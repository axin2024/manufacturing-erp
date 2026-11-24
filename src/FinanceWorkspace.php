<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class FinanceWorkspace extends WorkspaceScreen
{
    private const TRIAL_COLUMNS = ['account_code' => '会计科目', 'currency' => '币种', 'debit_minor' => '累计借方',
        'credit_minor' => '累计贷方', 'net_minor' => '借贷净额'];

    public function screen(string $page, ?int $id, int $before, array $parameters): array
    {
        $this->requireRole('finance');
        $period = WebInput::stringParameter($parameters, 'period');
        return match ($page) {
            'finance' => $this->periods($period),
            'receivables', 'payables' => $this->balances($page, $id, $before, WebInput::stringParameter($parameters, 'open') === '1'),
            'bill' => $this->bill($id),
            'journals' => $this->journals($id, $before, $period),
            'trial' => $this->trial(WebInput::stringParameter($parameters, 'cutoff', gmdate('Y-m-d'))),
            'intercompany' => $this->intercompany($before),
        };
    }

    private function periods(string $period): array
    {
        $close = new FiscalClose($this->db, $this->scope);
        if ($period === '') {
            $periods = $close->periods();
            foreach ($periods as &$row) {
                $row['_links']['period_start'] = WebView::url('finance', null, ['period' => $row['period_start']]);
            }
            unset($row);
            return ['title' => '财务与月结', 'links' => [
                $this->link('应收明细', 'receivables'), $this->link('应付明细', 'payables'),
                $this->link('会计凭证', 'journals'), $this->link('试算平衡', 'trial'), $this->link('跨法人调拨', 'intercompany'),
            ], 'tables' => [
                $this->table('会计期间', ['period_start' => '期间', 'state' => '状态',
                    'closed_at' => '结账时间（UTC）', 'digest' => '关期快照摘要'], $periods),
            ]];
        }

        $record = $close->review($period);
        $snapshot = $record['snapshot'] ?? $record;
        $state = $record['state'] ?? 'open';
        return ['title' => substr($period, 0, 7) . ' 月结检查', 'parent' => ['finance', '财务与月结'],
            'links' => [$this->link('期间凭证', 'journals', null, ['period' => $period]),
                $this->link('期末试算', 'trial', null, ['cutoff' => $snapshot['cutoff_on']])],
            'details' => ['状态' => WebView::state($state), '截止日期' => $snapshot['cutoff_on'],
                '快照摘要' => $record['digest'] ?? null],
            'tables' => [
                $this->table('关期待处理事项', ['kind' => '原因', 'period_start' => '期间', 'journal_id' => '凭证号',
                    'direction' => '业务方向', 'number' => '调拨单号', 'source_matched' => '调出匹配',
                    'destination_matched' => '调入匹配', 'book_minor' => '总账金额', 'subledger_minor' => '子账金额'],
                    $this->scopeLinks($snapshot['blockers'], 'journal_id', 'journals')),
                $this->table('期末应收应付', ['direction' => '业务方向', 'balance_minor' => '余额'], $snapshot['open_items']),
                $this->table('工单成本累计', ['element' => '成本要素', 'actual_minor' => '实际金额', 'standard_minor' => '标准金额'], $snapshot['costs']),
                $this->table('期末试算表', self::TRIAL_COLUMNS, $snapshot['trial']),
            ]];
    }

    private function balances(string $page, ?int $id, int $before, bool $openOnly): array
    {
        $direction = $page === 'receivables' ? 'ar' : 'ap';
        $title = WebView::state($direction) . '明细';
        $service = new ReceivablesPayables($this->db, $this->scope);
        if ($id === null) {
            return ['title' => $title, 'parent' => ['finance', '财务与月结'], 'links' => [
                $this->link($openOnly ? '查看全部' : '仅看未核销', $page, null, ['open' => $openOnly ? null : '1']),
                $this->export('下载明细 CSV', $page),
            ], 'tables' => [
                $this->listTable($title, ['document_ref' => '业务单号', 'party' => '往来单位', 'posted_on' => '入账日',
                    'due_on' => '到期日', 'amount_minor' => '单据金额', 'open_minor' => '待核销'],
                    $service->items($direction, $before, $openOnly), $page, 'document_ref', ['open' => $openOnly ? '1' : null]),
            ]];
        }
        $item = $service->item($id);
        Value::assert($item['direction'] === $direction, '应收应付单据不存在。', 404);
        $source = match ($item['source_kind']) {
            'shipment' => $this->link('关联交货单', 'shipments', (int) $item['source_id']),
            'purchase_bill' => $this->link('关联采购发票', 'bill', (int) $item['source_id']),
            default => null,
        };
        return ['title' => $item['document_ref'], 'parent' => [$page, $title], 'links' => $source === null ? [] : [$source],
            'details' => ['往来单位' => $item['party'], '入账日' => $item['posted_on'], '到期日' => $item['due_on'],
                '币种' => $this->scope->currency, '单据金额' => WebView::scaled($item['amount_minor'], 2),
                '待核销' => WebView::scaled($item['open_minor'], 2)],
            'tables' => [$this->table('核销明细', ['reference' => '核销单号', 'amount_minor' => '核销金额',
                'posted_on' => '核销日', 'actor_id' => '经手人编号'], $item['settlements'])]];
    }

    private function bill(?int $id): array
    {
        Value::assert($id !== null, '缺少采购发票编号。');
        $bill = (new ReceivablesPayables($this->db, $this->scope))->purchaseBill($id);
        return ['title' => $bill['number'], 'parent' => ['payables', '应付明细'],
            'details' => ['供应商' => $bill['supplier'], '发票日期' => $bill['posted_on'], '到期日' => $bill['due_on']],
            'tables' => [$this->table('发票与收货匹配', ['receipt_id' => '收货记录', 'receipt_number' => '收货单',
                'receipt_line_id' => '收货行', 'lot_code' => '批号', 'expected_minor' => '收货估价', 'invoiced_minor' => '发票金额'],
                $this->scopeLinks($bill['matches'], 'receipt_id', 'inbound'))]];
    }

    private function journals(?int $id, int $before, string $period): array
    {
        $ledger = new GeneralLedger($this->db, $this->scope);
        if ($id === null) {
            $selected = $period === '' ? null : Value::day($period);
            return ['title' => $selected === null ? '会计凭证' : '会计凭证 · ' . substr($selected, 0, 7),
                'parent' => ['finance', '财务与月结'], 'links' => [$this->export('下载凭证 CSV', 'journals', null, $selected)],
                'tables' => [$this->listTable('凭证列表', ['id' => '凭证号', 'period_start' => '期间', 'posted_on' => '入账日',
                    'memo' => '摘要', 'source_kind' => '来源', 'source_id' => '来源编号', 'actor' => '经手人'],
                    $ledger->journals($before, $selected), 'journals', 'id', ['period' => $selected])]];
        }
        $journal = $ledger->journal($id);
        $links = [$this->link('所属会计期间', 'finance', null, ['period' => $journal['period_start']])];
        if ($journal['corrects_journal_id'] !== null) {
            $links[] = $this->link('受调整凭证', 'journals', (int) $journal['corrects_journal_id']);
        }
        return ['title' => '会计凭证 #' . $id, 'parent' => ['journals', '会计凭证'], 'links' => $links,
            'details' => ['入账日期' => $journal['posted_on'], '摘要' => $journal['memo'], '经手人' => $journal['actor'],
                '来源' => WebView::state($journal['source_kind']), '来源编号' => $journal['source_id'], '调整原因' => $journal['reason']],
            'tables' => [$this->table('借贷分录', ['line_no' => '行次', 'account_code' => '会计科目', 'currency' => '币种',
                'debit_minor' => '借方', 'credit_minor' => '贷方'], $journal['lines'])]];
    }

    private function trial(string $cutoff): array
    {
        $rows = (new GeneralLedger($this->db, $this->scope))->trial($cutoff);
        return ['title' => '试算平衡', 'parent' => ['finance', '财务与月结'],
            'filters' => [['name' => 'cutoff', 'label' => '截至日期', 'type' => 'date', 'value' => $cutoff]],
            'tables' => [$this->table('截至 ' . $cutoff . ' 的科目余额', self::TRIAL_COLUMNS, $rows)]];
    }

    private function intercompany(int $before): array
    {
        $list = $this->paged((new Intercompany($this->db, $this->scope))->transfers($before), 'id', 'intercompany');
        $rows = $list['rows'];
        foreach ($rows as &$row) {
            $side = (int) $row['source_org_id'] === $this->scope->org ? 'source_lot_id' : 'destination_lot_id';
            $row['_links'][$side] = WebView::url('inventory', (int) $row[$side]);
        }
        unset($row);
        return ['title' => '跨法人调拨', 'parent' => ['finance', '财务与月结'], 'tables' => [
            $this->table('法人物流及对应金额', ['number' => '调拨单号', 'posted_on' => '入账日', 'source_org' => '调出法人',
                'destination_org' => '调入法人', 'source_lot_id' => '调出批次', 'destination_lot_id' => '调入批次',
                'qty_milli' => '数量', 'currency' => '币种', 'amount_minor' => '转移成本'], $rows, null, null, $list['next']),
        ]];
    }
}
