<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WebOperations extends Service
{
    private const RECEIPT_ROLES = [
        'jobs.enqueue' => ['planner','warehouse'],
        'stock.opening' => ['warehouse'],
        'stock.transfer' => ['warehouse'],
        'mrp.calculate' => ['planner'],
        'purchase.receive' => ['warehouse'],
        'work.issue' => ['production','warehouse'],
        'work.complete' => ['production'],
        'quality.decide' => ['quality'],
        'shipping.dispatch' => ['warehouse'],
    ];

    public function execute(string $action, array $input): array
    {
        return match ($action) {
            'freeze-sale' => $this->freezeSale($input),
            'calculate-plan' => $this->calculatePlan($input),
            'approve-plan' => $this->approvePlan($input),
            'enqueue-job' => $this->enqueueJob($input),
            'retry-job' => $this->retryJob($input),
            'cancel-job' => $this->cancelJob($input),
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
        if ($operation === 'jobs.enqueue') {
            Value::assert($this->scope->can(BatchJobs::ROLES[$result['kind']]), '未找到此请求的有效回执。', 404);
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
        $result = (new Planning($this->db, $this->scope))->approve($id);
        return ['target' => WebView::url('planning', $id), 'message' => '计划已批准。', 'result' => $result];
    }

    private function enqueueJob(array $input): array
    {
        $kind = WebInput::stringParameter($input, 'kind');
        Value::assert(in_array($kind, ['items','bom','opening'], true), '请选择物料、BOM 或期初库存。');
        return $this->queue($input, $kind, WebInput::json($input['rows'] ?? null, '导入数据'));
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
        return ['target' => WebView::url('jobs', $id), 'message' => '未开始的行已取消。', 'result' => $result];
    }
}
