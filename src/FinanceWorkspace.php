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
            'intercompany' => $this->intercompany($before, WebInput::optionalId($parameters, 'target')),
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
        $forms = $state === 'open' && $snapshot['blockers'] === [] ? [
            $this->form('关闭会计期间', 'close-period', [], ['period' => $period],
                '关账后凭证及子账余额将写入存档。该期间及更早日期的账务更正须在之后的开放期间入账。'),
        ] : [];
        return ['title' => substr($period, 0, 7) . ' 月结检查', 'parent' => ['finance', '财务与月结'],
            'forms' => $forms,
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
            ], 'forms' => $direction === 'ap' ? $this->invoiceForms() : [], 'tables' => [
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
            'vendor_invoice' => $this->vendorLink((int) $item['source_id']),
            default => null,
        };
        $forms = (int) $item['open_minor'] > 0 ? [$this->requestForm('登记' . WebView::state($direction) . '核销', 'settle-open-item', [
            $this->field('reference', '核销单号', 'text', ['maxlength' => 40]),
            $this->dateField('posted_on', '核销日期'),
            $this->field('amount', '核销金额', 'number', ['min' => '0.01', 'step' => '0.01', 'max' => WebView::scaled($item['open_minor'], 2)]),
        ], ['id' => $id])] : [];
        return ['title' => $item['document_ref'], 'parent' => [$page, $title], 'links' => $source === null ? [] : [$source],
            'forms' => $forms,
            'details' => ['往来单位' => $item['party'], '入账日' => $item['posted_on'], '到期日' => $item['due_on'],
                '币种' => $this->scope->currency, '单据金额' => WebView::scaled($item['amount_minor'], 2),
                '待核销' => WebView::scaled($item['open_minor'], 2)],
            'tables' => [$this->table('核销明细', ['reference' => '核销单号', 'amount_minor' => '核销金额',
                'posted_on' => '核销日', 'actor_id' => '经手人编号'], $item['settlements'])]];
    }

    private function vendorLink(int $fee): ?array
    {
        $row = $this->db->row('SELECT subcontract_id FROM subcontract_fees WHERE org_id=? AND id=?', [$this->scope->org, $fee]);
        return $row === null ? null : $this->link('关联委外加工单', 'vendors', (int) $row['subcontract_id']);
    }

    private function invoiceForms(): array
    {
        $unmatched = $this->db->rows('SELECT l.id,l.amount_minor,r.number,s.lot_code,p.code AS supplier,p.name AS supplier_name FROM receipt_lines l
            JOIN receipts r ON r.org_id=l.org_id AND r.id=l.receipt_id
            JOIN purchase_orders o ON o.org_id=r.org_id AND o.id=r.order_id JOIN parties p ON p.org_id=o.org_id AND p.id=o.supplier_id
            JOIN stock_lots s ON s.org_id=l.org_id AND s.id=l.lot_id
            LEFT JOIN purchase_bill_matches m ON m.org_id=l.org_id AND m.receipt_line_id=l.id
            WHERE l.org_id=? AND m.receipt_line_id IS NULL ORDER BY r.received_on DESC,l.id DESC', [$this->scope->org]);
        if ($unmatched === []) {
            return [];
        }
        $options = [];
        foreach ($unmatched as $row) {
            $options[$row['id']] = $row['supplier'] . ' ' . $row['supplier_name'] . ' · ' . $row['number'] . ' / ' . $row['lot_code']
                . ' · ' . WebView::scaled($row['amount_minor'], 2);
        }
        return [$this->requestForm('匹配采购发票', 'post-purchase-bill', [
            $this->field('supplier', '供应商', 'select', ['options' => $this->parties('supplier')]),
            $this->field('number', '供应商发票号', 'text', ['maxlength' => 40]),
            $this->dateField('posted_on', '发票日期'), $this->dateField('due_on', '应付到期日'),
            $this->field('lines', '发票匹配行', 'rows', ['fields' => [
                $this->field('receipt_line_id', '收货批次与收货估价', 'select', ['options' => $options]),
                $this->field('amount', '发票金额', 'number', ['min' => '0', 'step' => '0.01']),
            ]]),
        ], [], '逐行填写发票金额。采购价差将随发票确认。')];
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
                'forms' => [$this->adjustmentForm()],
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
            'forms' => [$this->adjustmentForm($id)],
            'details' => ['入账日期' => $journal['posted_on'], '摘要' => $journal['memo'], '经手人' => $journal['actor'],
                '来源' => WebView::state($journal['source_kind']), '来源编号' => $journal['source_id'], '调整原因' => $journal['reason']],
            'tables' => [$this->table('借贷分录', ['line_no' => '行次', 'account_code' => '会计科目', 'currency' => '币种',
                'debit_minor' => '借方', 'credit_minor' => '贷方'], $journal['lines'])]];
    }

    private function adjustmentForm(?int $id = null): array
    {
        $options = array_diff_key(FinanceLedger::ACCOUNTS, array_fill_keys(GeneralLedger::CONTROLLED_ACCOUNTS, true));
        return $this->requestForm('登记期间调整', 'post-adjustment', [
            $this->field('number', '调整凭证号', 'text', ['maxlength' => 40]),
            $this->dateField('posted_on', '过账日期'), $this->field('reason', '调整说明'),
            $this->field('lines', '调整分录', 'rows', ['fields' => [
                $this->field('account', '普通科目', 'select', ['options' => $options]),
                $this->field('currency', '币种', 'text', ['maxlength' => 3, 'value' => $this->scope->currency]),
                $this->field('amount', '带符号金额', 'number', ['step' => '0.01']),
            ]]),
        ], $id === null ? [] : ['corrects_journal_id' => $id], '至少添加两条分录。借方填正数，贷方填负数，每个币种分别平衡。');
    }

    private function trial(string $cutoff): array
    {
        $rows = (new GeneralLedger($this->db, $this->scope))->trial($cutoff);
        return ['title' => '试算平衡', 'parent' => ['finance', '财务与月结'],
            'filters' => [['name' => 'cutoff', 'label' => '截至日期', 'type' => 'date', 'value' => $cutoff]],
            'tables' => [$this->table('截至 ' . $cutoff . ' 的科目余额', self::TRIAL_COLUMNS, $rows)]];
    }

    private function intercompany(int $before, ?int $target): array
    {
        $list = $this->paged((new Intercompany($this->db, $this->scope))->transfers($before), 'id', 'intercompany');
        $rows = $list['rows'];
        foreach ($rows as &$row) {
            $side = (int) $row['source_org_id'] === $this->scope->org ? 'source_lot_id' : 'destination_lot_id';
            $row['_links'][$side] = WebView::url('inventory', (int) $row[$side]);
        }
        unset($row);
        return ['title' => '跨法人调拨', 'parent' => ['finance', '财务与月结'],
            ...$this->transferForms($target), 'tables' => [
            $this->table('法人物流及对应金额', ['number' => '调拨单号', 'posted_on' => '入账日', 'source_org' => '调出法人',
                'destination_org' => '调入法人', 'source_lot_id' => '调出批次', 'destination_lot_id' => '调入批次',
                'qty_milli' => '数量', 'currency' => '币种', 'amount_minor' => '转移成本'], $rows, null, null, $list['next']),
        ]];
    }

    private function transferForms(?int $target): array
    {
        $eligible = $this->db->rows("SELECT DISTINCT o.id,o.code,o.name FROM memberships m JOIN organizations o ON o.id=m.org_id
            WHERE m.user_id=? AND m.enabled=1 AND m.role IN ('finance','admin') AND o.tenant_id=? AND o.currency=? AND o.id<>?
            ORDER BY o.code", [$this->scope->user, $this->scope->tenant, $this->scope->currency, $this->scope->org]);
        $targetOptions = $this->options($eligible, 'code', 'name');
        if ($target === null) {
            $links = [];
            foreach ($targetOptions as $id => $label) {
                $links[] = $this->link('调拨至 ' . $label, 'intercompany', null, ['target' => $id]);
            }
            return ['links' => $links];
        }
        Value::assert(isset($targetOptions[$target]), '没有此调入法人的财务操作权限。', 403);
        $targetScope = (new Identity($this->db))->scope($this->scope->user, $target);
        $destinationLocations = (new MasterData($this->db, $targetScope))->locations();
        return ['forms' => [$this->requestForm('调拨至 ' . $targetOptions[$target], 'transfer-between-organizations', [
            $this->field('number', '集团调拨号', 'text', ['maxlength' => 40]),
            $this->field('lot', '调出批次记录编号', 'number', ['min' => 1]),
            $this->field('from', '调出库位', 'select', ['options' => $this->locations()]),
            $this->field('to', '调入库位', 'select', ['options' => $this->options($destinationLocations, 'code', 'name')]),
            $this->field('destination_code', '调入批号', 'text', ['maxlength' => 40]),
            $this->quantityField('quantity', '调拨数量'), $this->dateField('posted_on', '过账日期'),
        ], ['destination' => $target], '从批次库存中查找调出批次记录。目标法人须已启用该物料。')]];
    }
}
