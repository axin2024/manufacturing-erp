<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkOrderLedger extends Service
{
    public function order(int $id): array
    {
        $row = $this->db->row('SELECT w.*,i.code AS item_code,i.name AS item_name,i.base_uom,r.sales_demand_id
            FROM work_orders w JOIN items i ON i.id=w.item_id LEFT JOIN mrp_proposals p ON p.id=w.proposal_id
            LEFT JOIN mrp_requirements r ON r.run_id=p.run_id AND r.ref=p.requirement_ref
            WHERE w.org_id=? AND w.id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '生产工单不存在。', 404);
        return $row;
    }

    public function material(int $id): array
    {
        $row = $this->db->row('SELECT * FROM work_materials WHERE org_id=? AND id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '工单用料行不存在。', 404);
        return $row;
    }

    public function markStarted(array $work): void
    {
        if ($work['state'] === 'released') {
            $this->db->run("UPDATE work_orders SET state='active',started_at=COALESCE(started_at,UTC_TIMESTAMP(6)) WHERE org_id=? AND id=?",
                [$this->scope->org, $work['id']]);
            (new PlanningCommitments($this->db, $this->scope))->state('work', (int) $work['id'], 'started');
            $this->audit('work.started', (int) $work['id'], []);
        }
    }

    public function updateMaterialNeed(array $work, array $material, int $netIssued): void
    {
        $this->db->run('INSERT INTO planning_work_needs (org_id,id,work_id,sales_demand_id,item_id,needed_on,remaining_milli)
            VALUES (?,?,?,?,?,?,?) AS measured ON DUPLICATE KEY UPDATE remaining_milli=measured.remaining_milli',
            [$this->scope->org, $material['id'], $work['id'], $work['sales_demand_id'], $material['item_id'],
                $work['starts_on'], max(0, (int) $material['plan_milli'] - $netIssued)]);
    }

    public function updateSupply(array $work, int $remaining): void
    {
        (new PlanningCommitments($this->db, $this->scope))->supply('work', (int) $work['id'], (int) $work['item_id'], $work['due_on'], $remaining);
    }
}
