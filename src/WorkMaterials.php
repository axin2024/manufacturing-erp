<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkMaterials extends Service
{
    public function authorize(string $key, int $material, string $kind, int $useItem, int $quantity, string $reason): array
    {
        Value::assert(in_array($kind, ['substitute', 'overage'], true), '领料审批类型无效。');
        Value::quantity($quantity);
        $reason = Value::text($reason, 255, '审批依据');
        return $this->mutate(fn(int $org): array => $this->replay('work.authorize_material', $key,
            [$material, $kind, $useItem, $quantity, $reason],
            function () use ($org, $material, $kind, $useItem, $quantity, $reason): array {
                $ledger = new WorkOrderLedger($this->db, $this->scope);
                $line = $ledger->material($material);
                $work = $ledger->order((int) $line['work_id']);
                Value::assert(in_array($work['state'], ['approved', 'released', 'active', 'paused'], true), '工单状态不允许追加用料审批。', 409);
                if ($useItem !== (int) $line['item_id']) {
                    $alternative = $this->db->row('SELECT item_id FROM bom_alternatives WHERE org_id=? AND bom_id=? AND item_id=? AND alternative_item_id=?',
                        [$org, $work['bom_id'], $line['item_id'], $useItem]);
                    Value::assert($alternative !== null, '该物料不在工单冻结的替代料清单内。', 409);
                } else {
                    Value::assert($kind === 'overage', '替代料审批必须选择不同的物料。');
                }
                $id = $this->db->insert('INSERT INTO work_issue_authorizations
                    (org_id,material_id,kind,use_item_id,qty_milli,reason,approved_by) VALUES (?,?,?,?,?,?,?)',
                    [$org, $material, $kind, $useItem, $quantity, $reason, $this->scope->user]);
                $this->audit('work.material_authorized', $id, ['material_id' => $material, 'kind' => $kind,
                    'use_item_id' => $useItem, 'qty_milli' => $quantity, 'reason' => $reason]);
                return ['authorization_id' => $id];
            }), 'planner');
    }

    public function issue(string $key, int $material, int $lot, int $location, int $quantity, string $day,
        ?int $substituteApproval = null, ?int $overageApproval = null): array
    {
        Value::quantity($quantity);
        Value::day($day);
        return $this->mutate(fn(int $org): array => $this->replay('work.issue', $key,
            [$material, $lot, $location, $quantity, $day, $substituteApproval, $overageApproval],
            function () use ($org, $material, $lot, $location, $quantity, $day, $substituteApproval, $overageApproval): array {
                $ledger = new WorkOrderLedger($this->db, $this->scope);
                $stock = new StockLedger($this->db, $this->scope);
                $line = $ledger->material($material);
                $workId = (int) $line['work_id'];
                $work = $ledger->order($workId);
                Value::assert($work['kind'] !== 'subcontract', '委外供料必须通过加工方台账发出。', 409);
                Value::assert(in_array($work['state'], ['released', 'active'], true) && $day >= $work['starts_on'],
                    '仅可向已下达或执行中的工单领料，且日期不早于计划开工。', 409);
                $input = $stock->lot($lot);
                $net = $ledger->applyIssueApprovals($line, (int) $input['item_id'], $quantity, $substituteApproval, $overageApproval);
                $id = $this->db->insert('INSERT INTO work_issues
                    (org_id,work_id,material_id,lot_id,location_id,replacement_approval_id,overage_approval_id,qty_milli,unit_cost_minor,issued_on,actor_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$org, $workId, $material, $lot, $location, $substituteApproval, $overageApproval,
                        $quantity, $input['unit_cost_minor'], $day, $this->scope->user]);
                $actual = (new StockValuation($this->db, $this->scope))->take($lot, $quantity);
                $movement = $stock->post($lot, $location, 'available', -$quantity, $day, 'work_issue', $id);
                $standard = Value::extendedPrice($quantity, (int) $line['standard_unit_cost_minor']);
                $cost = (new WorkCostLedger($this->db, $this->scope))->record($workId, 'material', 'issue', $id, $actual, $standard, $day);
                $this->db->run('UPDATE work_materials SET issued_milli=issued_milli+? WHERE org_id=? AND id=?', [$quantity, $org, $material]);
                $ledger->updateMaterialNeed($work, $line, $net);
                $ledger->markStarted($work);
                $result = ['issue_id' => $id, 'work_id' => $workId, 'movement_id' => $movement, 'cost_id' => $cost,
                    'qty_milli' => $quantity, 'actual_minor' => $actual, 'standard_minor' => $standard, 'variance_minor' => $actual - $standard];
                $this->audit('work.material_issued', $id, $result);
                return $result;
            }), 'production', 'warehouse');
    }

    public function returnToStock(string $key, int $issue, int $location, int $quantity, string $day): array
    {
        Value::quantity($quantity);
        Value::day($day);
        return $this->mutate(fn(int $org): array => $this->replay('work.return', $key, [$issue, $location, $quantity, $day],
            function () use ($org, $issue, $location, $quantity, $day): array {
                $prior = $this->db->row('SELECT * FROM work_issues WHERE org_id=? AND id=?', [$org, $issue]);
                Value::assert($prior !== null, '未找到领料记录。', 404);
                $workId = (int) $prior['work_id'];
                $ledger = new WorkOrderLedger($this->db, $this->scope);
                $work = $ledger->order($workId);
                $received = (int) $prior['returned_milli'];
                $left = (int) $prior['qty_milli'] - $received - (int) $prior['consumed_milli'];
                Value::assert(in_array($work['state'], ['active', 'paused', 'waiting_quality'], true) && $day >= $prior['issued_on']
                    && $quantity <= $left, '退料须来自未耗用的领料余额，且不能早于领料日期。', 409);
                $this->location($location);
                $material = $ledger->material((int) $prior['material_id']);
                $id = $this->db->insert('INSERT INTO work_returns (org_id,issue_id,location_id,qty_milli,received_on,actor_id)
                    VALUES (?,?,?,?,?,?)', [$org, $issue, $location, $quantity, $day, $this->scope->user]);
                $unreturned = (int) $prior['qty_milli'] - $received;
                $orig = $this->db->row("SELECT actual_minor FROM work_cost_entries WHERE org_id=? AND source_kind='issue'
                    AND source_id=? AND element='material'", [$org, $issue]);
                $basis = (int) $orig['actual_minor'];
                $issued = (int) $prior['qty_milli'];
                $actual = Value::allocation($basis, $unreturned, $issued) - Value::allocation($basis, $unreturned - $quantity, $issued);
                (new StockValuation($this->db, $this->scope))->restore((int) $prior['lot_id'], $quantity, $actual);
                $movement = (new StockLedger($this->db, $this->scope))->post((int) $prior['lot_id'], $location, 'available', $quantity,
                    $day, 'work_return', $id);
                $standard = $this->returnedAmount($unreturned, $quantity, (int) $material['standard_unit_cost_minor']);
                $cost = (new WorkCostLedger($this->db, $this->scope))->record($workId, 'material', 'return', $id, -$actual, -$standard, $day);
                $this->db->run('UPDATE work_issues SET returned_milli=returned_milli+? WHERE org_id=? AND id=?', [$quantity, $org, $issue]);
                $this->db->run('UPDATE work_materials SET returned_milli=returned_milli+? WHERE org_id=? AND id=?',
                    [$quantity, $org, $material['id']]);
                $net = (int) $material['issued_milli'] - ((int) $material['returned_milli'] + $quantity);
                if ($work['state'] !== 'waiting_quality') {
                    $ledger->updateMaterialNeed($work, $material, $net);
                }
                $result = ['return_id' => $id, 'issue_id' => $issue, 'work_id' => $workId, 'movement_id' => $movement, 'cost_id' => $cost];
                $this->audit('work.material_returned', $id, $result);
                return $result;
            }), 'production', 'warehouse');
    }

    public function flows(int $work, int $before = PHP_INT_MAX): array
    {
        $this->requireRole('production', 'warehouse', 'planner', 'finance');
        (new WorkOrderLedger($this->db, $this->scope))->order($work);
        $org = $this->scope->org;
        return [
            'issues' => $this->db->rows('SELECT a.*,l.lot_code,i.code,c.actual_minor,c.standard_minor,
                c.actual_minor-c.standard_minor AS variance_minor
                FROM work_issues a JOIN stock_lots l ON l.id=a.lot_id JOIN items i ON i.id=l.item_id
                JOIN work_cost_entries c ON c.org_id=a.org_id AND c.source_kind=\'issue\' AND c.source_id=a.id AND c.element=\'material\'
                WHERE a.org_id=? AND a.work_id=? AND a.id<? ORDER BY a.id DESC LIMIT 51', [$org, $work, $before]),
            'authorizations' => $this->db->rows('SELECT a.*,i.code FROM work_issue_authorizations a JOIN items i ON i.id=a.use_item_id
                JOIN work_materials m ON m.id=a.material_id WHERE a.org_id=? AND m.work_id=? ORDER BY a.id', [$org, $work]),
        ];
    }

    private function returnedAmount(int $unreturned, int $quantity, int $cost): int
    {
        return Value::extendedPrice($unreturned, $cost) - Value::extendedPrice($unreturned - $quantity, $cost);
    }
}
