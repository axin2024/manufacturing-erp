<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkOutputLedger extends Service
{
    public function record(array $work, int $location, string $code, int $quantity, int $actual, int $standard, string $date): array
    {
        $org = $this->scope->org;
        $workId = (int) $work['id'];
        $after = (int) $work['produced_milli'] + $quantity;
        $remaining = (int) $work['qty_milli'] - $after;
        Value::assert($remaining >= 0, '完工数不能超过剩余计划。', 409);
        Value::assert($actual >= 0 && $actual <= intdiv(Value::MAX_UNIT_PRICE * $quantity, Value::SCALE), '完工分摊单价超出范围。');
        $unitCost = intdiv($actual, $quantity) * Value::SCALE
            + intdiv(($actual % $quantity) * Value::SCALE + intdiv($quantity, 2), $quantity);
        $source = $work['kind'] === 'subcontract' ? 'subcontract' : 'work';
        $lot = (new StockLedger($this->db, $this->scope))->createLot((int) $work['item_id'], $location, $code,
            $work['base_uom'], $quantity, $unitCost, $date, 'pending', 'work_output', $workId, null, $actual);
        $id = $this->db->insert('INSERT INTO work_outputs (org_id,work_id,lot_id,location_id,qty_milli,actual_minor,standard_minor,reported_on,actor_id)
            VALUES (?,?,?,?,?,?,?,?,?)', [$org, $workId, $lot['lot_id'], $location, $quantity, $actual, $standard, $date, $this->scope->user]);
        $inspection = (new InspectionLedger($this->db, $this->scope))->register($lot['lot_id'], $location, $source, $id, $quantity);
        $cost = (new WorkCostLedger($this->db, $this->scope))->record($workId, 'capitalized', 'output', $id, -$actual, -$standard, $date);
        $ledger = new WorkOrderLedger($this->db, $this->scope);
        $ledger->markStarted($work);
        $state = $remaining === 0 ? 'waiting_quality' : 'active';
        $this->db->run('UPDATE work_orders SET produced_milli=?,state=? WHERE org_id=? AND id=?', [$after, $state, $org, $workId]);
        $ledger->updateSupply($work, $remaining);
        if ($remaining === 0) {
            $this->db->run('UPDATE planning_work_needs SET remaining_milli=0 WHERE org_id=? AND work_id=?', [$org, $workId]);
        }
        $result = ['output_id' => $id, 'work_id' => $workId, 'inspection_id' => $inspection, 'cost_id' => $cost,
            'work_state' => $state, 'actual_minor' => $actual, 'standard_minor' => $standard, ...$lot];
        $this->audit('work.output_reported', $id, $result);
        return $result;
    }

    public function link(int $input, int $output, string $kind, int $source, int $quantity): void
    {
        $this->db->run('INSERT INTO lot_genealogy (org_id,input_lot_id,output_lot_id,source_kind,source_id,qty_milli) VALUES (?,?,?,?,?,?)',
            [$this->scope->org, $input, $output, $kind, $source, $quantity]);
    }
}
