<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class VendorForms extends WorkspaceScreen
{
    public function creation(): array
    {
        if (!$this->scope->can('buyer')) {
            return [];
        }
        $eligible = $this->db->rows("SELECT w.id,w.number,i.code FROM work_orders w JOIN items i ON i.id=w.item_id
            LEFT JOIN subcontract_orders s ON s.org_id=w.org_id AND s.work_id=w.id
            WHERE w.org_id=? AND w.kind='subcontract' AND w.state IN ('draft','approved') AND s.id IS NULL ORDER BY w.id DESC", [$this->scope->org]);
        if ($eligible === []) {
            return [];
        }
        return [$this->requestForm('建立委外加工单', 'create-vendor-contract', [
            $this->field('work', '未签约工单', 'select', ['options' => $this->options($eligible, 'number', 'code')]),
            $this->field('number', '加工合同号', 'text', ['maxlength' => 40]),
            $this->field('supplier', '加工方', 'select', ['options' => $this->parties('supplier')]),
            $this->field('unit_fee', '单位加工费', 'number', ['min' => '0', 'step' => '0.01']),
            $this->dateField('posted_on', '合同日期'),
        ])];
    }

    public function forOrder(array $vendor): array
    {
        $work = (new WorkOrders($this->db, $this->scope))->details((int) $vendor['work_id']);
        $outside = [];
        foreach ($vendor['materials'] as $material) {
            if ((int) $material['outside_milli'] > 0) {
                $outside[$material['id']] = $material['code'] . ' / ' . $material['lot_code'] . ' · 留存 '
                    . WebView::scaled($material['outside_milli'], 3);
            }
        }
        $forms = [];
        if ($this->scope->can('warehouse', 'production') && in_array($work['state'], ['released','active'], true)) {
            array_push($forms, ...$this->processing($vendor, $work, $outside));
        }
        if ($outside !== [] && in_array($work['state'], ['active','paused','waiting_quality'], true)) {
            array_push($forms, ...$this->recovery($outside));
        }
        if ($this->scope->can('finance')) {
            $unmatched = array_filter($vendor['fees'], static fn(array $row): bool => $row['matched_on'] === null);
            if ($unmatched !== []) {
                $forms[] = $this->requestForm('匹配加工费发票', 'match-vendor-fee', [
                    $this->field('fee', '未匹配加工费', 'select', ['options' => $this->options($unmatched, 'lot_code')]),
                    $this->field('invoice', '加工费发票号', 'text', ['maxlength' => 40]),
                    $this->field('amount', '实际发票金额', 'number', ['min' => '0', 'step' => '0.01']),
                    $this->dateField('posted_on', '发票日期'), $this->field('reason', '确认依据'),
                ]);
            }
        }
        return $forms;
    }

    private function processing(array $vendor, array $work, array $outside): array
    {
        $id = (int) $vendor['id'];
        $locations = $this->locations();
        $workStock = (new WorkshopForms($this->db, $this->scope))->workStock((int) $work['id']);
        $forms = [];
        if ($workStock !== []) {
            $forms[] = $this->requestForm('向加工方发料', 'send-vendor-material', [
                $this->field('material', '冻结用料', 'select', ['options' => $this->options($work['materials'], 'code', 'name')]),
                $this->field('lot', '投入批号', 'select', ['options' => $workStock]),
                $this->field('location', '发料库位', 'select', ['options' => $locations]),
                $this->quantityField('quantity', '发出数量'), $this->dateField('posted_on', '发料日期'),
                $this->field('replacement', '替代批准号（选填）', 'number', ['min' => 1, 'required' => false]),
                $this->field('overage', '超额批准号（选填）', 'number', ['min' => 1, 'required' => false]),
            ], ['id' => $id]);
        }
        if ($outside !== []) {
            $forms[] = $this->requestForm('登记委外产出', 'receive-vendor-output', [
                $this->field('lot', '委外产出批号', 'text', ['maxlength' => 40]),
                $this->field('location', '收货待检库位', 'select', ['options' => $locations]),
                $this->quantityField('quantity', '收货数量'), $this->dateField('posted_on', '收货日期'),
                $this->field('uses', '投入批次', 'rows', ['fields' => [
                    $this->field('dispatch_id', '发料批次', 'select', ['options' => $outside]),
                    $this->quantityField('quantity', '本次耗用数量'),
                ]]),
            ], ['id' => $id]);
        }
        return $forms;
    }

    private function recovery(array $outside): array
    {
        $fields = [
            $this->field('dispatch', '加工方留存批次', 'select', ['options' => $outside]),
            $this->quantityField('quantity', '本次处理数量'),
            $this->dateField('posted_on', '过账日期'), $this->field('reason', '处理依据'),
        ];
        $forms = [];
        if ($this->scope->can('warehouse', 'production')) {
            $forms[] = $this->requestForm('收回委外余料', 'return-vendor-material', [
                $this->field('location', '退回库位', 'select', ['options' => $this->locations()]), ...$fields,
            ]);
        }
        if ($this->scope->can('finance')) {
            $forms[] = $this->requestForm('确认委外损耗', 'write-off-vendor-material', $fields);
        }
        return $forms;
    }
}
