<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class SetupForms extends WorkspaceScreen
{
    public function engineering(array $item, string $day): array
    {
        $effective = $this->field('effective_on', '生效日', 'date', ['value' => $day]);
        $hidden = ['item' => $item['id']];
        $forms = [];
        if ($item['sourcing'] !== 'purchase') {
            $parts = array_filter($this->items(), static fn(int $id): bool => $id !== (int) $item['id'], ARRAY_FILTER_USE_KEY);
            if ($parts !== []) {
                $forms[] = $this->form('发布 BOM 版本', 'publish-bom', [
                    $effective,
                    $this->field('lines', '组件', 'rows', ['fields' => [
                        $this->field('item', '组件物料', 'select', ['options' => $parts]),
                        $this->quantityField('quantity', '单件用量'),
                        $this->field('uom', '用量单位', 'text', ['maxlength' => 24]),
                        $this->field('loss_percent', '损耗率（%）', 'number', ['min' => '0', 'max' => '99.99', 'step' => '0.01', 'value' => '0']),
                        $this->field('alternatives', '替代物料（可多选）', 'select', ['options' => $parts, 'multiple' => true, 'required' => false]),
                    ]]),
                ], $hidden, '最多 100 个组件，每个组件最多 12 种替代料。已投产工单继续引用其原版本。');
            }
        }
        $centers = $this->options((new Scheduling($this->db, $this->scope))->resources()['centers'], 'code', 'name');
        if ($centers !== []) {
            $forms[] = $this->form('发布工艺路线版本', 'publish-route', [
                $effective,
                $this->field('steps', '工序', 'rows', ['fields' => [
                    $this->field('center', '工作中心', 'select', ['options' => $centers]),
                    $this->field('label', '工序名称', 'text', ['maxlength' => 140]),
                    $this->field('setup_minutes', '准备分钟数', 'number', ['min' => '0', 'max' => '1440', 'value' => '0']),
                    $this->field('run_seconds_per_unit', '单件加工秒数', 'number', ['min' => '1', 'max' => '86400']),
                ]]),
            ], $hidden, '按工序录入顺序执行，最多 30 道。');
        }
        return $forms;
    }

    public function capacity(array $centers): array
    {
        $departments = $this->db->rows('SELECT id,code,name FROM departments WHERE org_id=? ORDER BY code', [$this->scope->org]);
        $forms = [];
        if ($departments !== []) {
            $forms[] = $this->form('登记工作中心', 'add-center', [
                $this->field('department', '部门', 'select', ['options' => $this->options($departments, 'code', 'name')]),
                ...$this->nameFields('工作中心'),
            ]);
        }
        if ($centers !== []) {
            $center = $this->field('center', '工作中心', 'select', ['options' => $this->options($centers, 'code', 'name')]);
            $forms[] = $this->form('登记机台', 'add-machine', [$center, ...$this->nameFields('机台')]);
            $forms[] = $this->form('发布班次', 'add-shift', [
                $center, $this->field('starts_at', '开始时间（UTC）', 'datetime-local'),
                $this->field('ends_at', '结束时间（UTC）', 'datetime-local'),
            ], [], '每个班次最长 24 小时。同一工作中心的班次不能重叠。');
        }
        return $forms;
    }
}
