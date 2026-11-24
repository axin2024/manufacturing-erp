<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

final class ReworkLedger extends Service
{
    public function open(array $inspection, int $decision, int $quantity, string $date): int
    {
        $org = $this->scope->org;
        $output = $this->db->row('SELECT work_id FROM work_outputs WHERE org_id=? AND id=? AND lot_id=?',
            [$org, $inspection['source_id'], $inspection['lot_id']]);
        Value::assert($output !== null, '无法从检验单定位完工记录。', 409);
        $parent = (new WorkOrderLedger($this->db, $this->scope))->order((int) $output['work_id']);
        Value::assert($parent['route_id'] !== null, '没有内部返工工艺可用。请登记拒收并通过委外单处理。', 409);
        $due = max($parent['due_on'], (new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d'));
        $id = $this->db->insert("INSERT INTO work_orders
            (org_id,number,kind,item_id,route_id,parent_work_id,rework_decision_id,qty_milli,standard_unit_cost_minor,engineering_on,starts_on,due_on,created_by)
            VALUES (?,?,'rework',?,?,?,?,?,?,?,?,?,?)", [$org, 'RW-' . $decision, $parent['item_id'], $parent['route_id'],
                $parent['id'], $decision, $quantity, $parent['standard_unit_cost_minor'], $parent['engineering_on'], $date, $due, $this->scope->user]);
        $this->db->run('INSERT INTO rework_inputs (org_id,work_id,lot_id,location_id,qty_milli) VALUES (?,?,?,?,?)',
            [$org, $id, $inspection['lot_id'], $inspection['location_id'], $quantity]);
        $steps = $this->db->rows('SELECT sequence_no,center_id,label,setup_minutes,run_seconds_per_unit
            FROM route_steps WHERE org_id=? AND route_id=? ORDER BY sequence_no', [$org, $parent['route_id']]);
        foreach ($steps as $step) {
            $standard = (int) $step['setup_minutes'] * 60 + intdiv((int) $step['run_seconds_per_unit'] * $quantity + 999, Value::SCALE);
            $this->db->run('INSERT INTO work_operations (org_id,work_id,sequence_no,center_id,label,standard_seconds) VALUES (?,?,?,?,?,?)',
                [$org, $id, $step['sequence_no'], $step['center_id'], $step['label'], $standard]);
        }
        $this->audit('work.rework_opened', $id, ['parent_work_id' => (int) $parent['id'], 'decision_id' => $decision,
            'source_lot_id' => (int) $inspection['lot_id'], 'qty_milli' => $quantity]);
        return $id;
    }
}
