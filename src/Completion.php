<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Completion extends Service
{
    public function report(string $key, int $workId, int $location, string $code, int $quantity, string $date, array $used): array
    {
        Value::quantity($quantity);
        Value::day($date);
        $code = Value::code($code, '完工批号');
        $used = $this->normalize($used);
        return $this->mutate(fn(int $org): array => $this->replay('work.complete', $key,
            [$workId, $location, $code, $quantity, $date, $used],
            function () use ($org, $workId, $location, $code, $quantity, $date, $used): array {
                $work = (new WorkOrderLedger($this->db, $this->scope))->order($workId);
                $before = (int) $work['produced_milli'];
                $total = (int) $work['qty_milli'];
                $after = $before + $quantity;
                Value::assert(in_array($work['state'], ['released', 'active'], true) && $work['kind'] !== 'subcontract'
                    && $after <= $total && $date >= $work['starts_on'], '工单状态、完工日期或数量不允许本次完工。', 409);
                $this->checkOperations($workId, $date);
                $prior = $this->db->row('SELECT MAX(reported_on) AS reported_on FROM work_outputs WHERE org_id=? AND work_id=?', [$org, $workId]);
                Value::assert($prior['reported_on'] === null || $prior['reported_on'] <= $date, '完工日期不能早于该工单已有完工记录。', 409);
                $ledger = new WorkOutputLedger($this->db, $this->scope);
                if ($work['kind'] === 'rework') {
                    Value::assert($used === [], '返工领用须来自检验员登记的原批次。');
                    $rework = $this->takeRework($workId, $before, $quantity, $date);
                    $inputCost = $rework['amount'];
                    $inputs = [];
                } else {
                    [$inputs, $inputCost] = $this->consumeMaterials($workId, $before, $after, $date, $used);
                    $rework = null;
                }
                $support = $this->db->row("SELECT COALESCE(SUM(actual_minor),0) AS amount FROM work_cost_entries
                    WHERE org_id=? AND work_id=? AND element IN ('labor','overhead')", [$org, $workId]);
                $supportTotal = (int) $support['amount'];
                $applied = Value::allocation($supportTotal, $after, $total) - Value::allocation($supportTotal, $before, $total);
                $unitStandard = (int) $work['standard_unit_cost_minor'];
                $standard = Value::extendedPrice($after, $unitStandard) - Value::extendedPrice($before, $unitStandard);
                $result = $ledger->record($work, $location, $code, $quantity, $inputCost + $applied, $standard, $date);
                foreach ($inputs as [$issue, $lot, $usedQuantity, $cost]) {
                    $this->db->run('INSERT INTO work_output_inputs (org_id,output_id,issue_id,qty_milli,actual_minor) VALUES (?,?,?,?,?)',
                        [$org, $result['output_id'], $issue, $usedQuantity, $cost]);
                    $ledger->link($lot, $result['lot_id'], 'issue', $issue, $usedQuantity);
                }
                if ($rework !== null) {
                    $ledger->link($rework['lot'], $result['lot_id'], 'rework', $workId, $quantity);
                    (new WorkCostLedger($this->db, $this->scope))->record($workId, 'material', 'rework_input', $result['output_id'],
                        $inputCost, $standard, $date);
                }
                return $result;
            }), 'production');
    }

    public function outputs(int $workId): array
    {
        $this->requireRole('production', 'quality', 'planner', 'warehouse', 'finance', 'buyer');
        (new WorkOrderLedger($this->db, $this->scope))->order($workId);
        return $this->db->rows('SELECT w.*,l.lot_code,c.id AS inspection_id,c.state AS inspection_state,c.version,
            c.accepted_milli,c.rejected_milli,c.rework_milli FROM work_outputs w JOIN stock_lots l ON l.id=w.lot_id
            JOIN inspection_cases c ON c.org_id=w.org_id AND c.lot_id=w.lot_id
            WHERE w.org_id=? AND w.work_id=? ORDER BY w.id', [$this->scope->org, $workId]);
    }

    private function normalize(array $used): array
    {
        Value::assert(array_is_list($used) && count($used) <= 300, '投入批次数不能超过 300。');
        $report = [];
        foreach ($used as $entry) {
            $issue = $entry['issue_id'] ?? null;
            $qty = $entry['quantity'] ?? null;
            Value::assert(is_int($issue) && is_int($qty) && !isset($report[$issue]), '投入记录须选择唯一的领料行和使用量。');
            $report[$issue] = Value::quantity($qty);
        }
        ksort($report, SORT_NUMERIC);
        return $report;
    }

    private function consumeMaterials(int $work, int $before, int $after, string $date, array $used): array
    {
        $org = $this->scope->org;
        $summary = $inputs = [];
        $total = 0;
        foreach ($used as $issue => $quantity) {
            $row = $this->db->row('SELECT * FROM work_issues WHERE org_id=? AND work_id=? AND id=?', [$org, $work, $issue]);
            Value::assert($row !== null, '投入批次不属于本工单。', 404);
            $consumed = (int) $row['consumed_milli'];
            Value::assert($date >= $row['issued_on'] && $quantity <= (int) $row['qty_milli'] - (int) $row['returned_milli'] - $consumed,
                '投入数量或过账日期超出领料记录允许范围。', 409);
            $price = (int) $row['unit_cost_minor'];
            $cost = Value::extendedPrice($consumed + $quantity, $price) - Value::extendedPrice($consumed, $price);
            $this->db->run('UPDATE work_issues SET consumed_milli=consumed_milli+? WHERE org_id=? AND id=?', [$quantity, $org, $issue]);
            $inputs[] = [$issue, (int) $row['lot_id'], $quantity, $cost];
            $summary[$row['material_id']] = ($summary[$row['material_id']] ?? 0) + $quantity;
            $total += $cost;
        }
        $materials = $this->db->rows('SELECT m.*,i.code FROM work_materials m JOIN items i ON i.id=m.item_id
            WHERE m.org_id=? AND m.work_id=?', [$org, $work]);
        foreach ($materials as $line) {
            $prior = $before === 0 ? 0 : ProductEngineering::componentRequirement($before, $line);
            $required = ProductEngineering::componentRequirement($after, $line) - $prior;
            Value::assert(($summary[$line['id']] ?? 0) >= $required, '还须登记物料 ' . $line['code'] . ' 的实际投入批次。', 409);
        }
        return [$inputs, $total];
    }

    private function checkOperations(int $work, string $date): void
    {
        $blocked = $this->db->row('SELECT p.id FROM work_operations p LEFT JOIN operation_actuals a ON a.org_id=p.org_id AND a.operation_id=p.id
            WHERE p.org_id=? AND p.work_id=? AND (a.id IS NULL OR DATE(a.ends_at)>?) LIMIT 1', [$this->scope->org, $work, $date]);
        Value::assert($blocked === null, '登记完工前须先记录整张工单的工序实绩。', 409);
    }

    private function takeRework(int $work, int $before, int $quantity, string $date): array
    {
        $org = $this->scope->org;
        $row = $this->db->row('SELECT r.*,l.unit_cost_minor FROM rework_inputs r JOIN stock_lots l ON l.id=r.lot_id WHERE r.org_id=? AND r.work_id=?',
            [$org, $work]);
        Value::assert($row !== null && (int) $row['consumed_milli'] === $before
            && $before + $quantity <= (int) $row['qty_milli'], '未找到足够的待返工原批次。', 409);
        (new StockLedger($this->db, $this->scope))->post((int) $row['lot_id'], (int) $row['location_id'], 'rework', -$quantity,
            $date, 'work_reworked', $work);
        $this->db->run('UPDATE rework_inputs SET consumed_milli=consumed_milli+? WHERE org_id=? AND work_id=?', [$quantity, $org, $work]);
        $price = (int) $row['unit_cost_minor'];
        return ['lot' => (int) $row['lot_id'],
            'amount' => Value::extendedPrice($before + $quantity, $price) - Value::extendedPrice($before, $price)];
    }
}
