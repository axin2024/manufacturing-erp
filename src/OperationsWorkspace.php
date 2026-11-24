<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class OperationsWorkspace extends WorkspaceScreen
{
    public function screen(string $page, ?int $id, int $before, array $parameters): array
    {
        return match ($page) {
            'purchases' => $this->purchases($id, $before),
            'sourcing' => $this->sourcing($id, $before),
            'inbound' => $this->inbound($this->requiredId($id)),
            'inventory' => $this->inventory($id, $before),
            'trace' => $this->trace($this->requiredId($id), WebInput::stringParameter($parameters, 'direction', 'downstream')),
            'work' => $this->work($id, $before, $parameters),
            'vendors' => $this->vendors($id, $before),
            'quality' => $this->quality($id, $before),
            'shipments' => $this->shipments($id, $before),
        };
    }

    private function purchases(?int $id, int $before): array
    {
        $purchasing = new Purchasing($this->db, $this->scope);
        if ($id === null) {
            $links = $this->scope->can('buyer', 'planner') ? [$this->link('供应商询报价', 'sourcing')] : [];
            return ['title' => '采购订单', 'links' => $links, 'tables' => [
                $this->listTable('采购订单', ['number' => '采购单号', 'supplier' => '供应商', 'ordered_on' => '单据日期',
                    'state' => '状态', 'issued_at' => '下单时间'], $purchasing->orders($before), 'purchases', 'number'),
            ]];
        }
        $order = $purchasing->order($id);
        $receiptLinks = $this->scope->can('buyer', 'warehouse', 'finance') ? 'inbound' : null;
        return ['title' => $order['number'], 'parent' => ['purchases', '采购订单'],
            'details' => ['供应商' => $order['supplier'], '状态' => WebView::state($order['state']), '单据日期' => $order['ordered_on']],
            'tables' => [
                $this->table('采购行', ['id' => '采购行号', 'code' => '物料', 'source_qty_milli' => '采购数量', 'source_uom' => '采购单位',
                    'factor_milli' => '换算率', 'qty_milli' => '基础数量', 'base_uom' => '基础单位', 'received_milli' => '已收',
                    'cancelled_milli' => '已止单', 'due_on' => '交期', 'proposal_id' => '计划建议号', 'quote_id' => '报价号'], $order['lines']),
                $this->table('收货单', ['number' => '收货单号', 'received_on' => '收货日期'], $order['receipts'], $receiptLinks, 'number'),
            ]];
    }

    private function sourcing(?int $id, int $before): array
    {
        $service = new Sourcing($this->db, $this->scope);
        if ($id === null) {
            $requests = $service->requests($before);
            foreach ($requests as &$request) {
                $request['state'] = $request['winning_quote_id'] === null ? 'open' : 'awarded';
            }
            unset($request);
            return ['title' => '供应商询报价', 'parent' => ['purchases', '采购订单'], 'tables' => [
                $this->listTable('询价单', ['number' => '询价单号', 'code' => '物料', 'qty_milli' => '询价数量',
                    'needed_on' => '需用日', 'state' => '状态', 'proposal_id' => '计划建议号'], $requests, 'sourcing', 'number'),
            ]];
        }
        $record = $service->details($id);
        return ['title' => $record['number'], 'parent' => ['sourcing', '供应商询报价'],
            'details' => ['需用日' => $record['needed_on'], '询价数量' => WebView::scaled($record['qty_milli'], 3),
                '状态' => WebView::state($record['winning_quote_id'] === null ? 'open' : 'awarded'), '中选报价号' => $record['winning_quote_id']],
            'tables' => [$this->table('供应商报价', ['id' => '报价号', 'number' => '报价单号', 'supplier_name' => '供应商',
                'unit_price_minor' => '基础单价', 'available_on' => '可用日', 'valid_until' => '报价有效期'], $record['quotes'])]];
    }

    private function inbound(int $id): array
    {
        $receipt = (new Purchasing($this->db, $this->scope))->receipt($id);
        $links = $this->scope->can('buyer', 'warehouse', 'finance') ? [$this->link('查看采购订单', 'purchases', (int) $receipt['order_id'])] : [];
        $rows = $this->scopeLinks($receipt['lines'], 'lot_id', 'inventory');
        $rows = $this->scopeLinks($rows, 'inspection_id', 'quality');
        return ['title' => $receipt['number'], 'links' => $links,
            'details' => ['采购单号' => $receipt['purchase_number'], '收货日期' => $receipt['received_on']],
            'tables' => [$this->table('收货与检验', ['id' => '收货行号', 'code' => '物料', 'lot_code' => '批号',
                'lot_id' => '库存记录', 'qty_milli' => '实收', 'accepted_milli' => '放行', 'rejected_milli' => '拒收',
                'pending_milli' => '待检', 'amount_minor' => '实收金额', 'inspection_id' => '检验单'], $rows)]];
    }

    private function inventory(?int $id, int $before): array
    {
        $inventory = new Inventory($this->db, $this->scope);
        if ($id === null) {
            return ['title' => '批次库存', 'tables' => [
                $this->listTable('库存批次', ['lot_code' => '批号', 'code' => '物料', 'name' => '名称', 'base_uom' => '单位',
                    'available_milli' => '可用', 'pending_milli' => '待检', 'rework_milli' => '返工', 'rejected_milli' => '拒收',
                    'received_on' => '入库日期'], $inventory->lots($before), 'inventory', 'lot_code'),
            ]];
        }
        $lot = $inventory->lot($id, $before);
        $movements = $this->paged($lot['movements'], 'id', 'inventory', $id);
        $links = $this->scope->can('quality', 'production', 'warehouse', 'planner', 'finance') ? [
            $this->link('向上追溯', 'trace', $id, ['direction' => 'upstream']),
            $this->link('向下追溯', 'trace', $id, ['direction' => 'downstream']),
        ] : [];
        return ['title' => $lot['lot_code'], 'parent' => ['inventory', '批次库存'], 'links' => $links,
            'details' => ['物料' => $lot['item_code'] . ' ' . $lot['item_name'], '入库日' => $lot['received_on'],
                '来源' => WebView::state($lot['origin_kind']), '原单位' => $lot['source_uom'],
                '原始数量' => WebView::scaled($lot['source_qty_milli'], 3), '冻结换算' => WebView::scaled($lot['factor_milli'], 3),
                '换算版本' => $lot['conversion_id'], '原始单位成本' => WebView::scaled($lot['unit_cost_minor'], 2)],
            'tables' => [
                $this->table('库位余额', ['code' => '库位', 'name' => '名称', 'quality' => '库存状态', 'qty_milli' => '数量'], $lot['balances']),
                $this->table('库存流水', ['id' => '流水号', 'location' => '库位', 'quality' => '库存状态', 'delta_milli' => '变动',
                    'event_kind' => '业务事件', 'event_id' => '事件号', 'posted_on' => '过账日期', 'actor' => '经手人'],
                    $movements['rows'], null, null, $movements['next']),
            ]];
    }

    private function trace(int $id, string $direction): array
    {
        $trace = (new Genealogy($this->db, $this->scope))->trace($id, $direction);
        $lots = $this->scopeLinks($trace['lots'], 'id', 'inventory');
        $links = $this->scopeLinks($trace['links'], 'input_lot_id', 'inventory');
        $links = $this->scopeLinks($links, 'output_lot_id', 'inventory');
        $shipments = $this->scope->can('sales', 'warehouse', 'finance', 'quality')
            ? $this->scopeLinks($trace['shipments'], 'shipment_id', 'shipments') : $trace['shipments'];
        return ['title' => $trace['lot']['lot_code'] . ($direction === 'upstream' ? ' · 上游投入' : ' · 下游去向'),
            'links' => [$this->link('批次详情', 'inventory', $id), $this->export('下载追溯 CSV', 'lot-trace', $id, $direction)],
            'tables' => [
                $this->table('相关批次', ['id' => '批次记录', 'lot_code' => '批号', 'code' => '物料', 'received_on' => '入库日',
                    'origin_kind' => '来源'], $lots),
                $this->table('投入与产出', ['input_lot_id' => '投入记录', 'input_lot' => '投入批号', 'output_lot_id' => '产出记录',
                    'output_lot' => '产出批号', 'source_kind' => '业务来源', 'source_id' => '来源编号', 'qty_milli' => '数量'], $links),
                ...($direction === 'downstream' ? [$this->table('受影响交货', ['shipment_id' => '交货记录', 'number' => '交货单号',
                    'customer' => '客户', 'lot_id' => '批次记录', 'qty_milli' => '数量', 'dispatched_on' => '发货日'], $shipments)] : []),
            ]];
    }

    private function work(?int $id, int $before, array $parameters): array
    {
        $work = new WorkOrders($this->db, $this->scope);
        if ($id === null) {
            $links = $this->scope->can('buyer', 'production', 'warehouse', 'planner', 'finance') ? [$this->link('委外加工单', 'vendors')] : [];
            return ['title' => '生产工单', 'links' => $links, 'tables' => [
                $this->listTable('生产工单', ['number' => '工单号', 'kind' => '类型', 'code' => '物料', 'qty_milli' => '计划数量',
                    'produced_milli' => '完工数量', 'due_on' => '计划完成', 'state' => '状态'], $work->orders($before), 'work', 'number'),
            ]];
        }
        $record = $work->details($id);
        $outputs = $this->scopeLinks((new Completion($this->db, $this->scope))->outputs($id), 'lot_id', 'inventory');
        if ($this->scope->can('quality', 'warehouse', 'production', 'buyer', 'finance')) {
            $outputs = $this->scopeLinks($outputs, 'inspection_id', 'quality');
        }
        $operations = $record['operations'];
        foreach ($operations as &$operation) {
            $operation['seconds_gap'] = $operation['actual_seconds'] === null ? null : (int) $operation['actual_seconds'] - (int) $operation['standard_seconds'];
        }
        unset($operation);
        $tables = [
            $this->table('工序与产能', ['id' => '工序号', 'label' => '工序名称', 'machine_code' => '机台', 'starts_at' => '计划开始（UTC）',
                'ends_at' => '计划结束（UTC）', 'standard_seconds' => '标准秒数', 'actual_seconds' => '实绩秒数', 'seconds_gap' => '差异秒数'], $operations),
            $this->table('冻结用料', ['id' => '用料记录', 'code' => '物料', 'plan_milli' => '计划', 'issued_milli' => '已领', 'returned_milli' => '已退',
                'base_uom' => '基础单位', 'standard_unit_cost_minor' => '标准单价'], $record['materials']),
            $this->table('完工批次', ['id' => '完工记录', 'lot_code' => '批号', 'lot_id' => '库存记录', 'qty_milli' => '完工量',
                'actual_minor' => '实际成本', 'reported_on' => '完工日', 'inspection_id' => '检验号', 'accepted_milli' => '放行',
                'rejected_milli' => '拒收', 'rework_milli' => '返工'], $outputs),
            $this->table('关联返工', ['number' => '返工单号', 'qty_milli' => '计划数量', 'produced_milli' => '完工数量', 'state' => '状态'],
                $record['rework_orders'], 'work', 'number'),
        ];
        $links = [];
        if ($record['parent_work_id'] !== null) {
            $links[] = $this->link('原生产工单', 'work', (int) $record['parent_work_id']);
        }
        if ($record['kind'] === 'subcontract' && $this->scope->can('buyer', 'production', 'warehouse', 'planner', 'finance')) {
            $contract = $this->db->row('SELECT id FROM subcontract_orders WHERE org_id=? AND work_id=?', [$this->scope->org, $id]);
            if ($contract !== null) {
                $links[] = $this->link('关联委外加工单', 'vendors', (int) $contract['id']);
            }
        }
        $issueBefore = isset($parameters['issue_before']) ? WebInput::integer($parameters['issue_before'], '领料分页游标') : PHP_INT_MAX;
        $costBefore = isset($parameters['cost_before']) ? WebInput::integer($parameters['cost_before'], '成本分页游标') : PHP_INT_MAX;
        $this->workLedgerTables($id, $issueBefore, $costBefore, $tables, $links);
        return ['title' => $record['number'], 'parent' => ['work', '生产工单'], 'links' => $links,
            'details' => ['物料' => $record['item_code'] . ' ' . $record['item_name'], '状态' => WebView::state($record['state']),
                '工单类型' => WebView::state($record['kind']), 'BOM 版本' => $record['bom_id'], '工艺版本' => $record['route_id'],
                '计划开工' => $record['starts_on'], '计划完成' => $record['due_on'], '来源建议' => $record['proposal_id']],
            'tables' => $tables];
    }

    private function workLedgerTables(int $id, int $issueBefore, int $costBefore, array &$tables, array &$links): void
    {
        if ($this->scope->can('production', 'warehouse', 'planner', 'finance')) {
            $flows = (new WorkMaterials($this->db, $this->scope))->flows($id, $issueBefore);
            $issues = $this->paged($flows['issues'], 'id', 'work', $id, 'issue_before', ['cost_before' => $costBefore]);
            $tables[] = $this->table('领料流水', ['id' => '领料单号', 'code' => '物料', 'lot_code' => '投入批号', 'qty_milli' => '领料',
                'returned_milli' => '已退', 'consumed_milli' => '实耗', 'actual_minor' => '实际成本', 'variance_minor' => '差异',
                'replacement_approval_id' => '替代审批', 'issued_on' => '领料日期'], $issues['rows'], null, null, $issues['next']);
            $tables[] = $this->table('补料与替代审批', ['id' => '审批号', 'kind' => '审批类型', 'code' => '物料', 'qty_milli' => '审批数量',
                'used_milli' => '已核销数量', 'reason' => '依据'], $flows['authorizations']);
            $links[] = $this->export('下载领料 CSV', 'work-issues', $id);
        }
        if ($this->scope->can('finance', 'production', 'planner')) {
            $report = (new WorkCosts($this->db, $this->scope))->report($id, $costBefore);
            $entries = $this->paged($report['entries'], 'id', 'work', $id, 'cost_before', ['issue_before' => $issueBefore]);
            $tables[] = $this->table('成本差异汇总', ['element' => '成本要素', 'actual_minor' => '实际成本', 'standard_minor' => '标准成本',
                'variance_minor' => '成本差异'], $report['summary']);
            $tables[] = $this->table('成本差异明细', ['id' => '成本记录', 'element' => '成本要素', 'source_kind' => '来源', 'source_id' => '来源编号',
                'lot_code' => '投入批号', 'actual_minor' => '实际成本', 'standard_minor' => '标准成本', 'variance_minor' => '成本差异',
                'posted_on' => '过账日'], $entries['rows'], null, null, $entries['next']);
            $links[] = $this->export('下载成本差异 CSV', 'work-variance', $id);
        }
        if ($this->scope->can('finance', 'production', 'planner', 'buyer')) {
            $review = (new WorkClosing($this->db, $this->scope))->review($id);
            $tables[] = $this->table('待解决结案事项', ['kind' => '待处理事项', 'source_id' => '来源记录', 'qty_milli' => '数量',
                'amount_minor' => '金额'], $review['blockers']);
        }
    }

    private function vendors(?int $id, int $before): array
    {
        $service = new Subcontracting($this->db, $this->scope);
        if ($id === null) {
            return ['title' => '委外加工单', 'parent' => ['work', '生产工单'], 'tables' => [
                $this->listTable('委外加工单', ['number' => '委外单号', 'supplier' => '加工方', 'work_number' => '生产工单号',
                    'contract_on' => '合同日期', 'state' => '工单状态'], $service->orders($before), 'vendors', 'number'),
            ]];
        }
        $vendor = $service->details($id);
        return ['title' => $vendor['number'], 'parent' => ['vendors', '委外加工单'], 'links' => [$this->link('生产工单', 'work', (int) $vendor['work_id'])],
            'details' => ['加工方' => $vendor['supplier_name'], '合同日期' => $vendor['contract_on']],
            'tables' => [
                $this->table('加工方物料留存', ['id' => '发料记录', 'lot_code' => '批号', 'sent_milli' => '发料', 'used_milli' => '加工耗用',
                    'returned_milli' => '已回收', 'loss_milli' => '已报损', 'outside_milli' => '加工方留存', 'last_posted_on' => '最近过账'], $vendor['materials']),
                $this->table('回收与损耗', ['id' => '记录号', 'dispatch_id' => '发料记录', 'kind' => '处置', 'qty_milli' => '数量',
                    'posted_on' => '过账日', 'reason' => '处置依据'], $vendor['recoveries']),
                $this->table('加工费对账', ['id' => '加工费号', 'lot_code' => '完工批号', 'expected_minor' => '合同加工费',
                    'invoiced_minor' => '发票金额', 'variance_minor' => '确认差异', 'vendor_invoice' => '发票号', 'matched_on' => '匹配日期'], $vendor['fees']),
            ]];
    }

    private function quality(?int $id, int $before): array
    {
        $quality = new Inspection($this->db, $this->scope);
        if ($id === null) {
            return ['title' => '质量检验', 'tables' => [
                $this->listTable('检验单', ['id' => '检验单号', 'lot_code' => '批号', 'source_kind' => '来源', 'qty_milli' => '总数量',
                    'pending_milli' => '待检', 'state' => '状态', 'version' => '版本'], $quality->cases($before), 'quality'),
            ]];
        }
        $record = $quality->details($id);
        $decisions = $this->scopeLinks($record['decisions'], 'rework_order_id', 'work');
        return ['title' => '检验单 #' . $id, 'parent' => ['quality', '质量检验'],
            'links' => [$this->link('批次与库存流水', 'inventory', (int) $record['lot_id'])],
            'details' => ['批号' => $record['lot_code'], '物料' => $record['item_name'], '状态' => WebView::state($record['state']),
                '当前版本' => $record['version'], '待检' => WebView::scaled($record['pending_milli'], 3),
                '合格' => WebView::scaled($record['accepted_milli'], 3), '拒收' => WebView::scaled($record['rejected_milli'], 3),
                '返工' => WebView::scaled($record['rework_milli'], 3)],
            'tables' => [$this->table('判定记录', ['version' => '版本', 'accepted_milli' => '合格', 'rejected_milli' => '拒收',
                'rework_milli' => '返工', 'reason' => '依据', 'measured_on' => '检验日期', 'actor' => '判定人', 'rework_order_id' => '关联返工'], $decisions)]];
    }

    private function shipments(?int $id, int $before): array
    {
        $shipping = new Shipping($this->db, $this->scope);
        if ($id === null) {
            return ['title' => '出库交付', 'tables' => [
                $this->listTable('交货单', ['number' => '交货单号', 'customer' => '客户', 'dispatched_on' => '交货日期'],
                    $shipping->shipments($before), 'shipments', 'number'),
            ]];
        }
        $shipment = $shipping->shipment($id);
        $rows = $this->scope->can('quality', 'warehouse', 'production', 'finance', 'planner', 'buyer')
            ? $this->scopeLinks($shipment['lines'], 'lot_id', 'inventory') : $shipment['lines'];
        return ['title' => $shipment['number'], 'parent' => ['shipments', '出库交付'],
            'details' => ['客户' => $shipment['customer'], '交付日期' => $shipment['dispatched_on']],
            'tables' => [$this->table('交货明细', ['order_number' => '销售单号', 'item_code' => '物料', 'lot_code' => '批号', 'lot_id' => '库存记录',
                'qty_milli' => '实发数量', 'revenue_minor' => '销售金额', 'cogs_minor' => '销售成本'], $rows)]];
    }

    private function requiredId(?int $id): int
    {
        Value::assert($id !== null, '缺少单据编号。');
        return $id;
    }
}
