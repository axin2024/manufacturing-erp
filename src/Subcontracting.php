<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Subcontracting extends Service
{
    public function create(string $key, string $number, int $workId, int $supplier, string $date, int $fee): array
    {
        $number = Value::code($number, '委外合同号');
        Value::day($date);
        Value::unitPrice($fee);
        return $this->mutate(fn(int $org): array => $this->replay('vendor.create', $key, [$number, $workId, $supplier, $date, $fee],
            function () use ($org, $number, $workId, $supplier, $date, $fee): array {
                $work = (new WorkOrderLedger($this->db, $this->scope))->order($workId);
                $this->party($supplier, 'supplier');
                Value::assert($work['kind'] === 'subcontract' && in_array($work['state'], ['draft', 'approved'], true)
                    && $date <= $work['starts_on'], '须在委外开工前确定加工合同。', 409);
                Value::assert($this->db->row('SELECT id FROM subcontract_orders WHERE org_id=? AND (number=? OR work_id=?)',
                    [$org, $number, $workId]) === null, '委外合同编号或工单已使用。', 409);
                $id = $this->db->insert('INSERT INTO subcontract_orders (org_id,work_id,number,supplier_id,contract_on,unit_fee_minor,created_by)
                    VALUES (?,?,?,?,?,?,?)', [$org, $workId, $number, $supplier, $date, $fee, $this->scope->user]);
                $this->audit('vendor.contract_created', $id, ['work_id' => $workId, 'supplier_id' => $supplier, 'unit_fee_minor' => $fee]);
                return ['subcontract_id' => $id, 'work_id' => $workId];
            }), 'buyer');
    }

    public function receive(string $key, int $contract, int $location, string $lot, int $quantity, string $date, array $used): array
    {
        $lot = Value::code($lot, '委外产出批号');
        Value::quantity($quantity);
        Value::day($date);
        $entries = $this->normalize($used);
        return $this->mutate(fn(int $org): array => $this->replay('vendor.complete', $key,
            [$contract, $location, $lot, $quantity, $date, $entries],
            function () use ($org, $contract, $location, $lot, $quantity, $date, $entries): array {
                $vendor = (new SubcontractLedger($this->db, $this->scope))->order($contract);
                $ledger = new WorkOrderLedger($this->db, $this->scope);
                $work = $ledger->order((int) $vendor['work_id']);
                $id = (int) $work['id'];
                $before = (int) $work['produced_milli'];
                $after = $before + $quantity;
                Value::assert(in_array($work['state'], ['released', 'active'], true) && $date >= $work['starts_on']
                    && $after <= (int) $work['qty_milli'], '委外工单状态、日期或数量不允许完工收货。', 409);
                $previous = $this->db->row('SELECT MAX(reported_on) AS posted FROM work_outputs WHERE org_id=? AND work_id=?',
                    [$org, $id]);
                Value::assert($previous['posted'] === null || $date >= $previous['posted'], '收货日期不能早于已有委外产出。', 409);
                [$parts, $actual, $plan] = $this->consume($contract, $id, $before, $after, $date, $entries);
                $fee = (int) $vendor['unit_fee_minor'];
                $charge = Value::extendedPrice($after, $fee) - Value::extendedPrice($before, $fee);
                $standard = (int) $work['standard_unit_cost_minor'];
                $target = Value::extendedPrice($after, $standard) - Value::extendedPrice($before, $standard);
                $results = new WorkOutputLedger($this->db, $this->scope);
                $output = $results->record($work, $location, $lot, $quantity, $actual + $charge, $target, $date);
                $outputId = $output['output_id'];
                $feeId = $this->db->insert('INSERT INTO subcontract_fees (org_id,subcontract_id,output_id,expected_minor) VALUES (?,?,?,?)',
                    [$org, $contract, $outputId, $charge]);
                $costs = new WorkCostLedger($this->db, $this->scope);
                $costs->record($id, 'material', 'vendor_usage', $outputId, $actual, $plan, $date);
                $costs->record($id, 'subcontract', 'vendor_fee_accrued', $feeId, $charge, $charge, $date);
                foreach ($parts as [$dispatch, $sourceLot, $usedQuantity, $amount]) {
                    $this->db->run('INSERT INTO subcontract_output_usage (org_id,output_id,dispatch_id,qty_milli,amount_minor) VALUES (?,?,?,?,?)',
                        [$org, $outputId, $dispatch, $usedQuantity, $amount]);
                    $results->link($sourceLot, $output['lot_id'], 'subcontract', $dispatch, $usedQuantity);
                }
                $this->audit('vendor.output_received', $outputId, ['subcontract_id' => $contract, 'fee_id' => $feeId]);
                return $output + ['fee_id' => $feeId, 'expected_fee_minor' => $charge];
            }), 'warehouse', 'production');
    }

    public function matchFee(string $key, int $feeId, string $invoice, int $invoiced, string $date, string $reason): array
    {
        $invoice = Value::code($invoice, '供应商发票号');
        Value::assert($invoiced >= 0 && $invoiced <= 1_000_000_000_000_000, '加工费金额超出范围。');
        Value::day($date);
        $reason = Value::text($reason, 255, '费用差异处理依据');
        return $this->mutate(fn(int $org): array => $this->replay('vendor.match_fee', $key, [$feeId, $invoice, $invoiced, $date, $reason],
            function () use ($org, $feeId, $invoice, $invoiced, $date, $reason): array {
                $fee = $this->db->row('SELECT f.*,o.work_id,o.reported_on,v.supplier_id FROM subcontract_fees f JOIN work_outputs o ON o.id=f.output_id
                    JOIN subcontract_orders v ON v.id=f.subcontract_id
                    WHERE f.org_id=? AND f.id=?', [$org, $feeId]);
                Value::assert($fee !== null, '未找到加工费记录。', 404);
                Value::assert($fee['matched_on'] === null && $date >= $fee['reported_on'], '加工费已匹配或发票日期早于收货。', 409);
                $this->openPeriod($date);
                $this->db->run('UPDATE subcontract_fees SET invoiced_minor=?,vendor_invoice=?,matched_on=?,matched_by=? WHERE org_id=? AND id=?',
                    [$invoiced, $invoice, $date, $this->scope->user, $org, $feeId]);
                $difference = $invoiced - (int) $fee['expected_minor'];
                if ($difference !== 0) {
                    $costs = new WorkCostLedger($this->db, $this->scope);
                    $work = (int) $fee['work_id'];
                    $costs->record($work, 'subcontract', 'vendor_fee_difference', $feeId, $difference, 0, $date);
                    $costs->record($work, 'adjustment', 'vendor_fee_expensed', $feeId, -$difference, 0, $date);
                }
                $finance = new FinanceLedger($this->db, $this->scope);
                $lines = array_values(array_filter([
                    $finance->line('VENDOR_ACCRUAL', (int) $fee['expected_minor']),
                    $finance->line('VENDOR_VARIANCE', $difference),
                    $finance->line('AP', -$invoiced),
                ], static fn (array $row): bool => $row[2] !== 0));
                $journal = $lines === [] ? null : $finance->post('vendor_invoice', $feeId, $date, '加工费发票 ' . $invoice, $lines);
                $payable = $finance->openItem('ap', (int) $fee['supplier_id'], $invoice, 'vendor_invoice', $feeId, $invoiced, $date, $date);
                $result = ['fee_id' => $feeId, 'invoiced_minor' => $invoiced, 'variance_minor' => $difference,
                    'journal_id' => $journal, 'payable_id' => $payable];
                $this->audit('vendor.fee_matched', $feeId, $result + ['vendor_invoice' => $invoice, 'reason' => $reason]);
                return $result;
            }), 'finance');
    }

    public function orders(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('buyer', 'production', 'warehouse', 'planner', 'finance');
        return $this->db->rows('SELECT v.id,v.number,v.contract_on,p.name AS supplier,w.id AS work_id,w.number AS work_number,w.state
            FROM subcontract_orders v JOIN parties p ON p.id=v.supplier_id JOIN work_orders w ON w.id=v.work_id
            WHERE v.org_id=? AND v.id<? ORDER BY v.id DESC LIMIT 51', [$this->scope->org, $before]);
    }

    public function details(int $id): array
    {
        $this->requireRole('buyer', 'production', 'warehouse', 'planner', 'finance');
        $ledger = new SubcontractLedger($this->db, $this->scope);
        $record = $ledger->order($id);
        $record['materials'] = $ledger->balances($id);
        $record['fees'] = $ledger->fees($id);
        $record['recoveries'] = $this->db->rows('SELECT r.* FROM subcontract_recoveries r JOIN subcontract_materials m
            ON m.org_id=r.org_id AND m.id=r.dispatch_id WHERE r.org_id=? AND m.subcontract_id=? ORDER BY r.id', [$this->scope->org, $id]);
        return $record;
    }

    private function consume(int $contract, int $work, int $before, int $after, string $date, array $entries): array
    {
        $org = $this->scope->org;
        $vendor = new SubcontractLedger($this->db, $this->scope);
        $ledger = new WorkOrderLedger($this->db, $this->scope);
        $used = $parts = [];
        $actual = $standard = 0;
        foreach ($entries as $id => $qty) {
            $line = $vendor->material($id);
            $prior = (int) $line['used_milli'];
            Value::assert((int) $line['subcontract_id'] === $contract && $qty <= (int) $line['outside_milli']
                && $date >= $line['last_posted_on'], '委外来料不是本合同可用留存量或日期早于发料。', 409);
            $r = $ledger->material((int) $line['material_id']);
            $sent = (int) $line['sent_milli'];
            $basis = $line['basis'] === null ? Value::extendedPrice($sent, (int) $line['unit_cost_minor']) : (int) $line['basis'];
            $std = (int) $r['standard_unit_cost_minor'];
            $amount = Value::allocation($basis, $prior + $qty, $sent) - Value::allocation($basis, $prior, $sent);
            $actual += $amount;
            $standard += Value::extendedPrice($prior + $qty, $std) - Value::extendedPrice($prior, $std);
            $used[$r['id']] = ($used[$r['id']] ?? 0) + $qty;
            $parts[] = [$id, (int) $line['lot_id'], $qty, $amount];
            $this->db->run('UPDATE subcontract_materials SET used_milli=used_milli+?,last_posted_on=? WHERE org_id=? AND id=?',
                [$qty, $date, $org, $id]);
        }
        $ledger->checkConsumption($work, $before, $after, $used);
        return [$parts, $actual, $standard];
    }

    private function normalize(array $used): array
    {
        Value::assert(array_is_list($used) && count($used) <= 300, '委外产出投入批次须为列表且不能超过 300 条。');
        $result = [];
        foreach ($used as $entry) {
            $id = $entry['dispatch_id'] ?? null;
            $qty = $entry['quantity'] ?? null;
            Value::assert(is_int($id) && is_int($qty) && !isset($result[$id]), '委外使用量须提供唯一的发料行和使用数量。');
            $result[$id] = Value::quantity($qty);
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }
}
