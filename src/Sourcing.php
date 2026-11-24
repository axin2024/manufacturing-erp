<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Sourcing extends Service
{
    public function request(string $key, string $number, int $item, int $quantity, string $neededOn, ?int $proposalId = null): array
    {
        $number = Value::code($number, '询价单号');
        Value::quantity($quantity);
        Value::day($neededOn);
        return $this->mutate(fn(int $org): array => $this->replay('purchase.rfq', $key, [$number, $item, $quantity, $neededOn, $proposalId],
            function () use ($org, $number, $item, $quantity, $neededOn, $proposalId): array {
                $this->organizationItem($item);
                if ($proposalId !== null) {
                    $proposal = (new PlanningCommitments($this->db, $this->scope))->proposal($proposalId, 'purchase');
                    Value::assert((int) $proposal['item_id'] === $item && (int) $proposal['qty_milli'] === $quantity,
                        '询价的物料与数量必须和采购建议一致。');
                }
                Value::assert($this->db->row('SELECT id FROM quote_requests WHERE org_id=? AND number=?', [$org, $number]) === null,
                    '询价单号已经存在。', 409);
                $id = $this->db->insert('INSERT INTO quote_requests (org_id,number,item_id,qty_milli,needed_on,proposal_id,created_by)
                    VALUES (?,?,?,?,?,?,?)', [$org, $number, $item, $quantity, $neededOn, $proposalId, $this->scope->user]);
                $this->audit('purchase.rfq_created', $id, ['number' => $number, 'proposal_id' => $proposalId]);
                return ['request_id' => $id];
            }), 'buyer');
    }

    public function quote(string $key, int $request, int $supplier, string $number, int $price, string $availableOn, string $validUntil): array
    {
        $number = Value::code($number, '供应商报价号');
        Value::unitPrice($price);
        Value::day($availableOn);
        Value::day($validUntil);
        return $this->mutate(fn(int $org): array => $this->replay('purchase.quote', $key,
            [$request, $supplier, $number, $price, $availableOn, $validUntil],
            function () use ($org, $request, $supplier, $number, $price, $availableOn, $validUntil): array {
                $record = $this->requestRecord($request);
                Value::assert($record['winning_quote_id'] === null, '该询价单已定标。', 409);
                $this->party($supplier, 'supplier');
                Value::assert($this->db->row('SELECT id FROM supplier_quotes WHERE org_id=? AND request_id=? AND supplier_id=? AND number=?',
                    [$org, $request, $supplier, $number]) === null, '该供应商报价号已经登记。', 409);
                $id = $this->db->insert('INSERT INTO supplier_quotes
                    (org_id,request_id,supplier_id,number,unit_price_minor,available_on,valid_until,created_by) VALUES (?,?,?,?,?,?,?,?)',
                    [$org, $request, $supplier, $number, $price, $availableOn, $validUntil, $this->scope->user]);
                $this->audit('purchase.quote_recorded', $id, ['request_id' => $request, 'supplier_id' => $supplier]);
                return ['quote_id' => $id];
            }), 'buyer');
    }

    public function award(int $request, int $quote, string $decisionOn): array
    {
        Value::day($decisionOn);
        return $this->mutate(function (int $org) use ($request, $quote, $decisionOn): array {
            $record = $this->requestRecord($request);
            if ($record['winning_quote_id'] !== null) {
                Value::assert((int) $record['winning_quote_id'] === $quote, '该询价单已选择另一份报价。', 409);
                return ['request_id' => $request, 'quote_id' => $quote];
            }
            $bid = $this->db->row('SELECT valid_until FROM supplier_quotes WHERE org_id=? AND request_id=? AND id=?',
                [$org, $request, $quote]);
            Value::assert($bid !== null, '此报价不在询价单下。', 404);
            Value::assert($bid['valid_until'] >= $decisionOn, '报价在定标日期已经失效。', 409);
            $this->db->run('UPDATE quote_requests SET winning_quote_id=? WHERE org_id=? AND id=?', [$quote, $org, $request]);
            $this->audit('purchase.rfq_awarded', $request, ['quote_id' => $quote, 'decision_on' => $decisionOn]);
            return ['request_id' => $request, 'quote_id' => $quote];
        }, 'buyer');
    }

    public function requests(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('buyer', 'planner');
        return $this->db->rows('SELECT q.*,i.code,i.name FROM quote_requests q JOIN items i ON i.id=q.item_id
            WHERE q.org_id=? AND q.id<? ORDER BY q.id DESC LIMIT 51', [$this->scope->org, $before]);
    }

    public function details(int $request): array
    {
        $this->requireRole('buyer', 'planner');
        $record = $this->requestRecord($request);
        $record['quotes'] = $this->db->rows('SELECT q.*,p.code AS supplier_code,p.name AS supplier_name
            FROM supplier_quotes q JOIN parties p ON p.id=q.supplier_id WHERE q.org_id=? AND q.request_id=? ORDER BY q.id',
            [$this->scope->org, $request]);
        return $record;
    }

    private function requestRecord(int $id): array
    {
        $record = $this->db->row('SELECT * FROM quote_requests WHERE org_id=? AND id=?', [$this->scope->org, $id]);
        Value::assert($record !== null, '询价单不存在。', 404);
        return $record;
    }
}
