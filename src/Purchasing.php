<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Purchasing extends Service
{
    public function create(string $key, string $number, int $supplier, string $orderedOn, array $lines): array
    {
        $number = Value::code($number, '采购单号');
        Value::day($orderedOn);
        $lines = $this->normalizeOrderLines($lines, $orderedOn);
        return $this->mutate(fn(int $org): array => $this->replay('purchase.create', $key, [$number, $supplier, $orderedOn, $lines],
            function () use ($org, $number, $supplier, $orderedOn, $lines): array {
                $this->party($supplier, 'supplier');
                Value::assert($this->db->row('SELECT id FROM purchase_orders WHERE org_id=? AND number=?', [$org, $number]) === null,
                    '采购单号已经存在。', 409);
                $order = $this->db->insert('INSERT INTO purchase_orders (org_id,number,supplier_id,ordered_on,created_by) VALUES (?,?,?,?,?)',
                    [$org, $number, $supplier, $orderedOn, $this->scope->user]);
                $ids = [];
                foreach ($lines as $index => [$item, $uom, $sourceQuantity, $price, $due, $proposalId, $quoteId]) {
                    $conversion = $this->conversion($item, $uom, $orderedOn);
                    $quantity = Value::convert($sourceQuantity, (int) $conversion['factor_milli']);
                    $line = [
                        'item_id' => $item, 'qty_milli' => $quantity, 'unit_price_minor' => $price, 'due_on' => $due,
                        'quote_id' => $quoteId, 'proposal_id' => $proposalId,
                    ];
                    if ($quoteId !== null) {
                        $this->validateQuote($line, $supplier, $orderedOn);
                    }
                    if ($proposalId !== null) {
                        $this->validateProposal($line);
                    }
                    $ids[] = $this->db->insert('INSERT INTO purchase_lines
                        (org_id,order_id,line_no,item_id,quote_id,proposal_id,source_uom,source_qty_milli,conversion_id,
                        factor_milli,qty_milli,unit_price_minor,due_on) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$org, $order, $index + 1, $item, $quoteId, $proposalId, $uom, $sourceQuantity, $conversion['id'],
                            $conversion['factor_milli'], $quantity, $price, $due]);
                }
                $this->audit('purchase.created', $order, ['number' => $number, 'line_ids' => $ids]);
                return ['order_id' => $order, 'line_ids' => $ids];
            }), 'buyer');
    }

    public function issue(int $order): array
    {
        return $this->mutate(function (int $org) use ($order): array {
            $record = $this->orderRecord($order);
            if ($record['state'] === 'issued') {
                return ['order_id' => $order, 'state' => 'issued'];
            }
            Value::assert($record['state'] === 'draft', '此采购单已结束，无法再次下单。', 409);
            $links = new PlanningCommitments($this->db, $this->scope);
            $this->db->run("UPDATE purchase_orders SET state='issued',issued_by=?,issued_at=UTC_TIMESTAMP(6) WHERE org_id=? AND id=?",
                [$this->scope->user, $org, $order]);
            foreach ($this->lines($order) as $line) {
                if ($line['quote_id'] !== null) {
                    $this->validateQuote($line, (int) $record['supplier_id'], $record['ordered_on']);
                }
                if ($line['proposal_id'] !== null) {
                    $links->materialize($this->validateProposal($line), 'purchase', (int) $line['id']);
                }
                $links->supply('purchase', (int) $line['id'], (int) $line['item_id'], $line['due_on'], (int) $line['qty_milli']);
            }
            $this->audit('purchase.issued', $order, []);
            return ['order_id' => $order, 'state' => 'issued'];
        }, 'buyer');
    }

    public function receive(string $key, int $order, string $number, string $receivedOn, array $entries): array
    {
        $number = Value::code($number, '收货单号');
        Value::day($receivedOn);
        $entries = $this->normalizeReceiptEntries($entries);
        return $this->mutate(fn(int $org): array => $this->replay('purchase.receive', $key, [$order, $number, $receivedOn, $entries],
            function () use ($org, $order, $number, $receivedOn, $entries): array {
                $record = $this->orderRecord($order);
                Value::assert($record['state'] === 'issued', '只有待收货的已下单采购单可接收新批次。', 409);
                Value::assert($receivedOn >= $record['ordered_on'], '收货日期不能早于采购日期。');
                Value::assert($this->db->row('SELECT id FROM receipts WHERE org_id=? AND number=?', [$org, $number]) === null,
                    '收货单号已经存在，请按原请求标识查询回执。', 409);
                $receipt = $this->db->insert('INSERT INTO receipts (org_id,order_id,number,received_on,actor_id) VALUES (?,?,?,?,?)',
                    [$org, $order, $number, $receivedOn, $this->scope->user]);
                $received = [];
                foreach ($entries as $entry) {
                    $received[] = $this->receiveLine($order, $receipt, $receivedOn, $entry);
                }
                $outstanding = $this->db->row('SELECT id FROM purchase_lines WHERE org_id=? AND order_id=?
                    AND received_milli+cancelled_milli<qty_milli LIMIT 1', [$org, $order]);
                if ($outstanding === null) {
                    $this->db->run("UPDATE purchase_orders SET state='received' WHERE org_id=? AND id=?", [$org, $order]);
                }
                $this->audit('purchase.received', $receipt, ['order_id' => $order, 'received_on' => $receivedOn, 'lines' => $received]);
                return ['receipt_id' => $receipt, 'lines' => $received, 'order_state' => $outstanding === null ? 'received' : 'issued'];
            }), 'warehouse');
    }

    public function stop(int $order, string $reason): array
    {
        $reason = Value::text($reason, 255, '结单原因');
        return $this->mutate(function (int $org) use ($order, $reason): array {
            $record = $this->orderRecord($order);
            $state = $record['state'];
            if (in_array($state, ['closed', 'void'], true)) {
                return ['order_id' => $order, 'state' => $state];
            }
            $links = new PlanningCommitments($this->db, $this->scope);
            if ($state !== 'draft') {
                foreach ($this->lines($order) as $line) {
                    $id = (int) $line['id'];
                    $received = (int) $line['received_milli'];
                    $cancelled = (int) $line['qty_milli'] - $received;
                    $this->db->run('UPDATE purchase_lines SET cancelled_milli=? WHERE org_id=? AND id=?', [$cancelled, $org, $id]);
                    $links->supply('purchase', $id, (int) $line['item_id'], $line['due_on'], 0);
                    $links->state('purchase', $id, $received > 0 ? 'completed' : 'released');
                }
            }
            $state = $state === 'draft' ? 'void' : 'closed';
            $this->db->run('UPDATE purchase_orders SET state=? WHERE org_id=? AND id=?', [$state, $org, $order]);
            $this->audit('purchase.stopped', $order, ['reason' => $reason, 'state' => $state]);
            return ['order_id' => $order, 'state' => $state];
        }, 'buyer');
    }

    public function order(int $order): array
    {
        $this->requireRole('buyer', 'planner', 'warehouse', 'finance');
        $record = $this->orderRecord($order);
        $record['lines'] = $this->lines($order);
        $record['receipts'] = $this->db->rows('SELECT id,number,received_on,actor_id FROM receipts
            WHERE org_id=? AND order_id=? ORDER BY id', [$this->scope->org, $order]);
        return $record;
    }

    public function orders(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('buyer', 'planner', 'warehouse', 'finance');
        return $this->db->rows('SELECT o.id,o.number,p.name AS supplier,o.ordered_on,o.state,o.issued_at FROM purchase_orders o
            JOIN parties p ON p.id=o.supplier_id WHERE o.org_id=? AND o.id<? ORDER BY o.id DESC LIMIT 51', [$this->scope->org, $before]);
    }

    public function receipt(int $id): array
    {
        $this->requireRole('buyer', 'warehouse', 'finance', 'quality');
        $org = $this->scope->org;
        $result = $this->db->row('SELECT r.*,p.number AS purchase_number FROM receipts r JOIN purchase_orders p ON p.id=r.order_id
            WHERE r.org_id=? AND r.id=?', [$org, $id]);
        Value::assert($result !== null, '收货单不存在。', 404);
        $result['lines'] = $this->db->rows('SELECT l.*,s.lot_code,i.code,c.id AS inspection_id,c.state AS inspection_state,
            c.accepted_milli,c.rejected_milli,c.qty_milli-c.accepted_milli-c.rejected_milli-c.rework_milli AS pending_milli
            FROM receipt_lines l JOIN stock_lots s ON s.id=l.lot_id JOIN items i ON i.id=s.item_id
            JOIN inspection_cases c ON c.lot_id=l.lot_id AND c.org_id=l.org_id
            WHERE l.org_id=? AND l.receipt_id=? ORDER BY l.id', [$org, $id]);
        return $result;
    }

    private function receiveLine(int $order, int $receipt, string $day, array $entry): array
    {
        [$lineId, $lotCode, $location, $inputQuantity] = $entry;
        $org = $this->scope->org;
        $line = $this->db->row('SELECT * FROM purchase_lines WHERE org_id=? AND order_id=? AND id=? FOR UPDATE',
            [$org, $order, $lineId]);
        Value::assert($line !== null, '收货行不属于此采购单。', 404);
        $quantity = Value::convert($inputQuantity, (int) $line['factor_milli']);
        $prior = (int) $line['received_milli'];
        $after = $prior + $quantity;
        $remaining = (int) $line['qty_milli'] - (int) $line['cancelled_milli'] - $after;
        Value::assert($remaining >= 0, '收货数量超过采购行未收数量。', 409);
        $item = (int) $line['item_id'];
        $unitPrice = (int) $line['unit_price_minor'];
        $amount = Value::extendedPrice($after, $unitPrice) - ($prior === 0 ? 0 : Value::extendedPrice($prior, $unitPrice));
        $lot = (new StockLedger($this->db, $this->scope))->createLot($item, $location, $lotCode, $line['source_uom'],
            $inputQuantity, $unitPrice, $day, 'pending', 'purchase_receipt', $receipt,
            ['id' => $line['conversion_id'], 'factor_milli' => $line['factor_milli']]);
        $id = $this->db->insert('INSERT INTO receipt_lines
            (org_id,receipt_id,purchase_line_id,lot_id,location_id,qty_milli,amount_minor) VALUES (?,?,?,?,?,?,?)',
            [$org, $receipt, $lineId, $lot['lot_id'], $location, $quantity, $amount]);
        $inspection = (new InspectionLedger($this->db, $this->scope))->register($lot['lot_id'], $location, 'purchase', $id, $quantity);
        $this->db->run('UPDATE purchase_lines SET received_milli=? WHERE org_id=? AND id=?', [$after, $org, $lineId]);
        $links = new PlanningCommitments($this->db, $this->scope);
        $links->supply('purchase', $lineId, $item, $line['due_on'], $remaining);
        $links->state('purchase', $lineId, $remaining === 0 ? 'completed' : 'started');
        return ['receipt_line_id' => $id, 'purchase_line_id' => $lineId, 'inspection_id' => $inspection, 'amount_minor' => $amount, ...$lot];
    }

    private function validateProposal(array $line): array
    {
        $proposal = (new PlanningCommitments($this->db, $this->scope))->proposal((int) $line['proposal_id'], 'purchase');
        Value::assert((int) $proposal['item_id'] === (int) $line['item_id'] && (int) $proposal['qty_milli'] === (int) $line['qty_milli'],
            '采购行的物料与数量必须和采购建议一致。');
        return $proposal;
    }

    private function validateQuote(array $line, int $supplier, string $day): void
    {
        $org = $this->scope->org;
        $quote = $this->db->row('SELECT q.*,r.item_id,r.qty_milli,r.proposal_id FROM supplier_quotes q JOIN quote_requests r
            ON r.org_id=q.org_id AND r.id=q.request_id AND r.winning_quote_id=q.id WHERE q.org_id=? AND q.id=? AND q.supplier_id=?',
            [$org, $line['quote_id'], $supplier]);
        Value::assert($quote !== null, '采购行须使用此供应商已定标的报价。', 409);
        Value::assert($quote['valid_until'] >= $day && $quote['available_on'] <= $line['due_on']
            && (int) $quote['item_id'] === (int) $line['item_id'] && (int) $quote['qty_milli'] === (int) $line['qty_milli']
            && (int) $quote['unit_price_minor'] === (int) $line['unit_price_minor'] && $quote['proposal_id'] === $line['proposal_id'],
            '采购行和已定标报价的物料、日期、数量、价格或计划来源不一致。', 409);
        $prior = $this->db->row("SELECT l.id FROM purchase_lines l JOIN purchase_orders o ON o.id=l.order_id
            WHERE l.org_id=? AND l.quote_id=? AND o.state IN ('issued','received','closed') AND l.id<>? LIMIT 1",
            [$org, $line['quote_id'], $line['id'] ?? 0]);
        Value::assert($prior === null, '这份报价已经用于正式采购行。', 409);
    }

    private function orderRecord(int $order): array
    {
        $row = $this->db->row('SELECT o.*,p.name AS supplier FROM purchase_orders o JOIN parties p ON p.id=o.supplier_id
            WHERE o.org_id=? AND o.id=?', [$this->scope->org, $order]);
        Value::assert($row !== null, '采购单不存在。', 404);
        return $row;
    }

    private function lines(int $order): array
    {
        return $this->db->rows('SELECT l.*,i.code,i.name,i.base_uom FROM purchase_lines l JOIN items i ON i.id=l.item_id
            WHERE l.org_id=? AND l.order_id=? ORDER BY l.line_no', [$this->scope->org, $order]);
    }

    private function normalizeOrderLines(array $lines, string $orderedOn): array
    {
        Value::assert(array_is_list($lines) && count($lines) >= 1 && count($lines) <= 100, '采购单须包含 1 至 100 行。');
        $normalized = [];
        foreach ($lines as $line) {
            $item = $line['item'] ?? null;
            $uom = $line['uom'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $price = $line['unit_price_minor'] ?? null;
            $due = $line['due_on'] ?? null;
            $proposal = $line['proposal_id'] ?? null;
            $quote = $line['quote_id'] ?? null;
            Value::assert(is_int($item) && is_string($uom) && is_int($quantity) && is_int($price) && is_string($due)
                && ($proposal === null || is_int($proposal)) && ($quote === null || is_int($quote)), '采购行数据格式无效。');
            Value::assert(Value::day($due) >= $orderedOn, '预计到货日期不能早于采购日期。');
            $normalized[] = [$item, Value::unit($uom), Value::quantity($quantity), Value::unitPrice($price), $due, $proposal, $quote];
        }
        return $normalized;
    }

    private function normalizeReceiptEntries(array $entries): array
    {
        Value::assert(array_is_list($entries) && count($entries) >= 1 && count($entries) <= 100, '收货单须包含 1 至 100 个批次。');
        $normalized = [];
        foreach ($entries as $entry) {
            $line = $entry['line_id'] ?? null;
            $lot = $entry['lot'] ?? null;
            $location = $entry['location'] ?? null;
            $quantity = $entry['quantity'] ?? null;
            Value::assert(is_int($line) && is_string($lot) && is_int($location) && is_int($quantity), '收货行数据格式无效。');
            $normalized[] = [$line, Value::code($lot, '批号'), $location, Value::quantity($quantity)];
        }
        return $normalized;
    }
}
