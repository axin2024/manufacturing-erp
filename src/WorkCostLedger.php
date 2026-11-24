<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkCostLedger extends Service
{
    public function record(int $work, string $element, string $sourceKind, int $sourceId, int $actual, int $standard, string $day): int
    {
        $period = $this->openPeriod($day);
        return $this->db->insert('INSERT INTO work_cost_entries
            (org_id,work_id,element,source_kind,source_id,actual_minor,standard_minor,posted_on,period_start,actor_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$this->scope->org, $work, $element, $sourceKind, $sourceId, $actual, $standard, $day, $period, $this->scope->user]);
    }
}
