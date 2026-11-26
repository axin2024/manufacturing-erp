<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WorkshopActions extends Service
{
    public function execute(string $action, array $input): array
    {
        return match ($action) {
            'create-work' => $this->create($input),
            'approve-work', 'release-work', 'pause-work', 'resume-work', 'cancel-work', 'close-work' => $this->changeWork($action, $input),
            'book-operation', 'report-operation' => $this->operation($action, $input),
            'authorize-material', 'issue-material', 'return-material' => $this->material($action, $input),
            'complete-work' => $this->complete($input),
        };
    }

    private function create(array $input): array
    {
        $work = new WorkOrders($this->db, $this->scope);
        $result = $work->create(WebInput::stringParameter($input, 'request_key'), WebInput::stringParameter($input, 'number'),
            WebInput::id($input, 'item'), WebInput::quantity($input, 'quantity'), WebInput::stringParameter($input, 'engineering_on'),
            WebInput::stringParameter($input, 'starts_on'), WebInput::stringParameter($input, 'due_on'), WebInput::optionalId($input, 'proposal_id'));
        return $this->outcome($result['work_id'], '已创建工单草稿并冻结工程版本。', $result, 201);
    }

    private function changeWork(string $action, array $input): array
    {
        $id = WebInput::id($input, 'id');
        $work = new WorkOrders($this->db, $this->scope);
        [$result, $message] = match ($action) {
            'approve-work' => [$work->approve($id, WebInput::stringParameter($input, 'acting_for') === '1'), '工单已批准。'],
            'release-work' => [$work->release($id), '工单已下达。'],
            'pause-work' => [$work->pause($id, WebInput::stringParameter($input, 'reason')), '工单已挂起。'],
            'resume-work' => [$work->resume($id), '工单已恢复。'],
            'cancel-work' => [$work->cancel($id, WebInput::stringParameter($input, 'reason')), '工单已撤销。'],
            'close-work' => [(new WorkClosing($this->db, $this->scope))->close($id, WebInput::stringParameter($input, 'posted_on')), '工单已结案。'],
        };
        return $result['state'] === 'blocked' ? $this->outcome($id, '工单还有待处理事项。', $result, 409)
            : $this->outcome($id, $message, $result);
    }

    private function operation(string $action, array $input): array
    {
        $this->requireRole($action === 'book-operation' ? 'planner' : 'production');
        $operation = WebInput::id($input, 'operation');
        $work = $this->workFor('work_operations', $operation);
        $key = WebInput::stringParameter($input, 'request_key');
        $start = WebInput::timestamp($input['starts_at'] ?? null);
        $end = WebInput::timestamp($input['ends_at'] ?? null);
        if ($action === 'book-operation') {
            $result = (new Scheduling($this->db, $this->scope))->book($key, $operation, WebInput::id($input, 'machine'), $start, $end);
            $message = '排程已保留。';
        } else {
            $result = (new OperationExecution($this->db, $this->scope))->report($key, $operation, $start, $end,
                WebInput::price($input, 'labor_rate'), WebInput::price($input, 'overhead_rate'));
            $message = '工序实绩和成本已过账。';
        }
        return $this->outcome($work, $message, $result, 201);
    }

    private function material(string $action, array $input): array
    {
        $key = WebInput::stringParameter($input, 'request_key');
        $qty = WebInput::quantity($input, 'quantity');
        $service = new WorkMaterials($this->db, $this->scope);
        if ($action === 'authorize-material') {
            $this->requireRole('planner');
            $line = WebInput::id($input, 'material');
            $work = $this->workFor('work_materials', $line);
            $result = $service->authorize($key, $line, WebInput::stringParameter($input, 'kind'), WebInput::id($input, 'use_item'),
                $qty, WebInput::stringParameter($input, 'reason'));
            return $this->outcome($work, '领料授权已登记。', $result, 201);
        }
        $this->requireRole('production', 'warehouse');
        $location = WebInput::id($input, 'location');
        $date = WebInput::stringParameter($input, 'posted_on');
        if ($action === 'issue-material') {
            $line = WebInput::id($input, 'material');
            $work = $this->workFor('work_materials', $line);
            $result = $service->issue($key, $line, WebInput::id($input, 'lot'), $location, $qty, $date,
                WebInput::optionalId($input, 'replacement'), WebInput::optionalId($input, 'overage'));
            $message = '领料和成本已过账。';
        } else {
            $issue = WebInput::id($input, 'issue');
            $work = $this->workFor('work_issues', $issue);
            $result = $service->returnToStock($key, $issue, $location, $qty, $date);
            $message = '未使用领料已退回库存。';
        }
        return $this->outcome($work, $message, $result, 201);
    }

    private function complete(array $input): array
    {
        $id = WebInput::id($input, 'id');
        $usage = ($input['uses'] ?? []) === [] ? [] : WebInput::lines($input, 'uses', static fn(array $row): array => [
            'issue_id' => WebInput::id($row, 'issue_id'), 'quantity' => WebInput::quantity($row, 'quantity'),
        ], 300);
        $result = (new Completion($this->db, $this->scope))->report(WebInput::stringParameter($input, 'request_key'), $id,
            WebInput::id($input, 'location'), WebInput::stringParameter($input, 'lot'), WebInput::quantity($input, 'quantity'),
            WebInput::stringParameter($input, 'posted_on'), $usage);
        return ['target' => WebView::url('quality', $result['inspection_id']), 'message' => '完工批次已登记并进入待检。',
            'result' => $result, 'status' => 201];
    }

    private function workFor(string $table, int $id): int
    {
        $record = $this->db->row("SELECT work_id FROM {$table} WHERE org_id=? AND id=?", [$this->scope->org, $id]);
        Value::assert($record !== null, '工单明细不存在。', 404);
        return (int) $record['work_id'];
    }

    private function outcome(int $work, string $message, array $result, int $status = 200): array
    {
        return ['target' => WebView::url('work', $work), 'message' => $message, 'result' => $result, 'status' => $status];
    }
}
