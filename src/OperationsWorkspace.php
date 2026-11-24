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
            'shipments' => $this->shipments($id, $before, WebInput::optionalId($parameters, 'customer')),
        };
    }

    private function purchases(?int $id, int $before): array
    {
        $purchasing = new Purchasing($this->db, $this->scope);
        if ($id === null) {
            $links = $this->scope->can('buyer', 'planner') ? [$this->link('供应商询报价', 'sourcing')] : [];
            $forms = [];
            if ($this->scope->can('buyer')) {
                $forms[] = $this->requestForm('新建采购订单', 'create-purchase', [
                    $this->field('number', '采购单号', 'text', ['maxlength' => 40]),
                    $this->field('supplier', '供应商', 'select', ['options' => $this->parties('supplier')]),
                    $this->dateField('ordered_on', '采购日期'),
                    $this->field('lines', '采购行', 'rows', ['fields' => [
                        $this->field('item', '物料', 'select', ['options' => $this->items()]),
                        $this->field('uom', '采购单位', 'text', ['maxlength' => 24]),
                        $this->quantityField('quantity', '采购数量'),
                        $this->field('unit_price', '基础单位单价', 'number', ['min' => '0', 'step' => '0.01']),
                        $this->dateField('due_on', '预计到货'),
                        $this->field('proposal_id', '计划建议号（选填）', 'number', ['min' => 1, 'required' => false]),
                        $this->field('quote_id', '中选报价号（选填）', 'number', ['min' => 1, 'required' => false]),
                    ]]),
                ]);
            }
            return ['title' => '采购订单', 'links' => $links, 'forms' => $forms, 'tables' => [
                $this->listTable('采购订单', ['number' => '采购单号', 'supplier' => '供应商', 'ordered_on' => '单据日期',
                    'state' => '状态', 'issued_at' => '下单时间'], $purchasing->orders($before), 'purchases', 'number'),
            ]];
        }
        $order = $purchasing->order($id);
        $receiptLinks = $this->scope->can('buyer', 'warehouse', 'finance') ? 'inbound' : null;
        return ['title' => $order['number'], 'parent' => ['purchases', '采购订单'],
            'forms' => $this->purchaseForms($order),
            'details' => ['供应商' => $order['supplier'], '状态' => WebView::state($order['state']), '单据日期' => $order['ordered_on']],
            'tables' => [
                $this->table('采购行', ['id' => '采购行号', 'code' => '物料', 'source_qty_milli' => '采购数量', 'source_uom' => '采购单位',
                    'factor_milli' => '换算率', 'qty_milli' => '基础数量', 'base_uom' => '基础单位', 'received_milli' => '已收',
                    'cancelled_milli' => '已止单', 'due_on' => '交期', 'proposal_id' => '计划建议号', 'quote_id' => '报价号'], $order['lines']),
                $this->table('收货单', ['number' => '收货单号', 'received_on' => '收货日期'], $order['receipts'], $receiptLinks, 'number'),
            ]];
    }

    private function purchaseForms(array $order): array
    {
        $forms = [];
        $hidden = ['id' => $order['id']];
        if ($this->scope->can('buyer') && $order['state'] === 'draft') {
            $forms[] = $this->form('下达采购订单', 'issue-purchase', [], $hidden, '下达后的未收货数量将纳入物料计划。');
        }
        if ($this->scope->can('buyer') && !in_array($order['state'], ['closed','void'], true)) {
            $forms[] = $this->form('结束采购订单', 'stop-purchase', [$this->field('reason', '结单原因')], $hidden,
                '终止剩余交货，已过账收货可继续检验和匹配发票。');
        }
        if ($this->scope->can('warehouse') && $order['state'] === 'issued') {
            $options = [];
            foreach ($order['lines'] as $row) {
                $remaining = (int) $row['qty_milli'] - (int) $row['received_milli'] - (int) $row['cancelled_milli'];
                if ($remaining > 0) {
                    $options[$row['id']] = $row['code'] . ' · 待收 ' . WebView::scaled($remaining, 3) . ' ' . $row['base_uom'];
                }
            }
            $forms[] = $this->requestForm('登记收货批次', 'receive-purchase', [
                $this->field('number', '收货单号', 'text', ['maxlength' => 40]),
                $this->dateField('received_on', '收货日期'),
                $this->field('lines', '收货批次', 'rows', ['fields' => [
                    $this->field('line_id', '采购行', 'select', ['options' => $options]),
                    $this->field('lot', '批号', 'text', ['maxlength' => 40]),
                    $this->field('location', '收货库位', 'select', ['options' => $this->locations()]),
                    $this->quantityField('quantity', '实收数量（基础单位）'),
                ]]),
            ], $hidden, '同一采购行可以拆成多个批次，每个批次独立生成检验单。');
        }
        return $forms;
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
            $forms = $this->scope->can('buyer') ? [$this->requestForm('新建供应商询价', 'create-rfq', [
                $this->field('number', '询价单号', 'text', ['maxlength' => 40]),
                $this->field('item', '询价物料', 'select', ['options' => $this->items()]),
                $this->quantityField('quantity', '询价数量'),
                $this->dateField('needed_on', '需用日期'),
                $this->field('proposal_id', '计划建议号（选填）', 'number', ['min' => 1, 'required' => false]),
            ])] : [];
            return ['title' => '供应商询报价', 'parent' => ['purchases', '采购订单'], 'forms' => $forms, 'tables' => [
                $this->listTable('询价单', ['number' => '询价单号', 'code' => '物料', 'qty_milli' => '询价数量',
                    'needed_on' => '需用日', 'state' => '状态', 'proposal_id' => '计划建议号'], $requests, 'sourcing', 'number'),
            ]];
        }
        $record = $service->details($id);
        $forms = [];
        if ($this->scope->can('buyer') && $record['winning_quote_id'] === null) {
            $forms[] = $this->requestForm('登记供应商报价', 'record-quote', [
                $this->field('supplier', '供应商', 'select', ['options' => $this->parties('supplier')]),
                $this->field('number', '报价单号', 'text', ['maxlength' => 40]),
                $this->field('unit_price', '基础单位单价', 'number', ['min' => '0', 'step' => '0.01']),
                $this->dateField('available_on', '可用日期'), $this->dateField('valid_until', '报价有效期'),
            ], ['id' => $id]);
            if ($record['quotes'] !== []) {
                $forms[] = $this->form('确认中选报价', 'award-rfq', [
                    $this->field('quote_id', '供应商报价', 'select', ['options' => $this->options($record['quotes'], 'number', 'supplier_name')]),
                    $this->dateField('decision_on', '定标日期'),
                ], ['id' => $id]);
            }
        }
        return ['title' => $record['number'], 'parent' => ['sourcing', '供应商询报价'],
            'forms' => $forms,
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
            $forms = $this->scope->can('warehouse') ? [$this->requestForm('登记期初库存', 'open-stock', [
                $this->field('item', '物料', 'select', ['options' => $this->items()]),
                $this->field('location', '库位', 'select', ['options' => $this->locations()]),
                $this->field('code', '批号', 'text', ['maxlength' => 40]),
                $this->field('uom', '源单位', 'text', ['maxlength' => 24]),
                $this->quantityField('quantity', '期初数量（源单位）'),
                $this->field('unit_cost', '基础单位成本', 'number', ['min' => '0', 'step' => '0.01']),
                $this->dateField('posted_on', '期初入账日'),
            ])] : [];
            return ['title' => '批次库存', 'forms' => $forms, 'tables' => [
                $this->listTable('库存批次', ['lot_code' => '批号', 'id' => '批次记录编号', 'code' => '物料', 'name' => '名称', 'base_uom' => '单位',
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
        return ['title' => $lot['lot_code'], 'parent' => ['inventory', '批次库存'], 'links' => $links, 'forms' => $this->moveForms($lot),
            'details' => ['批次记录编号' => $id, '物料' => $lot['item_code'] . ' ' . $lot['item_name'], '入库日' => $lot['received_on'],
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

    private function moveForms(array $lot): array
    {
        if (!$this->scope->can('warehouse')) {
            return [];
        }
        $origins = [];
        foreach ($lot['balances'] as $balance) {
            if (in_array($balance['quality'], ['available','rejected'], true)) {
                $origins[$balance['location_id']] = $balance['code'] . ' · ' . $balance['name'];
            }
        }
        if ($origins === []) {
            return [];
        }
        return [$this->requestForm('移动批次库位', 'move-stock', [
            $this->field('from', '调出库位', 'select', ['options' => $origins]),
            $this->field('to', '调入库位', 'select', ['options' => $this->locations()]),
            $this->field('quality', '库存状态', 'select', ['options' => ['available' => '可用', 'rejected' => '拒收'], 'value' => 'available']),
            $this->quantityField('quantity', '移动数量（基础单位）'), $this->dateField('posted_on', '过账日期'),
        ], ['id' => $lot['id']], '已分配库存保留在当前库位。')];
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
        $actions = new WorkshopForms($this->db, $this->scope);
        if ($id === null) {
            $links = $this->scope->can('buyer', 'production', 'warehouse', 'planner', 'finance') ? [$this->link('委外加工单', 'vendors')] : [];
            return ['title' => '生产工单', 'links' => $links, 'forms' => $actions->creation(), 'tables' => [
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
        $hasContract = false;
        if ($record['kind'] === 'subcontract' && $this->scope->can('buyer', 'production', 'warehouse', 'planner', 'finance')) {
            $contract = $this->db->row('SELECT id FROM subcontract_orders WHERE org_id=? AND work_id=?', [$this->scope->org, $id]);
            if ($contract !== null) {
                $links[] = $this->link('关联委外加工单', 'vendors', (int) $contract['id']);
                $hasContract = true;
            } elseif ($this->scope->can('buyer') && in_array($record['state'], ['draft','approved'], true)) {
                $links[] = $this->link('登记加工方及费用', 'vendors');
            }
        }
        $issueBefore = isset($parameters['issue_before']) ? WebInput::integer($parameters['issue_before'], '领料分页游标') : PHP_INT_MAX;
        $costBefore = isset($parameters['cost_before']) ? WebInput::integer($parameters['cost_before'], '成本分页游标') : PHP_INT_MAX;
        $this->workLedgerTables($id, $issueBefore, $costBefore, $tables, $links);
        return ['title' => $record['number'], 'parent' => ['work', '生产工单'], 'links' => $links, 'forms' => $actions->forOrder($record, $hasContract),
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
        $actions = new VendorForms($this->db, $this->scope);
        if ($id === null) {
            return ['title' => '委外加工单', 'parent' => ['work', '生产工单'], 'forms' => $actions->creation(), 'tables' => [
                $this->listTable('委外加工单', ['number' => '委外单号', 'supplier' => '加工方', 'work_number' => '生产工单号',
                    'contract_on' => '合同日期', 'state' => '工单状态'], $service->orders($before), 'vendors', 'number'),
            ]];
        }
        $vendor = $service->details($id);
        return ['title' => $vendor['number'], 'parent' => ['vendors', '委外加工单'], 'links' => [$this->link('生产工单', 'work', (int) $vendor['work_id'])],
            'forms' => $actions->forOrder($vendor),
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
        $forms = [];
        if ($this->scope->can('quality') && $record['state'] !== 'completed') {
            $quantities = [$this->quantityField('accepted', '放行数量', true), $this->quantityField('rejected', '拒收数量', true)];
            if ($record['source_kind'] !== 'purchase') {
                $quantities[] = $this->quantityField('rework', '返工数量', true);
            }
            $forms[] = $this->requestForm('提交检验判定', 'decide-inspection', [
                ...$quantities, $this->dateField('measured_on', '检验日期'), $this->field('reason', '检验依据'),
            ], ['id' => $id, 'version' => $record['version']], '可分批判定。每次提交须核对当期版本和剩余待检数量。');
        }
        return ['title' => '检验单 #' . $id, 'parent' => ['quality', '质量检验'],
            'forms' => $forms,
            'links' => [$this->link('批次与库存流水', 'inventory', (int) $record['lot_id'])],
            'details' => ['批号' => $record['lot_code'], '物料' => $record['item_name'], '状态' => WebView::state($record['state']),
                '当前版本' => $record['version'], '待检' => WebView::scaled($record['pending_milli'], 3),
                '合格' => WebView::scaled($record['accepted_milli'], 3), '拒收' => WebView::scaled($record['rejected_milli'], 3),
                '返工' => WebView::scaled($record['rework_milli'], 3)],
            'tables' => [$this->table('判定记录', ['version' => '版本', 'accepted_milli' => '合格', 'rejected_milli' => '拒收',
                'rework_milli' => '返工', 'reason' => '依据', 'measured_on' => '检验日期', 'actor' => '判定人', 'rework_order_id' => '关联返工'], $decisions)]];
    }

    private function shipments(?int $id, int $before, ?int $customer): array
    {
        $shipping = new Shipping($this->db, $this->scope);
        if ($id === null) {
            return ['title' => '出库交付', ...$this->shipmentForms($customer), 'tables' => [
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

    private function shipmentForms(?int $customer): array
    {
        if (!$this->scope->can('warehouse')) {
            return [];
        }
        $org = $this->scope->org;
        if ($customer === null) {
            $rows = $this->db->rows("SELECT DISTINCT p.id,p.code,p.name FROM stock_reservations r
                JOIN sales_demands d ON d.org_id=r.org_id AND d.id=r.demand_id JOIN sales_lines l ON l.org_id=d.org_id AND l.id=d.line_id
                JOIN sales_orders s ON s.org_id=l.org_id AND s.id=l.order_id JOIN parties p ON p.org_id=s.org_id AND p.id=s.customer_id
                WHERE r.org_id=? AND r.remaining_milli>0 AND d.state='released' ORDER BY p.code", [$org]);
            $links = [];
            foreach ($rows as $row) {
                $links[] = $this->link('发货给 ' . $row['name'], 'shipments', null, ['customer' => $row['id']]);
            }
            return ['links' => $links];
        }
        $party = $this->party($customer, 'customer');
        $rows = $this->db->rows("SELECT r.id,r.remaining_milli,l.lot_code,s.number,d.due_on FROM stock_reservations r
            JOIN sales_demands d ON d.org_id=r.org_id AND d.id=r.demand_id JOIN sales_lines a ON a.org_id=d.org_id AND a.id=d.line_id
            JOIN sales_orders s ON s.org_id=a.org_id AND s.id=a.order_id JOIN stock_lots l ON l.org_id=r.org_id AND l.id=r.lot_id
            WHERE r.org_id=? AND s.customer_id=? AND d.state='released' AND r.remaining_milli>0 ORDER BY d.due_on,r.id", [$org, $customer]);
        if ($rows === []) {
            return [];
        }
        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = $row['number'] . ' / ' . $row['lot_code'] . ' · ' . $row['due_on']
                . ' · 待发 ' . WebView::scaled($row['remaining_milli'], 3);
        }
        return ['forms' => [$this->requestForm('发货给 ' . $party['name'], 'dispatch-delivery', [
            $this->field('number', '交货单号', 'text', ['maxlength' => 40]), $this->dateField('dispatched_on', '交货日期'),
            $this->field('lines', '交货分配行', 'rows', ['fields' => [
                $this->field('reservation_id', '待发分配记录', 'select', ['options' => $options]),
                $this->quantityField('quantity', '实发数量'),
            ]]),
        ], ['customer' => $customer], '发货将扣减库存并登记该客户的应收。')]];
    }

    private function requiredId(?int $id): int
    {
        Value::assert($id !== null, '缺少单据编号。');
        return $id;
    }
}
