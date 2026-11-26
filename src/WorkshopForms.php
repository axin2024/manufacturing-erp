<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkshopForms extends WorkspaceScreen
{
    public function creation(): array
    {
        if (!$this->scope->can('planner')) {
            return [];
        }
        $items = array_filter((new MasterData($this->db, $this->scope))->items(), static fn(array $row): bool => $row['sourcing'] !== 'purchase');
        return [$this->requestForm('新建生产工单', 'create-work', [
            $this->field('number', '工单号', 'text', ['maxlength' => 40]),
            $this->field('item', '自制或委外物料', 'select', ['options' => $this->options($items, 'code', 'name')]),
            $this->quantityField('quantity', '计划数量'),
            $this->dateField('engineering_on', '工程基准日期'), $this->dateField('starts_on', '计划开工日'),
            $this->dateField('due_on', '计划完成日'),
            $this->field('proposal_id', '计划建议号（选填）', 'number', ['min' => 1, 'required' => false]),
        ])];
    }

    public function forOrder(array $work, bool $hasContract): array
    {
        $forms = $this->transitions($work, $hasContract);
        $state = $work['state'];
        if ($work['operations'] !== []) {
            array_push($forms, ...$this->operations($work));
        }
        if ($work['materials'] !== [] && $this->scope->can('planner') && in_array($state, ['approved','released','active','paused'], true)) {
            $forms[] = $this->requestForm('批准替代或超额领料', 'authorize-material', [
                $this->field('material', '冻结用料', 'select', ['options' => $this->options($work['materials'], 'code', 'name')]),
                $this->field('kind', '批准类型', 'select', ['options' => ['substitute' => '替代物料', 'overage' => '超额领料']]),
                $this->field('use_item', '批准使用的物料', 'select', ['options' => $this->items()]),
                $this->quantityField('quantity', '批准数量'), $this->field('reason', '批准依据'),
            ]);
        }
        if ($work['kind'] !== 'subcontract' && $this->scope->can('production', 'warehouse')) {
            array_push($forms, ...$this->shopFloor($work));
        }
        return $forms;
    }

    public function workStock(int $id): array
    {
        $stock = $this->db->rows("SELECT DISTINCT l.id,l.lot_code,i.code FROM stock_lots l JOIN items i ON i.id=l.item_id
            JOIN stock_balances b ON b.org_id=l.org_id AND b.lot_id=l.id AND b.quality='available' AND b.qty_milli>0
            WHERE l.org_id=? AND (EXISTS (SELECT 1 FROM work_materials m WHERE m.org_id=l.org_id AND m.work_id=? AND m.item_id=l.item_id)
              OR EXISTS (SELECT 1 FROM work_issue_authorizations a JOIN work_materials m ON m.org_id=a.org_id AND m.id=a.material_id
                 WHERE m.org_id=l.org_id AND m.work_id=? AND a.use_item_id=l.item_id AND a.used_milli<a.qty_milli))
            ORDER BY i.code,l.lot_code", [$this->scope->org, $id, $id]);
        return $this->options($stock, 'code', 'lot_code');
    }

    private function transitions(array $work, bool $hasContract): array
    {
        $state = $work['state'];
        $id = (int) $work['id'];
        $forms = [];
        if ($state === 'approved' && $this->scope->can('planner', 'production')) {
            $ready = $work['kind'] === 'subcontract' ? $hasContract
                : array_filter($work['operations'], static fn(array $row): bool => $row['slot_id'] === null) === [];
            if ($ready) {
                $forms[] = $this->form('下达工单', 'release-work', [], ['id' => $id]);
            }
        }
        if ($this->scope->can('planner', 'production')) {
            if (in_array($state, ['released','active'], true)) {
                $forms[] = $this->form('挂起工单', 'pause-work', [$this->field('reason', '挂起原因')], ['id' => $id]);
            } elseif ($state === 'paused') {
                $forms[] = $this->form('恢复工单', 'resume-work', [], ['id' => $id]);
            }
        }
        if ($this->scope->can('planner') && $work['kind'] !== 'rework' && $work['started_at'] === null
            && in_array($state, ['draft','approved','released','paused'], true)) {
            $forms[] = $this->form('撤销未开工工单', 'cancel-work', [$this->field('reason', '撤销原因')], ['id' => $id]);
        }
        if ($state === 'waiting_quality' && $this->scope->can('production', 'finance')) {
            $forms[] = $this->form('结案工单', 'close-work', [$this->dateField('posted_on', '结案日期')], ['id' => $id],
                '系统会复核待检数量、未退余料、在制金额及委外费用。');
        }
        return $forms;
    }

    private function operations(array $work): array
    {
        $state = $work['state'];
        $forms = [];
        $times = [
            $this->field('starts_at', '开始时间（UTC）', 'datetime-local', ['step' => '1']),
            $this->field('ends_at', '结束时间（UTC）', 'datetime-local', ['step' => '1']),
        ];
        if ($this->scope->can('planner') && in_array($state, ['approved','released'], true)) {
            $centerIds = array_column($work['operations'], 'center_id');
            $resources = (new Scheduling($this->db, $this->scope))->resources();
            $machines = array_filter($resources['machines'], static fn(array $row): bool => in_array($row['center_id'], $centerIds, true));
            $forms[] = $this->requestForm('预留机台和班次', 'book-operation', [
                $this->field('operation', '工序', 'select', ['options' => $this->options($work['operations'], 'sequence_no', 'label')]),
                $this->field('machine', '机台', 'select', ['options' => $this->options($machines, 'code', 'name')]),
                ...$times,
            ], [], '工序时段须覆盖标准工时，且落在同工作中心的开放班次内。');
        }
        if ($this->scope->can('production') && in_array($state, ['released','active'], true)) {
            $pending = array_filter($work['operations'], static fn(array $row): bool => $row['actual_seconds'] === null);
            if ($pending !== []) {
                $forms[] = $this->requestForm('登记工序实绩', 'report-operation', [
                    $this->field('operation', '待登记工序', 'select', ['options' => $this->options($pending, 'sequence_no', 'label')]),
                    ...$times,
                    $this->field('labor_rate', '人工小时费率', 'number', ['min' => '0', 'step' => '0.01', 'value' => '0']),
                    $this->field('overhead_rate', '制造费用小时费率', 'number', ['min' => '0', 'step' => '0.01', 'value' => '0']),
                ]);
            }
        }
        return $forms;
    }

    private function shopFloor(array $work): array
    {
        $forms = [];
        $state = $work['state'];
        $id = (int) $work['id'];
        $locations = $this->locations();
        if (in_array($state, ['released','active'], true) && $work['materials'] !== []) {
            $forms[] = $this->requestForm('按批次领料', 'issue-material', [
                $this->field('material', '冻结用料', 'select', ['options' => $this->options($work['materials'], 'code', 'name')]),
                $this->field('lot', '投入批号', 'select', ['options' => $this->workStock($id)]),
                $this->field('location', '发料库位', 'select', ['options' => $locations]),
                $this->quantityField('quantity', '领料数量'), $this->dateField('posted_on', '领料日期'),
                $this->field('replacement', '替代批准号（选填）', 'number', ['min' => 1, 'required' => false]),
                $this->field('overage', '超额批准号（选填）', 'number', ['min' => 1, 'required' => false]),
            ]);
        }
        $issues = $this->remainingIssues($id);
        if ($issues !== [] && in_array($state, ['active','paused','waiting_quality'], true)) {
            $forms[] = $this->requestForm('退回未使用领料', 'return-material', [
                $this->field('issue', '领料记录', 'select', ['options' => $issues]),
                $this->field('location', '退回库位', 'select', ['options' => $locations]),
                $this->quantityField('quantity', '退料数量'), $this->dateField('posted_on', '退料日期'),
            ]);
        }
        if ($this->scope->can('production') && in_array($state, ['released','active'], true)) {
            $allReported = array_filter($work['operations'], static fn(array $row): bool => $row['actual_seconds'] === null) === [];
            if ($allReported && ($issues !== [] || $work['kind'] === 'rework')) {
                $fields = [
                    $this->field('lot', '完工批号', 'text', ['maxlength' => 40]),
                    $this->field('location', '完工待检库位', 'select', ['options' => $locations]),
                    $this->quantityField('quantity', '完工数量'), $this->dateField('posted_on', '完工日期'),
                ];
                if ($work['kind'] !== 'rework') {
                    $fields[] = $this->field('uses', '投入批次', 'rows', ['fields' => [
                        $this->field('issue_id', '领料记录', 'select', ['options' => $issues]),
                        $this->quantityField('quantity', '本次使用数量'),
                    ]]);
                }
                $forms[] = $this->requestForm('完工并提交质检', 'complete-work', $fields, ['id' => $id]);
            }
        }
        return $forms;
    }

    private function remainingIssues(int $id): array
    {
        $rows = $this->db->rows('SELECT a.id,l.lot_code,i.code,a.qty_milli-a.returned_milli-a.consumed_milli AS left_milli FROM work_issues a
            JOIN stock_lots l ON l.org_id=a.org_id AND l.id=a.lot_id JOIN items i ON i.id=l.item_id
            WHERE a.org_id=? AND a.work_id=? AND a.qty_milli>a.returned_milli+a.consumed_milli ORDER BY a.id', [$this->scope->org, $id]);
        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = $row['code'] . ' / ' . $row['lot_code'] . ' · ' . WebView::scaled($row['left_milli'], 3);
        }
        return $options;
    }
}
