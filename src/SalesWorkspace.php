<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class SalesWorkspace extends WorkspaceScreen
{
    public function screen(?int $id, int $before, ?int $demandId): array
    {
        $sales = new Sales($this->db, $this->scope);
        if ($id === null) {
            $forms = $this->scope->can('sales') ? [$this->creationForm()] : [];
            return ['title' => '销售订单', 'forms' => $forms, 'tables' => [
                $this->listTable('订单', ['number' => '销售单号', 'customer' => '客户名称', 'state' => '状态', 'created_at' => '登记时间'],
                    $sales->list($before), 'sales', 'number'),
            ]];
        }
        $order = $sales->order($id);
        $demands = $order['demands'];
        foreach ($demands as &$row) {
            $row['outstanding_milli'] = (int) $row['qty_milli'] - (int) $row['dispatched_milli'];
            $row['_links']['id'] = WebView::url('sales', $id, ['demand' => $row['id']]);
        }
        unset($row);
        $chosen = $demandId === null ? null : (array_column($demands, null, 'id')[$demandId] ?? null);
        Value::assert($demandId === null || $chosen !== null, '销售交付需求不存在。', 404);
        $dispositions = $this->db->rows('SELECT x.* FROM demand_dispositions x JOIN sales_demands d ON d.id=x.demand_id AND d.org_id=x.org_id
            JOIN sales_lines l ON l.id=d.line_id AND l.org_id=d.org_id WHERE x.org_id=? AND l.order_id=? ORDER BY x.id', [$this->scope->org, $id]);
        $forms = $this->orderForms($order, $chosen, $dispositions);
        $tables = [];
        $links = [];
        if ($chosen !== null) {
            $reservations = (new Shipping($this->db, $this->scope))->reservations((int) $chosen['id']);
            [$reservationForms, $links] = $this->reservationForms($order, $chosen, $reservations);
            array_push($forms, ...$reservationForms);
            $tables[] = $this->table('需求 #' . $chosen['id'] . ' 的库存分配', ['id' => '分配编号', 'lot_code' => '批号',
                'location_code' => '库位', 'remaining_milli' => '未发数量'], $reservations);
        }
        return ['title' => $order['number'], 'parent' => ['sales', '销售订单'], 'forms' => $forms, 'links' => $links, 'details' => [
            '客户' => $order['customer'], '状态' => WebView::state($order['state']), '登记时间（UTC）' => $order['created_at'],
            '冻结时间（UTC）' => $order['frozen_at'],
        ], 'tables' => [
            ...$tables,
            $this->table('交货计划', ['id' => '需求编号', 'line_no' => '销售行次', 'delivery_no' => '交货批次', 'code' => '物料',
                'due_on' => '交期', 'qty_milli' => '需求', 'base_uom' => '单位', 'unit_price_minor' => '单价',
                'allocated_milli' => '已分配', 'dispatched_milli' => '已交付', 'outstanding_milli' => '未交付', 'state' => '状态', 'version' => '版本'], $demands),
            $this->table('变更处置', ['id' => '处置编号', 'demand_id' => '需求编号', 'source_kind' => '来源',
                'source_id' => '关联单据', 'qty_milli' => '数量', 'state' => '状态', 'reason' => '原因'], $dispositions),
        ]];
    }

    private function creationForm(): array
    {
        return $this->requestForm('新建销售订单', 'create-sale', [
            $this->field('number', '销售单号', 'text', ['maxlength' => 40]),
            $this->field('customer', '客户', 'select', ['options' => $this->parties('customer')]),
            $this->field('deliveries', '分批交货安排', 'rows', ['fields' => [
                $this->field('line', '销售行号', 'number', ['min' => 1, 'max' => 100, 'value' => 1]),
                $this->field('item', '物料', 'select', ['options' => $this->items()]),
                $this->field('unit_price', '基础单位单价', 'number', ['min' => '0', 'step' => '0.01']),
                $this->dateField('due_on', '交付日期'),
                $this->quantityField('quantity', '本批交付数量'),
            ]]),
        ], [], '同一销售行号可分为多批交货，物料和单价须一致。各批次的数量自动合计。');
    }

    private function orderForms(array $order, ?array $demand, array $dispositions): array
    {
        $forms = [];
        if ($order['state'] === 'draft' && $this->scope->can('sales')) {
            $forms[] = $this->form('冻结销售需求', 'freeze-sale', [], ['id' => $order['id']], '审核每一批交货日期与数量后冻结，物料计划将纳入这些需求。');
        }
        if ($this->scope->can('sales') && $demand !== null && in_array($demand['state'], ['draft','released'], true)) {
            $forms[] = $this->form('取消需求 #' . $demand['id'], 'cancel-demand', [$this->field('reason', '取消原因')],
                ['id' => $demand['id']], '剩余库存分配将释放，已经承诺的供给会生成处置事项。');
        }
        if ($this->scope->can('planner')) {
            $open = array_filter($dispositions, static fn(array $row): bool => $row['state'] === 'open');
            if ($open !== []) {
                $forms[] = $this->form('登记处置结果', 'resolve-disposition', [
                    $this->field('id', '待处理事项', 'select', ['options' => $this->options($open, 'id', 'reason')]),
                    $this->field('reason', '处置说明'),
                ]);
            }
        }
        return $forms;
    }

    private function reservationForms(array $order, array $demand, array $reservations): array
    {
        $forms = $links = [];
        $open = array_filter($reservations, static fn(array $row): bool => (int) $row['remaining_milli'] > 0);
        if ($this->scope->can('warehouse') && $open !== []) {
            $links[] = $this->link('按此客户登记交付', 'shipments', null, ['customer' => $order['customer_id']]);
        }
        if (!$this->scope->can('sales', 'warehouse')) {
            return [$forms, $links];
        }
        $remaining = (int) $demand['qty_milli'] - (int) $demand['allocated_milli'];
        if ($demand['state'] === 'released' && $remaining > 0) {
            $options = $this->availableBins((int) $demand['id']);
            if ($options !== []) {
                $forms[] = $this->requestForm('分配合格库存', 'reserve-stock', [
                    $this->field('stock_bin', '批号与可分配库位', 'select', ['options' => $options]),
                    $this->quantityField('quantity', '分配数量') + ['max' => WebView::scaled($remaining, 3)],
                ], ['demand' => $demand['id']]);
            }
        }
        if ($open !== []) {
            $choices = [];
            foreach ($open as $row) {
                $choices[$row['id']] = $row['lot_code'] . ' / ' . $row['location_code'] . ' · 余 ' . WebView::scaled($row['remaining_milli'], 3);
            }
            $forms[] = $this->requestForm('解除未发库存分配', 'release-reservation', [
                $this->field('id', '分配记录', 'select', ['options' => $choices]), $this->field('reason', '解除分配原因'),
            ]);
        }
        return [$forms, $links];
    }

    private function availableBins(int $demand): array
    {
        $rows = $this->db->rows("SELECT b.lot_id,b.location_id,l.lot_code,w.code,b.qty_milli-
            COALESCE((SELECT SUM(r.remaining_milli) FROM stock_reservations r
                WHERE r.org_id=b.org_id AND r.lot_id=b.lot_id AND r.location_id=b.location_id),0) AS free_milli
            FROM sales_demands d JOIN sales_lines s ON s.org_id=d.org_id AND s.id=d.line_id
            JOIN stock_lots l ON l.org_id=s.org_id AND l.item_id=s.item_id JOIN stock_balances b ON b.org_id=l.org_id AND b.lot_id=l.id
            JOIN locations w ON w.org_id=b.org_id AND w.id=b.location_id
            WHERE d.org_id=? AND d.id=? AND b.quality='available' AND b.qty_milli>0
            HAVING free_milli>0 ORDER BY l.received_on,l.id,w.code", [$this->scope->org, $demand]);
        $options = [];
        foreach ($rows as $row) {
            $options[$row['lot_id'] . ':' . $row['location_id']] = $row['lot_code'] . ' / ' . $row['code'] . ' · ' . WebView::scaled($row['free_milli'], 3);
        }
        return $options;
    }
}
