<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkCostLedger extends Service
{
    public function record(int $work, string $element, string $sourceKind, int $sourceId, int $actual, int $standard, string $day): int
    {
        $period = $this->openPeriod($day);
        $id = $this->db->insert('INSERT INTO work_cost_entries
            (org_id,work_id,element,source_kind,source_id,actual_minor,standard_minor,posted_on,period_start,actor_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$this->scope->org, $work, $element, $sourceKind, $sourceId, $actual, $standard, $day, $period, $this->scope->user]);
        $accounts = match ($sourceKind) {
            'issue', 'return', 'rework_input' => ['WIP', 'STOCK'],
            'operation_actual' => ['WIP', $element === 'labor' ? 'LABOR_CLEAR' : 'OVERHEAD_CLEAR'],
            'vendor_usage' => ['WIP', 'VENDOR_STOCK'],
            'vendor_fee_accrued' => ['WIP', 'VENDOR_ACCRUAL'],
            'output' => ['WIP', 'STOCK'],
            default => null,
        };
        if ($accounts !== null) {
            (new FinanceLedger($this->db, $this->scope))->move('work_cost', $id, $day, '工单成本 ' . $work,
                $accounts[0], $accounts[1], $actual);
        }
        return $id;
    }
}
