<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class ReceivablesPayables extends Service
{
    public function bill(string $key, int $supplier, string $number, string $date, string $due, array $matches): array
    {
        $number = Value::code($number, '采购发票号');
        Value::assert(Value::day($date) <= Value::day($due), '到期日期不能早于入账日期。');
        $matches = $this->normalizeMatches($matches);
        return $this->mutate(fn(int $org): array => $this->replay('finance.purchase_bill', $key, [$supplier, $number, $date, $due, $matches],
            function () use ($org, $supplier, $number, $date, $due, $matches): array {
                $this->party($supplier, 'supplier');
                $this->openPeriod($date);
                Value::assert($this->db->row('SELECT id FROM purchase_bills WHERE org_id=? AND supplier_id=? AND number=?',
                    [$org, $supplier, $number]) === null, '此供应商发票号已登记。', 409);
                $id = $this->db->insert('INSERT INTO purchase_bills (org_id,supplier_id,number,posted_on,due_on,actor_id) VALUES (?,?,?,?,?,?)',
                    [$org, $supplier, $number, $date, $due, $this->scope->user]);
                $expected = $invoiced = 0;
                $lines = [];
                foreach ($matches as $receipt => $amount) {
                    $entry = $this->db->row('SELECT a.amount_minor,r.received_on,o.supplier_id,m.bill_id FROM receipt_lines a
                        JOIN receipts r ON r.id=a.receipt_id JOIN purchase_orders o ON o.id=r.order_id
                        LEFT JOIN purchase_bill_matches m ON m.org_id=a.org_id AND m.receipt_line_id=a.id
                        WHERE a.org_id=? AND a.id=?', [$org, $receipt]);
                    Value::assert($entry !== null && (int) $entry['supplier_id'] === $supplier, '收货行不属于本供应商。', 404);
                    Value::assert($entry['bill_id'] === null && $date >= $entry['received_on'], '收货行已经匹配发票或入账日期早于实际收货。', 409);
                    $expected += (int) $entry['amount_minor'];
                    $invoiced += $amount;
                    $lines[] = [$org, $id, $receipt, $entry['amount_minor'], $amount];
                }
                Value::assert($expected <= FinanceLedger::MAX_AMOUNT && $invoiced <= FinanceLedger::MAX_AMOUNT, '发票合计金额超出范围。');
                $this->db->insertRows('INSERT INTO purchase_bill_matches (org_id,bill_id,receipt_line_id,expected_minor,invoiced_minor)', $lines);
                $finance = new FinanceLedger($this->db, $this->scope);
                $posting = array_values(array_filter([
                    $finance->line('GRNI', $expected),
                    $finance->line('PURCHASE_VARIANCE', $invoiced - $expected),
                    $finance->line('AP', -$invoiced),
                ], static fn (array $row): bool => $row[2] !== 0));
                $journal = $posting === [] ? null : $finance->post('purchase_bill', $id, $date, '采购发票 ' . $number, $posting);
                $payable = $finance->openItem('ap', $supplier, $number, 'purchase_bill', $id, $invoiced, $date, $due);
                $result = ['bill_id' => $id, 'payable_id' => $payable, 'journal_id' => $journal, 'amount_minor' => $invoiced,
                    'expected_minor' => $expected, 'variance_minor' => $invoiced - $expected];
                $this->audit('finance.purchase_bill_matched', $id, $result);
                return $result;
            }), 'finance');
    }

    public function settle(string $key, int $openItem, string $reference, int $amount, string $date): array
    {
        $reference = Value::code($reference, '收付款流水号');
        Value::assert($amount > 0 && $amount <= FinanceLedger::MAX_AMOUNT, '核销金额须为有效的正数。');
        Value::day($date);
        return $this->mutate(fn(int $org): array => $this->replay('finance.settle', $key, [$openItem, $reference, $amount, $date],
            function () use ($org, $openItem, $reference, $amount, $date): array {
                $item = $this->record($openItem);
                Value::assert($date >= $item['latest_settlement'] && $amount <= (int) $item['open_minor'], '核销日期或金额超出未核销余额范围。', 409);
                Value::assert($this->db->row('SELECT id FROM settlements WHERE org_id=? AND open_item_id=? AND reference=?',
                    [$org, $openItem, $reference]) === null, '收付款流水已登记。', 409);
                $id = $this->db->insert('INSERT INTO settlements (org_id,open_item_id,reference,amount_minor,posted_on,actor_id)
                    VALUES (?,?,?,?,?,?)', [$org, $openItem, $reference, $amount, $date, $this->scope->user]);
                $finance = new FinanceLedger($this->db, $this->scope);
                $journal = $item['direction'] === 'ar'
                    ? $finance->move('settlement', $id, $date, '应收核销 ' . $reference, 'BANK', 'AR', $amount)
                    : $finance->move('settlement', $id, $date, '应付核销 ' . $reference, 'AP', 'BANK', $amount);
                $left = (int) $item['open_minor'] - $amount;
                $this->db->run('UPDATE open_items SET open_minor=? WHERE org_id=? AND id=?', [$left, $org, $openItem]);
                $result = ['settlement_id' => $id, 'open_item_id' => $openItem, 'journal_id' => $journal, 'open_minor' => $left];
                $this->audit('finance.settled', $id, $result);
                return $result;
            }), 'finance');
    }

    public function items(string $direction, int $before = PHP_INT_MAX, bool $openOnly = false): array
    {
        $this->requireRole('finance');
        Value::assert(in_array($direction, ['ar', 'ap'], true), '往来类型无效。');
        return $this->db->rows('SELECT i.*,p.code AS party_code,p.name AS party FROM open_items i JOIN parties p ON p.id=i.party_id
            WHERE i.org_id=? AND i.direction=? AND i.id<? AND (?=0 OR i.open_minor>0) ORDER BY i.id DESC LIMIT 51',
            [$this->scope->org, $direction, $before, (int) $openOnly]);
    }

    public function item(int $id): array
    {
        $this->requireRole('finance');
        $item = $this->record($id);
        $item['settlements'] = $this->db->rows('SELECT id,reference,amount_minor,posted_on,actor_id FROM settlements
            WHERE org_id=? AND open_item_id=? ORDER BY posted_on,id', [$this->scope->org, $id]);
        return $item;
    }

    public function purchaseBill(int $id): array
    {
        $this->requireRole('finance', 'buyer');
        $org = $this->scope->org;
        $bill = $this->db->row('SELECT b.*,p.name AS supplier FROM purchase_bills b JOIN parties p ON p.id=b.supplier_id
            WHERE b.org_id=? AND b.id=?', [$org, $id]);
        Value::assert($bill !== null, '采购发票不存在。', 404);
        $bill['matches'] = $this->db->rows('SELECT m.*,l.lot_code,r.id AS receipt_id,r.number AS receipt_number
            FROM purchase_bill_matches m JOIN receipt_lines e ON e.id=m.receipt_line_id JOIN receipts r ON r.id=e.receipt_id
            JOIN stock_lots l ON l.id=e.lot_id WHERE m.org_id=? AND m.bill_id=? ORDER BY m.receipt_line_id', [$org, $id]);
        return $bill;
    }

    private function record(int $id): array
    {
        $row = $this->db->row('SELECT i.*,p.name AS party,GREATEST(i.posted_on,
            COALESCE((SELECT MAX(s.posted_on) FROM settlements s WHERE s.org_id=i.org_id AND s.open_item_id=i.id),i.posted_on)) AS latest_settlement
            FROM open_items i JOIN parties p ON p.id=i.party_id WHERE i.org_id=? AND i.id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '应收应付单据不存在。', 404);
        return $row;
    }

    private function normalizeMatches(array $matches): array
    {
        Value::assert(array_is_list($matches) && count($matches) >= 1 && count($matches) <= 100, '须提供 1 至 100 条收货匹配。');
        $entries = [];
        foreach ($matches as $entry) {
            $receipt = $entry['receipt_line_id'] ?? null;
            $amount = $entry['amount_minor'] ?? null;
            Value::assert(is_int($receipt) && is_int($amount) && !isset($entries[$receipt])
                && $amount >= 0 && $amount <= FinanceLedger::MAX_AMOUNT, '发票金额或匹配行无效。');
            $entries[$receipt] = $amount;
        }
        ksort($entries, SORT_NUMERIC);
        return $entries;
    }
}
