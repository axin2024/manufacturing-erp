<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WebOperations extends Service
{
    private const RECEIPT_ROLES = [
        'jobs.enqueue' => ['planner','warehouse'],
        'jobs.retry_selection' => ['planner','warehouse'],
        'stock.opening' => ['warehouse'],
        'stock.transfer' => ['warehouse'],
        'sales.create' => ['sales'],
        'mrp.calculate' => ['planner'],
        'purchase.rfq' => ['buyer'],
        'purchase.quote' => ['buyer'],
        'purchase.create' => ['buyer'],
        'purchase.receive' => ['warehouse'],
        'work.create' => ['planner'],
        'capacity.book' => ['planner'],
        'work.operation_actual' => ['production'],
        'work.authorize_material' => ['planner'],
        'work.issue' => ['production','warehouse'],
        'work.return' => ['production','warehouse'],
        'work.complete' => ['production'],
        'vendor.create' => ['buyer'],
        'vendor.send' => ['warehouse','production'],
        'vendor.recover' => ['warehouse','production','finance'],
        'vendor.complete' => ['warehouse','production'],
        'vendor.match_fee' => ['finance'],
        'quality.decide' => ['quality'],
        'shipping.dispatch' => ['warehouse'],
        'shipping.reserve' => ['warehouse','sales'],
        'shipping.release' => ['warehouse','sales'],
        'finance.purchase_bill' => ['finance'],
        'finance.settle' => ['finance'],
        'finance.adjust' => ['finance'],
        'intercompany.transfer' => ['finance'],
    ];

    public function execute(string $action, array $input): array
    {
        return match ($action) {
            'freeze-sale' => $this->freezeSale($input),
            'calculate-plan' => $this->calculatePlan($input),
            'approve-plan' => $this->approvePlan($input),
            'delegate-approval', 'revoke-approval' => $this->changeDelegation($action, $input),
            'enqueue-job' => $this->enqueueJob($input),
            'watch-receipt' => $this->watchReceipt($input),
            'retry-job' => $this->retryJob($input),
            'retry-job-selection' => $this->retryJobSelection($input),
            'cancel-job' => $this->cancelJob($input),
            'create-purchase', 'issue-purchase', 'stop-purchase', 'receive-purchase',
            'create-rfq', 'record-quote', 'award-rfq', 'decide-inspection'
                => (new ProcurementActions($this->db, $this->scope))->execute($action, $input),
            'post-purchase-bill', 'settle-open-item', 'post-adjustment', 'close-period', 'transfer-between-organizations'
                => (new FinanceActions($this->db, $this->scope))->execute($action, $input),
            'create-sale', 'cancel-demand', 'resolve-disposition', 'open-stock', 'move-stock',
            'reserve-stock', 'release-reservation', 'dispatch-delivery'
                => (new DeliveryActions($this->db, $this->scope))->execute($action, $input),
            'create-work', 'approve-work', 'release-work', 'pause-work', 'resume-work', 'cancel-work', 'close-work',
            'book-operation', 'report-operation', 'authorize-material', 'issue-material', 'return-material', 'complete-work'
                => (new WorkshopActions($this->db, $this->scope))->execute($action, $input),
            'create-vendor-contract', 'send-vendor-material', 'return-vendor-material', 'write-off-vendor-material',
            'receive-vendor-output', 'match-vendor-fee' => (new VendorActions($this->db, $this->scope))->execute($action, $input),
            'create-organization', 'add-department', 'add-member', 'set-membership', 'add-item', 'publish-conversion',
            'add-location', 'add-party', 'publish-bom', 'publish-route', 'add-center', 'add-machine', 'add-shift'
                => (new SetupActions($this->db, $this->scope))->execute($action, $input),
            default => throw new BusinessError('找不到此操作。', 404),
        };
    }

    public function receipt(string $operation, string $key): array
    {
        Value::assert(isset(self::RECEIPT_ROLES[$operation]) && $this->scope->can(...self::RECEIPT_ROLES[$operation]),
            '没有此类回执查询。', 404);
        Value::assert((bool) preg_match('/\A[A-Za-z0-9_.:@-]{1,100}\z/', $key), '请求标识格式无效。');
        $row = $this->db->row('SELECT result, created_at FROM operation_receipts
            WHERE org_id=? AND actor_id=? AND kind=? AND request_key=?', [$this->scope->org, $this->scope->user, $operation, $key]);
        Value::assert($row !== null, '未找到此请求的有效回执。', 404);
        $result = Value::fromJson($row['result']);
        if (in_array($operation, ['jobs.enqueue', 'jobs.retry_selection'], true)) {
            Value::assert($this->scope->can(BatchJobs::ROLES[$result['kind']]), '未找到此请求的有效回执。', 404);
        }
        if ($operation === 'vendor.recover') {
            $roles = $result['kind'] === 'loss' ? ['finance'] : ['warehouse','production'];
            Value::assert($this->scope->can(...$roles), '未找到此请求的有效回执。', 404);
        }
        return ['request_key' => $key, 'operation' => $operation, 'organization_id' => $this->scope->org,
            'created_at' => $row['created_at'], 'result' => $result];
    }

    private function freezeSale(array $input): array
    {
        $id = WebInput::integer($input['id'] ?? null, '销售单编号');
        $demands = (new Sales($this->db, $this->scope))->freeze($id);
        return ['target' => WebView::url('sales', $id), 'message' => '销售需求已冻结。', 'result' => ['order_id' => $id, 'demand_ids' => $demands]];
    }

    private function calculatePlan(array $input): array
    {
        $asOf = Value::day(WebInput::stringParameter($input, 'as_of'));
        $horizon = Value::day(WebInput::stringParameter($input, 'horizon_on'));
        $policy = WebInput::stringParameter($input, 'policy', Planning::POLICY);
        return $this->queue($input, 'mrp', [['as_of' => $asOf, 'horizon_on' => $horizon, 'policy' => $policy]]);
    }

    private function approvePlan(array $input): array
    {
        $id = WebInput::integer($input['id'] ?? null, '计划运行号');
        $actingFor = WebInput::stringParameter($input, 'acting_for') === '1';
        $result = (new Planning($this->db, $this->scope))->approve($id, $actingFor);
        return ['target' => WebView::url('planning', $id), 'message' => '计划已批准。', 'result' => $result];
    }

    private function changeDelegation(string $action, array $input): array
    {
        $id = WebInput::id($input, 'id');
        $kind = WebInput::stringParameter($input, 'kind');
        $delegations = new ApprovalDelegations($this->db, $this->scope);
        if ($action === 'delegate-approval') {
            $result = $delegations->assign($kind, $id, WebInput::id($input, 'delegate_id'), WebInput::integer($input['hours'] ?? null, '委托期限', 1, 168));
            $message = '已加入代办人的工作台。';
        } else {
            $result = $delegations->revoke($kind, $id);
            $message = '审批委托已撤销。';
        }
        return ['target' => WebView::url($kind === 'plan' ? 'planning' : 'work', $id), 'message' => $message, 'result' => $result];
    }

    private function enqueueJob(array $input): array
    {
        $kind = WebInput::stringParameter($input, 'kind');
        Value::assert(in_array($kind, ['items','bom','opening'], true), '请选择物料、BOM 或期初库存。');
        return $this->queue($input, $kind, WebInput::json($input['rows'] ?? null, '导入数据'));
    }

    private function watchReceipt(array $input): array
    {
        $key = WebInput::stringParameter($input, 'request_key');
        $source = WebInput::stringParameter($input, 'source');
        $number = WebInput::stringParameter($input, 'external_number');
        $job = (new BatchJobs($this->db, $this->scope))->watchReceipt($key, WebInput::id($input, 'id'), $source, $number);
        return ['target' => WebView::url('jobs', $job['job_id']), 'message' => '外部收货回执已加入跟踪。', 'status' => 202,
            'result' => $job];
    }

    private function queue(array $input, string $kind, array $rows): array
    {
        $key = WebInput::stringParameter($input, 'request_key');
        $version = WebInput::integer($input['format_version'] ?? 1, '格式版本');
        $result = (new BatchJobs($this->db, $this->scope))->enqueue($key, $kind, $version, $rows);
        return ['target' => WebView::url('jobs', $result['job_id']), 'message' => '任务已提交，可逐行查看执行结果。',
            'status' => 202, 'result' => $result + ['request_key' => $key]];
    }

    private function retryJob(array $input): array
    {
        $id = WebInput::integer($input['id'] ?? null, '任务号');
        $row = isset($input['row']) ? WebInput::integer($input['row'], '任务行号') : null;
        $corrections = $row === null ? [] : [$row => WebInput::json($input['payload'] ?? null, '修正输入', true)];
        $result = (new BatchJobs($this->db, $this->scope))->retry($id, $corrections);
        return ['target' => WebView::url('jobs', $id), 'message' => '失败行已重新排队。', 'result' => $result];
    }

    private function cancelJob(array $input): array
    {
        $id = WebInput::integer($input['id'] ?? null, '任务号');
        $result = (new BatchJobs($this->db, $this->scope))->cancel($id);
        return ['target' => WebView::url('jobs', $id), 'message' => '等待中的行已取消。', 'result' => $result];
    }

    private function retryJobSelection(array $input): array
    {
        $id = WebInput::id($input, 'id');
        $rows = $input['rows'] ?? null;
        Value::assert(is_array($rows) && array_is_list($rows) && count($rows) >= 1 && count($rows) <= 100, '请选择 1 至 100 个任务行。');
        $selection = array_map(fn(mixed $row): int => WebInput::integer($row, '重试行号'), $rows);
        $after = WebInput::integer($input['after'] ?? 0, '任务分页游标', 0);
        $key = WebInput::stringParameter($input, 'request_key');
        $result = (new BatchJobs($this->db, $this->scope))->retrySelection($id, $key, $selection);
        $message = '重新排队 ' . $result['requeued_rows'] . ' 行，保留本次选集的处理结果。';
        $details = array_map(static function (array $line): string {
            $state = $line['previous_state'] === null ? '' : '（原状态 ' . WebView::state($line['previous_state']) . '）';
            return '第 ' . $line['row_no'] . ' 行 · ' . ($line['reason'] ?? '已排队') . $state;
        }, $result['rows']);
        $target = WebView::url('jobs', $id, $after === 0 ? [] : ['after' => $after]);
        return ['target' => $target, 'message' => $message, 'details' => $details, 'result' => $result + ['request_key' => $key]];
    }
}
