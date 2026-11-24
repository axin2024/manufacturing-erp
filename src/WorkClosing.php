<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkClosing extends Service
{
    public function review(int $workId): array
    {
        $this->requireRole('finance', 'production', 'planner', 'buyer');
        return $this->readiness((new WorkOrderLedger($this->db, $this->scope))->order($workId));
    }

    public function close(int $workId, string $date): array
    {
        Value::day($date);
        return $this->mutate(function (int $org) use ($workId, $date): array {
            $ledger = new WorkOrderLedger($this->db, $this->scope);
            $work = $ledger->order($workId);
            if ($work['state'] === 'closed') {
                Value::assert($work['closed_on'] === $date, '工单已在其他日期结案。', 409);
                return ['work_id' => $workId, 'state' => 'closed', 'closed_on' => $date];
            }
            $review = $this->readiness($work);
            if ($review['blockers'] !== []) {
                return ['work_id' => $workId, 'state' => 'blocked', 'blockers' => $review['blockers']];
            }
            Value::assert($work['state'] === 'waiting_quality' && $date >= $review['last_posting'], '结案日期须覆盖工单的最终业务记录。', 409);
            $this->openPeriod($date);
            $this->db->run("UPDATE work_orders SET state='closed',closed_on=? WHERE org_id=? AND id=?", [$date, $org, $workId]);
            $ledger->updateSupply($work, 0);
            (new PlanningCommitments($this->db, $this->scope))->state('work', $workId, 'completed');
            $this->audit('work.closed', $workId, ['closed_on' => $date, 'wip_minor' => 0]);
            return ['work_id' => $workId, 'state' => 'closed', 'closed_on' => $date];
        }, 'production', 'finance');
    }

    private function readiness(array $work): array
    {
        $org = $this->scope->org;
        $id = (int) $work['id'];
        $blockers = [];
        $remaining = (int) $work['qty_milli'] - (int) $work['produced_milli'];
        if ($remaining > 0) {
            $blockers[] = ['kind' => 'unfinished_quantity', 'source_id' => $id, 'qty_milli' => $remaining];
        }
        $pending = $this->db->rows('SELECT c.id,c.qty_milli-c.accepted_milli-c.rejected_milli-c.rework_milli AS quantity
            FROM work_outputs o JOIN inspection_cases c ON c.org_id=o.org_id AND c.lot_id=o.lot_id
            WHERE o.org_id=? AND o.work_id=? AND c.state<>\'completed\' ORDER BY c.id', [$org, $id]);
        foreach ($pending as $row) {
            $blockers[] = ['kind' => 'pending_inspection', 'source_id' => (int) $row['id'], 'qty_milli' => (int) $row['quantity']];
        }
        $unreturned = $this->db->rows('SELECT id,qty_milli-returned_milli-consumed_milli AS quantity FROM work_issues
            WHERE org_id=? AND work_id=? AND qty_milli>returned_milli+consumed_milli ORDER BY id', [$org, $id]);
        foreach ($unreturned as $row) {
            $blockers[] = ['kind' => 'unreturned_material', 'source_id' => (int) $row['id'], 'qty_milli' => (int) $row['quantity']];
        }
        $lastVendor = $work['starts_on'];
        if ($work['kind'] === 'subcontract') {
            $contract = $this->db->row('SELECT id FROM subcontract_orders WHERE org_id=? AND work_id=?', [$org, $id]);
            if ($contract === null) {
                $blockers[] = ['kind' => 'missing_subcontract', 'source_id' => $id];
            } else {
                $vendor = new SubcontractLedger($this->db, $this->scope);
                foreach ($vendor->balances((int) $contract['id']) as $line) {
                    if ((int) $line['outside_milli'] > 0) {
                        $blockers[] = ['kind' => 'vendor_material_gap', 'source_id' => (int) $line['id'], 'qty_milli' => (int) $line['outside_milli']];
                    }
                    $lastVendor = max($lastVendor, $line['last_posted_on']);
                }
                foreach ($vendor->fees((int) $contract['id']) as $fee) {
                    if ($fee['matched_on'] === null) {
                        $blockers[] = ['kind' => 'unmatched_vendor_fee', 'source_id' => (int) $fee['id'], 'amount_minor' => (int) $fee['expected_minor']];
                    } else {
                        $lastVendor = max($lastVendor, $fee['matched_on']);
                    }
                }
            }
        }
        $cost = $this->db->row('SELECT MAX(posted_on) AS posted_on,COALESCE(SUM(actual_minor),0) AS amount
            FROM work_cost_entries WHERE org_id=? AND work_id=?', [$org, $id]);
        $wip = (int) $cost['amount'];
        if ($wip !== 0) {
            $blockers[] = ['kind' => 'open_wip', 'source_id' => $id, 'amount_minor' => $wip];
        }
        $inspection = $this->db->row('SELECT MAX(d.measured_on) AS posted_on FROM work_outputs o
            JOIN inspection_cases c ON c.org_id=o.org_id AND c.lot_id=o.lot_id
            JOIN inspection_decisions d ON d.org_id=c.org_id AND d.case_id=c.id WHERE o.org_id=? AND o.work_id=?', [$org, $id]);
        $last = max($work['starts_on'], $cost['posted_on'] ?? '', $inspection['posted_on'] ?? '', $lastVendor);
        return ['work_id' => $id, 'state' => $work['state'], 'blockers' => $blockers, 'last_posting' => $last, 'wip_minor' => $wip];
    }
}
