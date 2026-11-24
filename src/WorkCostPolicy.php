<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkCostPolicy extends Service
{
    public function freeze(int $work): string
    {
        $version = Extensions::load()->allocationVersion();
        if ($version !== Extensions::ALLOCATION_DEFAULT) {
            $this->db->run('INSERT INTO work_cost_policies (org_id,work_id,policy_version) VALUES (?,?,?)',
                [$this->scope->org, $work, $version]);
        }
        return $version;
    }

    public function apply(array $work, int $totalCost, int $after): int
    {
        $quantity = (int) $work['qty_milli'];
        $version = $work['cost_policy_version'];
        if ($version === null) {
            return Value::allocation($totalCost, $after, $quantity)
                - Value::allocation($totalCost, (int) $work['produced_milli'], $quantity);
        }
        $previous = (int) $work['support_applied_minor'];
        $amount = Extensions::load()->accumulatedCost($version, $totalCost, $after, $quantity, $previous);
        $this->db->run('UPDATE work_cost_policies SET applied_minor=? WHERE org_id=? AND work_id=?',
            [$amount, $this->scope->org, $work['id']]);
        return $amount - $previous;
    }
}
