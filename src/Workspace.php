<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Workspace extends WorkspaceScreen
{
    private const MENU = [
        'home' => ['工作台', '▦', []],
        'sales' => ['销售与交付', '↗', ['sales','planner','warehouse','finance']],
        'planning' => ['物料计划', '◇', ['planner','buyer','production']],
        'purchases' => ['采购', '▤', ['buyer','planner','warehouse','finance']],
        'inventory' => ['批次库存', '□', ['warehouse','planner','buyer','production','quality','finance']],
        'work' => ['制造与委外', '⚙', ['planner','production','warehouse','quality','finance','buyer']],
        'quality' => ['质量检验', '✓', ['quality','warehouse','production','buyer','finance']],
        'shipments' => ['出库交付', '↗', ['sales','warehouse','finance','quality']],
        'finance' => ['财务与月结', '¥', ['finance']],
        'jobs' => ['批量任务', '≡', ['planner','warehouse']],
    ];

    public function menu(): array
    {
        return array_filter(self::MENU, fn(array $entry): bool => $entry[2] === [] || $this->scope->can(...$entry[2]));
    }

    public function screen(string $page, array $parameters): array
    {
        $id = isset($parameters['id']) ? WebInput::integer($parameters['id'], '对象编号') : null;
        $before = isset($parameters['before']) ? WebInput::integer($parameters['before'], '分页游标') : PHP_INT_MAX;
        $screen = match ($page) {
            'home' => $this->home(),
            'sales' => (new SalesWorkspace($this->db, $this->scope))->screen($id, $before, WebInput::optionalId($parameters, 'demand')),
            'planning' => $this->planning($id, $before, WebInput::stringParameter($parameters, 'ref'),
                WebInput::stringParameter($parameters, 'after'), WebInput::stringParameter($parameters, 'diff_after')),
            'jobs' => $this->jobs($id, $before, isset($parameters['row']) ? WebInput::integer($parameters['row'], '任务行号') : null,
                isset($parameters['after']) ? WebInput::integer($parameters['after'], '分页游标') : 0),
            'purchases', 'sourcing', 'inbound', 'inventory', 'trace', 'work', 'vendors', 'quality', 'shipments'
                => (new OperationsWorkspace($this->db, $this->scope))->screen($page, $id, $before, $parameters),
            'finance', 'receivables', 'payables', 'bill', 'journals', 'trial', 'intercompany'
                => (new FinanceWorkspace($this->db, $this->scope))->screen($page, $id, $before, $parameters),
            default => throw new BusinessError('找不到此页面。', 404),
        };
        $screen['navigation'] = match ($page) {
            'sourcing', 'inbound' => 'purchases',
            'trace' => 'inventory',
            'vendors' => 'work',
            'receivables', 'payables', 'bill', 'journals', 'trial', 'intercompany' => 'finance',
            default => $page,
        };
        return $screen;
    }

    private function home(): array
    {
        $queues = [];
        $org = $this->scope->org;
        if ($this->scope->can('sales')) {
            $queues['sales'] = ['待冻结销售单', $this->db->row("SELECT COUNT(*) AS total FROM sales_orders WHERE org_id=? AND state='draft'", [$org])];
        }
        if ($this->scope->can('planner')) {
            $queues['planning'] = ['待审核计划', $this->db->row("SELECT COUNT(*) AS total FROM mrp_runs WHERE org_id=? AND state='review'", [$org])];
        }
        if ($this->scope->can('buyer')) {
            $queues['purchases'] = ['待下单采购单', $this->db->row("SELECT COUNT(*) AS total FROM purchase_orders WHERE org_id=? AND state='draft'", [$org])];
        }
        if ($this->scope->can('production')) {
            $queues['work'] = ['待开工或执行中', $this->db->row("SELECT COUNT(*) AS total FROM work_orders WHERE org_id=? AND state IN ('released','active')", [$org])];
        }
        if ($this->scope->can('quality')) {
            $queues['quality'] = ['待判定检验单', $this->db->row("SELECT COUNT(*) AS total FROM inspection_cases WHERE org_id=? AND state<>'completed'", [$org])];
        }
        if ($this->scope->can('finance')) {
            $queues['finance'] = ['待关账期间', $this->db->row("SELECT COUNT(*) AS total FROM fiscal_periods WHERE org_id=? AND state='open'", [$org])];
        }
        if ($this->scope->can('planner', 'warehouse')) {
            $kinds = array_keys(array_filter(BatchJobs::ROLES, fn(string $role): bool => $this->scope->can($role)));
            $list = implode(',', array_fill(0, count($kinds), '?'));
            $queues['jobs'] = ['待执行任务', $this->db->row("SELECT COUNT(*) AS total FROM batch_jobs
                WHERE org_id=? AND kind IN ($list) AND state IN ('queued','running')", [$org, ...$kinds])];
        }

        $cards = [];
        foreach ($queues as $page => [$label, $row]) {
            $cards[] = ['label' => $label, 'value' => (int) $row['total'], 'url' => WebView::url($page)];
        }
        $screen = ['title' => '工作台', 'cards' => $cards, 'tables' => []];
        $demo = (new DemoScenario($this->db))->lookup($this->scope);
        if ($demo === null) {
            return $screen;
        }
        $facts = $demo['facts'];
        $objects = $demo['objects'];
        $screen['demo'] = [
            'date' => $demo['as_of'],
            'metrics' => [
                ['label' => '净缺定位销', 'value' => WebView::scaled($facts['pin_shortage_milli'], 3) . ' 件', 'url' => WebView::url('planning', $objects['planning_run_id'])],
                ['label' => '替代料成本差', 'value' => '¥' . WebView::scaled($facts['material_variance_minor'], 2), 'url' => WebView::url('work', $objects['work_id'])],
                ['label' => '首批已交付', 'value' => WebView::scaled($facts['shipped_milli'], 3) . ' 台', 'url' => WebView::url('shipments', $objects['shipment_id'])],
                ['label' => '待返工', 'value' => WebView::scaled($facts['outstanding_milli'], 3) . ' 台', 'url' => WebView::url('work', $objects['rework_id'])],
            ],
        ];
        return $screen;
    }

    private function planning(?int $id, int $before, string $ref, string $after, string $diffAfter): array
    {
        $planning = new Planning($this->db, $this->scope);
        if ($id === null) {
            $list = $this->paged($planning->runs($before), 'id', 'planning');
            $forms = $this->scope->can('planner') ? [$this->form('提交计划试算', 'calculate-plan', [
                ['name' => 'as_of', 'label' => '基准日期', 'type' => 'date', 'value' => gmdate('Y-m-d')],
                ['name' => 'horizon_on', 'label' => '需求截点', 'type' => 'date', 'value' => gmdate('Y-m-d', time() + 30 * 86_400)],
                ['name' => 'policy', 'label' => '策略版本', 'type' => 'select', 'options' => [Planning::POLICY => '按需用日净算 · dated-netting-v1']],
            ], ['request_key' => bin2hex(random_bytes(16))], '计划在后台计算。提交后可查看任务回执。')] : [];
            return ['title' => '物料需求计划', 'forms' => $forms, 'tables' => [
                $this->table('计划运行', ['id' => '运行号', 'as_of' => '基准日期', 'horizon_on' => '需求截点', 'state' => '状态',
                    'policy' => '策略', 'created_at' => '登记时间'], $list['rows'], 'planning', 'id', $list['next']),
            ]];
        }
        $run = $planning->run($id, $after, $diffAfter);
        $need = $this->paged($run['requirements'], 'ref', 'planning', $id, 'after', ['diff_after' => $diffAfter]);
        foreach ($need['rows'] as &$row) {
            $row['_links']['ref'] = WebView::url('planning', $id, ['ref' => $row['ref'], 'after' => $after, 'diff_after' => $diffAfter]);
            $row['ref'] = '查供需';
        }
        unset($row);
        $differences = $this->paged($run['differences'], 'requirement_ref', 'planning', $id, 'diff_after', ['after' => $after]);
        $tables = [
            $this->table('净需求与建议', ['code' => '物料', 'needed_on' => '需用日', 'gross_milli' => '总需求', 'stock_milli' => '现货',
                'incoming_milli' => '按期在途', 'shortage_milli' => '净缺口', 'kind' => '建议', 'proposal_id' => '建议号',
                'destination_id' => '已生成单据', 'ref' => '供需关联'], $need['rows'], null, null, $need['next']),
            $this->table('与上一次运行的差异', ['code' => '物料', 'change_kind' => '类型', 'previous_values' => '上次', 'current_values' => '本次'],
                $differences['rows'], null, null, $differences['next']),
        ];
        if ($ref !== '') {
            array_unshift($tables, $this->table('供需关联详情', ['source_kind' => '来源', 'source_id' => '记录号',
                'available_on' => '可用日期', 'qty_milli' => '冲抵数量'], $planning->pegging($id, $ref)));
        }
        $forms = $run['state'] === 'review' && $this->scope->can('planner')
            ? [$this->form('批准计划', 'approve-plan', [], ['id' => $id], '批准后可将未被占用的建议转成正式采购单或工单。')] : [];
        return ['title' => '计划运行 #' . $id, 'parent' => ['planning', '物料需求计划'], 'forms' => $forms, 'details' => [
            '状态' => WebView::state($run['state']), '基准日期' => $run['as_of'], '需求截点' => $run['horizon_on'],
            '策略' => $run['policy'], '根需求' => $run['stats']['roots'], '建议数' => $run['stats']['proposals'],
        ], 'tables' => $tables];
    }

    private function jobs(?int $id, int $before, ?int $row, int $after): array
    {
        $this->requireRole('planner', 'warehouse');
        $jobs = new BatchJobs($this->db, $this->scope);
        if ($id === null) {
            $list = $this->paged($jobs->jobs($before), 'id', 'jobs');
            $kinds = array_filter(BatchJobs::ROLES, fn(string $role, string $kind): bool => $kind !== 'mrp' && $this->scope->can($role), ARRAY_FILTER_USE_BOTH);
            $options = [];
            foreach (array_keys($kinds) as $kind) {
                $options[$kind] = WebView::state($kind);
            }
            $form = $this->form('提交批量导入', 'enqueue-job', [
                ['name' => 'kind', 'label' => '导入类型', 'type' => 'select', 'options' => $options],
                ['name' => 'rows', 'label' => 'JSON 数据（最多 1,000 行）', 'type' => 'textarea', 'placeholder' => '[{"code":"PIN","name":"标准销","uom":"件","sourcing":"purchase","standard_cost_minor":1200,"lead_days":2}]'],
            ], ['request_key' => bin2hex(random_bytes(16)), 'format_version' => 1], '每行单独记录结果。格式版本 1。');
            return ['title' => '批量任务', 'forms' => [$form], 'tables' => [
                $this->table('任务列表', ['id' => '任务号', 'kind' => '类型', 'state' => '状态', 'total_rows' => '行数',
                    'creator' => '提交人', 'updated_at' => '更新时间'], $list['rows'], 'jobs', 'id', $list['next']),
            ]];
        }
        $job = $jobs->details($id, $after);
        $list = $this->paged($job['rows'], 'row_no', 'jobs', $id, 'after');
        foreach ($list['rows'] as &$line) {
            $line['_links']['row_no'] = WebView::url('jobs', $id, ['row' => $line['row_no'], 'after' => $after]);
            if ($job['kind'] === 'mrp' && $line['state'] === 'succeeded') {
                $run = Value::fromJson($line['result'])['run_id'];
                $line['_links']['result'] = WebView::url('planning', $run);
                $line['result'] = '计划运行 #' . $run;
            }
        }
        unset($line);
        $tables = [
            $this->table('行回执', ['row_no' => '行号', 'state' => '状态', 'executor' => '执行人', 'attempts' => '尝试次数',
                'payload' => '输入', 'result' => '结果', 'error_text' => '失败原因'], $list['rows'], null, null, $list['next']),
        ];
        $forms = [];
        if ($row !== null) {
            array_unshift($tables, $this->table('第 ' . $row . ' 行的执行记录', ['ordinal' => '次数', 'actor' => '执行人', 'state' => '状态',
                'payload_digest' => '输入摘要', 'result' => '结果', 'error_text' => '失败原因', 'created_at' => '时间'], $jobs->attempts($id, $row)));
            $chosen = array_column($job['rows'], null, 'row_no')[$row] ?? null;
            if ($chosen !== null && $job['state'] !== 'cancelled' && in_array($chosen['state'], ['rejected','retryable'], true)) {
                $forms[] = $this->form('修正并重试第 ' . $row . ' 行', 'retry-job', [
                    ['name' => 'payload', 'label' => '修正后的输入', 'type' => 'textarea', 'value' => $chosen['payload']],
                ], ['id' => $id, 'row' => $row]);
            }
        }
        if ($job['state'] !== 'cancelled' && (isset($job['counts']['rejected']) || isset($job['counts']['retryable']))) {
            $forms[] = $this->form('按原输入重试失败行', 'retry-job', [], ['id' => $id], '如需修正某行，先进入该行的执行记录。');
        }
        if (isset($job['counts']['pending'])) {
            $forms[] = $this->form('取消未开始的行', 'cancel-job', [], ['id' => $id], '已成功的业务记录继续有效。');
        }
        $details = ['类型' => WebView::state($job['kind']), '状态' => WebView::state($job['state']), '总行数' => $job['total_rows'],
            '格式版本' => $job['format_version'], '提交人' => $job['creator']];
        foreach ($job['counts'] as $state => $count) {
            $details[WebView::state($state) . '行数'] = $count;
        }
        return ['title' => '批量任务 #' . $id, 'parent' => ['jobs', '批量任务'], 'details' => $details, 'forms' => $forms, 'tables' => $tables];
    }

}
