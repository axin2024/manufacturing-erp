<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class FinanceActions extends Service
{
    public function execute(string $action, array $input): array
    {
        $this->requireRole('finance');
        return match ($action) {
            'post-purchase-bill' => $this->invoice($input),
            'settle-open-item' => $this->settle($input),
            'post-adjustment' => $this->adjust($input),
            'close-period' => $this->close($input),
            'transfer-between-organizations' => $this->transfer($input),
        };
    }

    private function invoice(array $input): array
    {
        $rows = WebInput::lines($input, 'lines', static fn(array $row): array => [
            'receipt_line_id' => WebInput::id($row, 'receipt_line_id'),
            'amount_minor' => WebInput::decimal($row['amount'] ?? null, 2, FinanceLedger::MAX_AMOUNT),
        ]);
        $result = (new ReceivablesPayables($this->db, $this->scope))->bill(WebInput::stringParameter($input, 'request_key'),
            WebInput::id($input, 'supplier'), WebInput::stringParameter($input, 'number'),
            WebInput::stringParameter($input, 'posted_on'), WebInput::stringParameter($input, 'due_on'), $rows);
        return ['target' => WebView::url('bill', $result['bill_id']), 'message' => '采购发票已匹配并过账。', 'result' => $result, 'status' => 201];
    }

    private function settle(array $input): array
    {
        $id = WebInput::id($input, 'id');
        $finance = new ReceivablesPayables($this->db, $this->scope);
        $item = $finance->item($id);
        $result = $finance->settle(WebInput::stringParameter($input, 'request_key'), $id,
            WebInput::stringParameter($input, 'reference'), WebInput::decimal($input['amount'] ?? null, 2, FinanceLedger::MAX_AMOUNT),
            WebInput::stringParameter($input, 'posted_on'));
        return ['target' => WebView::url($item['direction'] === 'ar' ? 'receivables' : 'payables', $id),
            'message' => '核销已过账。', 'result' => $result, 'status' => 201];
    }

    private function adjust(array $input): array
    {
        $rows = WebInput::lines($input, 'lines', fn(array $row): array => [
            'account' => WebInput::stringParameter($row, 'account'),
            'currency' => WebInput::stringParameter($row, 'currency', $this->scope->currency),
            'signed_minor' => WebInput::decimal($row['amount'] ?? null, 2, FinanceLedger::MAX_AMOUNT, true),
        ], 300);
        $result = (new GeneralLedger($this->db, $this->scope))->adjustment(WebInput::stringParameter($input, 'request_key'),
            WebInput::stringParameter($input, 'number'), WebInput::stringParameter($input, 'posted_on'),
            WebInput::stringParameter($input, 'reason'), $rows, WebInput::optionalId($input, 'corrects_journal_id'));
        return ['target' => WebView::url('journals', $result['journal_id']), 'message' => '调整分录已过账。', 'result' => $result, 'status' => 201];
    }

    private function close(array $input): array
    {
        $period = WebInput::stringParameter($input, 'period');
        $result = (new FiscalClose($this->db, $this->scope))->close($period);
        $closed = $result['state'] === 'closed';
        return ['target' => WebView::url('finance', null, ['period' => $period]),
            'message' => $closed ? '期间已关闭，关期快照已存档。' : '期间仍开放，请处理页面列出的差异。',
            'result' => $result, 'status' => $closed ? 200 : 409];
    }

    private function transfer(array $input): array
    {
        $result = (new Intercompany($this->db, $this->scope))->transfer(WebInput::stringParameter($input, 'request_key'),
            WebInput::stringParameter($input, 'number'), WebInput::id($input, 'destination'), WebInput::id($input, 'lot'),
            WebInput::id($input, 'from'), WebInput::id($input, 'to'), WebInput::stringParameter($input, 'destination_code'),
            WebInput::quantity($input, 'quantity'), WebInput::stringParameter($input, 'posted_on'));
        return ['target' => WebView::url('intercompany'), 'message' => '两个法人账套已同时过账。', 'result' => $result, 'status' => 201];
    }
}
