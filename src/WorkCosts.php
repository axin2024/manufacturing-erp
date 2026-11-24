<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkCosts extends Service
{
    public function report(int $work, int $before = PHP_INT_MAX): array
    {
        $this->requireRole('finance', 'production', 'planner');
        $record = (new WorkOrderLedger($this->db, $this->scope))->order($work);
        $org = $this->scope->org;
        $summary = $this->db->rows('SELECT element,SUM(actual_minor) AS actual_minor,SUM(standard_minor) AS standard_minor,
            SUM(actual_minor-standard_minor) AS variance_minor
            FROM work_cost_entries WHERE org_id=? AND work_id=? GROUP BY element ORDER BY element', [$org, $work]);
        $entries = $this->db->rows("SELECT c.*,u.name AS actor,c.actual_minor-c.standard_minor AS variance_minor,
            i.id AS issue_id,l.lot_code,l.id AS input_lot_id
            FROM work_cost_entries c JOIN users u ON u.id=c.actor_id
            LEFT JOIN work_returns r ON c.source_kind='return' AND r.org_id=c.org_id AND r.id=c.source_id
            LEFT JOIN work_issues i ON i.org_id=c.org_id AND i.id=IF(c.source_kind='issue',c.source_id,r.issue_id)
            LEFT JOIN stock_lots l ON l.id=i.lot_id WHERE c.org_id=? AND c.work_id=? AND c.id<? ORDER BY c.id DESC LIMIT 51",
            [$org, $work, $before]);
        return ['work_id' => $work, 'number' => $record['number'], 'summary' => $summary, 'entries' => $entries,
            'wip_minor' => array_sum(array_column($summary, 'actual_minor'))];
    }
}
