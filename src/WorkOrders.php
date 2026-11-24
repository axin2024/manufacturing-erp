<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkOrders extends Service
{
    public function create(string $key, string $number, int $item, int $quantity, string $engineeringOn, string $startsOn,
        string $dueOn, ?int $proposalId = null): array
    {
        $number = Value::code($number, '工单号');
        Value::assert(!str_starts_with($number, 'RW-'), 'RW- 编号用于检验生成的返工单。');
        Value::quantity($quantity);
        Value::assert(Value::day($engineeringOn) <= Value::day($startsOn) && $startsOn <= Value::day($dueOn),
            '工单日期须按工程基准、计划开工、计划完成排序。');
        return $this->mutate(fn(int $org): array => $this->replay('work.create', $key,
            [$number, $item, $quantity, $engineeringOn, $startsOn, $dueOn, $proposalId],
            function () use ($org, $number, $item, $quantity, $engineeringOn, $startsOn, $dueOn, $proposalId): array {
                $record = $this->organizationItem($item);
                $kind = $record['sourcing'];
                Value::assert($kind !== 'purchase', '直接采购的物料不能创建生产工单。');
                Value::assert($this->db->row('SELECT id FROM work_orders WHERE org_id=? AND number=?', [$org, $number]) === null,
                    '工单号已存在。', 409);
                $engineering = new ProductEngineering($this->db, $this->scope);
                if ($proposalId === null) {
                    $bom = $engineering->bom($item, $engineeringOn);
                } else {
                    $proposal = (new PlanningCommitments($this->db, $this->scope))->proposal($proposalId, $kind);
                    Value::assert((int) $proposal['item_id'] === $item && (int) $proposal['qty_milli'] === $quantity
                        && $proposal['as_of'] === $engineeringOn, '工单的物料、数量和工程基准日必须与计划建议一致。');
                    $bom = $engineering->bomRevision((int) $proposal['bom_id']);
                }
                $route = $engineering->route($item, $engineeringOn);
                Value::assert($bom !== null && ($kind === 'subcontract' || $route !== null), '工单缺少有效的 BOM 或工艺路线。', 409);
                $work = $this->db->insert('INSERT INTO work_orders
                    (org_id,number,kind,item_id,proposal_id,bom_id,route_id,qty_milli,standard_unit_cost_minor,engineering_on,starts_on,due_on,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$org, $number, $kind, $item, $proposalId, $bom['id'], $route['id'] ?? null,
                        $quantity, $record['standard_cost_minor'], $engineeringOn, $startsOn, $dueOn, $this->scope->user]);
                $this->snapshotEngineering($org, $work, $quantity, $bom, $kind === 'subcontract' ? null : $route);
                $policy = $kind === 'manufacture' ? (new WorkCostPolicy($this->db, $this->scope))->freeze($work) : null;
                $this->audit('work.created', $work, ['number' => $number, 'bom_id' => $bom['id'], 'route_id' => $route['id'] ?? null,
                    'proposal_id' => $proposalId, 'cost_policy_version' => $policy]);
                return ['work_id' => $work, 'state' => 'draft'];
            }), 'planner');
    }

    public function approve(int $id): array
    {
        return $this->mutate(function (int $org) use ($id): array {
            $work = (new WorkOrderLedger($this->db, $this->scope))->order($id);
            Value::assert(in_array($work['state'], ['draft', 'approved'], true), '此状态无法审核工单。', 409);
            if ($work['state'] === 'draft') {
                $this->setState($org, $id, 'approved');
                $this->audit('work.approved', $id, []);
            }
            return ['work_id' => $id, 'state' => 'approved'];
        }, 'planner');
    }

    public function release(int $id): array
    {
        return $this->mutate(function (int $org) use ($id): array {
            $ledger = new WorkOrderLedger($this->db, $this->scope);
            $work = $ledger->order($id);
            if ($work['state'] === 'released') {
                return ['work_id' => $id, 'state' => 'released'];
            }
            Value::assert($work['state'] === 'approved', '只有已审核工单可以下达。', 409);
            if ($work['kind'] === 'subcontract') {
                Value::assert($this->db->row('SELECT id FROM subcontract_orders WHERE org_id=? AND work_id=?', [$org, $id]) !== null,
                    '委外工单须先确定加工方和合同加工费。', 409);
            }
            $unscheduled = $this->db->row('SELECT p.id FROM work_operations p LEFT JOIN operation_slots s
                ON s.org_id=p.org_id AND s.operation_id=p.id AND s.active=1 WHERE p.org_id=? AND p.work_id=? AND s.id IS NULL LIMIT 1',
                [$org, $id]);
            Value::assert($unscheduled === null, '还有工序未预留机台和班次。', 409);
            if ($work['proposal_id'] !== null) {
                $link = new PlanningCommitments($this->db, $this->scope);
                $link->materialize($link->proposal((int) $work['proposal_id'], $work['kind']), 'work', $id);
            }
            foreach ($this->materials($id) as $material) {
                $ledger->updateMaterialNeed($work, $material, 0);
            }
            $ledger->updateSupply($work, (int) $work['qty_milli']);
            $this->setState($org, $id, 'released');
            $this->audit('work.released', $id, []);
            return ['work_id' => $id, 'state' => 'released'];
        }, 'planner', 'production');
    }

    public function pause(int $id, string $reason): array
    {
        $reason = Value::text($reason, 255, '挂起原因');
        return $this->mutate(function (int $org) use ($id, $reason): array {
            $ledger = new WorkOrderLedger($this->db, $this->scope);
            $work = $ledger->order($id);
            Value::assert(in_array($work['state'], ['released', 'active'], true), '工单只有在待开工或执行中才可挂起。', 409);
            $this->db->run("UPDATE work_orders SET state='paused',hold_reason=? WHERE org_id=? AND id=?", [$reason, $org, $id]);
            $ledger->updateSupply($work, 0);
            $this->audit('work.paused', $id, ['reason' => $reason]);
            return ['work_id' => $id, 'state' => 'paused'];
        }, 'planner', 'production');
    }

    public function resume(int $id): array
    {
        return $this->mutate(function (int $org) use ($id): array {
            $ledger = new WorkOrderLedger($this->db, $this->scope);
            $work = $ledger->order($id);
            Value::assert($work['state'] === 'paused', '该工单没有处于挂起状态。', 409);
            $state = $work['started_at'] === null ? 'released' : 'active';
            $this->db->run('UPDATE work_orders SET state=?,hold_reason=NULL WHERE org_id=? AND id=?', [$state, $org, $id]);
            $ledger->updateSupply($work, (int) $work['qty_milli'] - (int) $work['produced_milli']);
            $this->audit('work.resumed', $id, ['state' => $state]);
            return ['work_id' => $id, 'state' => $state];
        }, 'planner', 'production');
    }

    public function cancel(int $id, string $reason): array
    {
        $reason = Value::text($reason, 255, '撤销原因');
        return $this->mutate(function (int $org) use ($id, $reason): array {
            $ledger = new WorkOrderLedger($this->db, $this->scope);
            $work = $ledger->order($id);
            Value::assert(in_array($work['state'], ['draft', 'approved', 'released', 'active', 'paused'], true)
                && (int) $work['produced_milli'] === 0, '已有完工或终结记录，无法撤销工单。', 409);
            Value::assert($work['kind'] !== 'rework', '返工单关联实际待处理库存，须通过质检流程处理。', 409);
            Value::assert($work['started_at'] === null, '已开工工单须通过余料、成本和产出结案流程处理。', 409);
            $this->db->run('UPDATE operation_slots s JOIN work_operations p ON p.id=s.operation_id
                SET s.active=0 WHERE s.org_id=? AND p.work_id=? AND s.active=1', [$org, $id]);
            $this->db->run('UPDATE planning_work_needs SET remaining_milli=0 WHERE org_id=? AND work_id=?', [$org, $id]);
            $ledger->updateSupply($work, 0);
            (new PlanningCommitments($this->db, $this->scope))->state('work', $id, 'released');
            $this->setState($org, $id, 'cancelled');
            $this->audit('work.cancelled', $id, ['reason' => $reason]);
            return ['work_id' => $id, 'state' => 'cancelled'];
        }, 'planner');
    }

    public function orders(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('planner', 'production', 'warehouse', 'quality', 'finance', 'buyer');
        return $this->db->rows('SELECT w.id,w.number,w.kind,w.state,w.qty_milli,w.produced_milli,w.due_on,i.code,i.name
            FROM work_orders w JOIN items i ON i.id=w.item_id WHERE w.org_id=? AND w.id<? ORDER BY w.id DESC LIMIT 51',
            [$this->scope->org, $before]);
    }

    public function details(int $id): array
    {
        $this->requireRole('planner', 'production', 'warehouse', 'quality', 'finance', 'buyer');
        $work = (new WorkOrderLedger($this->db, $this->scope))->order($id);
        $work['materials'] = $this->materials($id);
        $work['operations'] = $this->db->rows('SELECT p.*,s.id AS slot_id,s.starts_at,s.ends_at,m.code AS machine_code,
            a.actual_seconds,a.starts_at AS actual_starts_at,a.ends_at AS actual_ends_at
            FROM work_operations p LEFT JOIN operation_slots s ON s.org_id=p.org_id AND s.operation_id=p.id AND s.active=1
            LEFT JOIN operation_actuals a ON a.org_id=p.org_id AND a.operation_id=p.id
            LEFT JOIN machines m ON m.id=s.machine_id WHERE p.org_id=? AND p.work_id=? ORDER BY p.sequence_no', [$this->scope->org, $id]);
        $work['rework_orders'] = $this->db->rows('SELECT id,number,state,qty_milli,produced_milli
            FROM work_orders WHERE org_id=? AND parent_work_id=? ORDER BY id', [$this->scope->org, $id]);
        return $work;
    }

    private function materials(int $id): array
    {
        return $this->db->rows('SELECT m.*,i.code,i.name,i.base_uom FROM work_materials m JOIN items i ON i.id=m.item_id
            WHERE m.org_id=? AND m.work_id=? ORDER BY m.id', [$this->scope->org, $id]);
    }

    private function snapshotEngineering(int $org, int $work, int $quantity, array $bom, ?array $route): void
    {
        foreach ($bom['lines'] as $line) {
            $item = $this->organizationItem((int) $line['item_id']);
            $this->db->run('INSERT INTO work_materials (org_id,work_id,item_id,plan_milli,standard_unit_cost_minor,qty_per_milli,loss_bps)
                VALUES (?,?,?,?,?,?,?)', [$org, $work, $line['item_id'], ProductEngineering::componentRequirement($quantity, $line),
                    $item['standard_cost_minor'], $line['qty_per_milli'], $line['loss_bps']]);
        }
        foreach ($route['steps'] ?? [] as $step) {
            $standard = (int) $step['setup_minutes'] * 60 + intdiv((int) $step['run_seconds_per_unit'] * $quantity + 999, Value::SCALE);
            $this->db->run('INSERT INTO work_operations (org_id,work_id,sequence_no,center_id,label,standard_seconds) VALUES (?,?,?,?,?,?)',
                [$org, $work, $step['sequence_no'], $step['center_id'], $step['label'], $standard]);
        }
    }

    private function setState(int $org, int $work, string $state): void
    {
        $this->db->run('UPDATE work_orders SET state=? WHERE org_id=? AND id=?', [$state, $org, $work]);
    }
}
