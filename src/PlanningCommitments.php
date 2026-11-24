<?php

declare(strict_types=1);

namespace ManufacturingErp;

/**
 * Links firm business documents to the approved plan within the caller's transaction.
 */
final class PlanningCommitments extends Service
{
    public function proposal(int $id, string $kind): array
    {
        $org = $this->scope->org;
        $row = $this->db->row('SELECT p.*,r.item_id,r.needed_on,r.bom_id,r.sales_demand_id,m.as_of,m.state AS run_state,
            d.state AS demand_state,(SELECT MAX(id) FROM mrp_runs WHERE org_id=p.org_id) AS latest_id
            FROM mrp_proposals p JOIN mrp_runs m ON m.org_id=p.org_id AND m.id=p.run_id
            JOIN mrp_requirements r ON r.run_id=p.run_id AND r.ref=p.requirement_ref
            LEFT JOIN sales_demands d ON d.org_id=p.org_id AND d.id=r.sales_demand_id
            WHERE p.org_id=? AND p.id=? AND p.kind=?', [$org, $id, $kind]);
        Value::assert($row !== null, '本账套没有该类型的计划建议。', 404);
        Value::assert($row['run_state'] === 'approved' && (int) $row['run_id'] === (int) $row['latest_id'],
            '计划建议须来自最新批准的计划运行。', 409);
        Value::assert($row['destination_id'] === null, '此计划建议已有下游单据。', 409);
        Value::assert($row['blocked_reason'] === null, '此计划建议缺少有效的物料清单。', 409);
        Value::assert($row['sales_demand_id'] === null || $row['demand_state'] === 'released', '源需求已经停止，请重算计划。', 409);
        return $row;
    }

    public function materialize(array $proposal, string $sourceKind, int $sourceId): void
    {
        $org = $this->scope->org;
        $this->db->run('UPDATE mrp_proposals SET destination_id=? WHERE org_id=? AND id=?', [$sourceId, $org, $proposal['id']]);
        if ($proposal['sales_demand_id'] !== null) {
            $this->db->run('INSERT INTO demand_commitments (org_id,demand_id,source_kind,source_id,qty_milli)
                VALUES (?,?,?,?,?)', [$org, $proposal['sales_demand_id'], $sourceKind, $sourceId, $proposal['qty_milli']]);
        }
    }

    public function state(string $kind, int $sourceId, string $state): void
    {
        $this->db->run("UPDATE demand_commitments SET state=? WHERE org_id=? AND source_kind=? AND source_id=? AND state<>'released'",
            [$state, $this->scope->org, $kind, $sourceId]);
    }

    public function supply(string $kind, int $id, int $item, string $day, int $quantity): void
    {
        $this->db->run('INSERT INTO planning_supplies (org_id,source_kind,source_id,item_id,available_on,remaining_milli)
            VALUES (?,?,?,?,?,?) AS measured ON DUPLICATE KEY UPDATE available_on=measured.available_on,remaining_milli=measured.remaining_milli',
            [$this->scope->org, $kind, $id, $item, $day, $quantity]);
    }
}
