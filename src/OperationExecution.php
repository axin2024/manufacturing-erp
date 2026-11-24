<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;
use DateTimeZone;

final class OperationExecution extends Service
{
    public function report(string $key, int $operation, string $start, string $end, int $laborRate, int $overheadRate): array
    {
        Value::assert(Value::timestamp($start) < Value::timestamp($end), '实际结束时间须晚于开始时间。');
        Value::unitPrice($laborRate);
        Value::unitPrice($overheadRate);
        $utc = new DateTimeZone('UTC');
        $seconds = (new DateTimeImmutable($end, $utc))->getTimestamp() - (new DateTimeImmutable($start, $utc))->getTimestamp();
        Value::assert($seconds <= 86_400, '一次工序实绩不得超过 24 小时。');
        return $this->mutate(fn(int $org): array => $this->replay('work.operation_actual', $key,
            [$operation, $start, $end, $laborRate, $overheadRate],
            function () use ($org, $operation, $start, $end, $seconds, $laborRate, $overheadRate): array {
                $step = $this->db->row('SELECT * FROM work_operations WHERE org_id=? AND id=?', [$org, $operation]);
                Value::assert($step !== null, '工序不存在。', 404);
                $ledger = new WorkOrderLedger($this->db, $this->scope);
                $work = $ledger->order((int) $step['work_id']);
                Value::assert(in_array($work['state'], ['released', 'active'], true) && $work['kind'] !== 'subcontract',
                    '仅可登记已下达或执行中的内部工序。', 409);
                $slot = $this->db->row('SELECT id,machine_id,starts_at FROM operation_slots WHERE org_id=? AND operation_id=? AND active=1',
                    [$org, $operation]);
                Value::assert($slot !== null && $start >= $slot['starts_at'], '实绩须从预订的时间起登记。', 409);
                $existing = $this->db->row('SELECT id FROM operation_actuals WHERE org_id=? AND operation_id=?', [$org, $operation]);
                Value::assert($existing === null, '本工序已有实绩记录。', 409);
                $earlier = $this->db->row('SELECT p.id FROM work_operations p LEFT JOIN operation_actuals a
                    ON a.org_id=p.org_id AND a.operation_id=p.id WHERE p.org_id=? AND p.work_id=?
                    AND p.sequence_no<? AND (a.id IS NULL OR a.ends_at>?) LIMIT 1', [$org, $work['id'], $step['sequence_no'], $start]);
                Value::assert($earlier === null, '前序工序尚未完成或实际时间发生交叠。', 409);
                $conflict = $this->db->row('SELECT id FROM operation_actuals
                    WHERE org_id=? AND machine_id=? AND starts_at<? AND ends_at>? LIMIT 1', [$org, $slot['machine_id'], $end, $start]);
                Value::assert($conflict === null, '机台实际占用时间发生交叠。', 409);
                $id = $this->db->insert('INSERT INTO operation_actuals
                    (org_id,operation_id,slot_id,machine_id,starts_at,ends_at,actual_seconds,labor_rate_minor,overhead_rate_minor,actor_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?)', [$org, $operation, $slot['id'], $slot['machine_id'], $start, $end, $seconds, $laborRate,
                        $overheadRate, $this->scope->user]);
                $date = substr($end, 0, 10);
                $costs = new WorkCostLedger($this->db, $this->scope);
                $labor = $costs->record((int) $work['id'], 'labor', 'operation_actual', $id, $this->hourlyCost($seconds, $laborRate),
                    $this->hourlyCost((int) $step['standard_seconds'], $laborRate), $date);
                $overhead = $costs->record((int) $work['id'], 'overhead', 'operation_actual', $id, $this->hourlyCost($seconds, $overheadRate),
                    $this->hourlyCost((int) $step['standard_seconds'], $overheadRate), $date);
                $ledger->markStarted($work);
                $result = ['actual_id' => $id, 'operation_id' => $operation, 'actual_seconds' => $seconds,
                    'standard_seconds' => (int) $step['standard_seconds'], 'labor_cost_id' => $labor, 'overhead_cost_id' => $overhead];
                $this->audit('work.operation_reported', $id, $result);
                return $result;
            }), 'production');
    }

    private function hourlyCost(int $seconds, int $rate): int
    {
        return intdiv($seconds * $rate + 1800, 3600);
    }
}
