<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class BatchWorker
{
    public function __construct(private readonly Database $db)
    {
    }

    public function next(): ?array
    {
        while (($candidate = $this->db->row("SELECT r.id,r.org_id FROM batch_jobs j JOIN batch_rows r
            ON r.org_id=j.org_id AND r.job_id=j.id
            WHERE j.state IN ('queued','running') AND r.state='pending' ORDER BY j.id,r.row_no LIMIT 1")) !== null) {
            $result = $this->db->transaction(fn(): ?array => $this->execute((int) $candidate['org_id'], (int) $candidate['id']));
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    private function execute(int $org, int $id): ?array
    {
        $this->db->row('SELECT id FROM organizations WHERE id=? FOR UPDATE', [$org]);
        $row = $this->db->row("SELECT r.*,j.kind FROM batch_rows r JOIN batch_jobs j ON j.org_id=r.org_id AND j.id=r.job_id
            WHERE r.org_id=? AND r.id=? AND r.state='pending' AND j.state IN ('queued','running')", [$org, $id]);
        if ($row === null) {
            return null;
        }

        $executor = (int) $row['executor_id'];
        $payload = Value::fromJson($row['payload']);
        $digest = hash('sha256', $row['payload']);
        $attempt = (int) $row['attempts'] + 1;
        $result = $message = null;
        try {
            $scope = (new Identity($this->db))->scope($executor, $org);
            Value::assert($scope->can(BatchJobs::ROLES[$row['kind']]), '执行人的角色已变更，请由有权限的人员重试。', 403);
            $result = Value::json($this->apply($scope, $row['kind'], 'batch-row-' . $id, $payload));
            $state = 'succeeded';
        } catch (BusinessError $error) {
            $message = $error->getMessage();
            $state = $error->status >= 500 ? 'retryable' : 'rejected';
        }

        $this->db->run('INSERT INTO batch_attempts (org_id,row_id,ordinal,payload_digest,state,result,error_text,actor_id)
            VALUES (?,?,?,?,?,?,?,?)', [$org, $id, $attempt, $digest, $state, $result, $message, $executor]);
        $this->db->run('UPDATE batch_rows SET state=?,result=?,error_text=?,attempts=? WHERE org_id=? AND id=?',
            [$state, $result, $message, $attempt, $org, $id]);
        $job = (int) $row['job_id'];
        $this->db->run('UPDATE batch_jobs SET state=? WHERE org_id=? AND id=?', [$this->jobState($org, $job), $org, $job]);
        return ['job_id' => $job, 'row_no' => (int) $row['row_no'], 'attempt' => $attempt, 'state' => $state];
    }

    private function apply(Scope $scope, string $kind, string $key, array $record): array
    {
        return match ($kind) {
            'items' => ['item_id' => (new MasterData($this->db, $scope))->addItem(
                $this->string($record, 'code'), $this->string($record, 'name'), $this->string($record, 'uom'),
                $this->string($record, 'sourcing'), $this->integer($record, 'standard_cost_minor'), $this->integer($record, 'lead_days'))],
            'bom' => $this->publishBom($scope, $record),
            'opening' => (new Inventory($this->db, $scope))->openingBalance($key,
                $this->itemId($scope->org, $this->string($record, 'item_code')),
                $this->locationId($scope->org, $this->string($record, 'location_code')),
                $this->string($record, 'lot'), $this->string($record, 'uom'), $this->quantity($record),
                $this->integer($record, 'unit_cost_minor'), $this->string($record, 'posted_on')),
            'mrp' => (new Planning($this->db, $scope))->calculate($key, $this->string($record, 'as_of'),
                $this->string($record, 'horizon_on'), $this->string($record, 'policy', Planning::POLICY)),
        };
    }

    private function publishBom(Scope $scope, array $record): array
    {
        $source = $record['lines'] ?? null;
        Value::assert(is_array($source) && array_is_list($source) && count($source) >= 1 && count($source) <= 100,
            'BOM 须包含 1 至 100 个组件。');
        $lines = [];
        foreach ($source as $line) {
            Value::assert(is_array($line), 'BOM 组件必须是对象。');
            $alternatives = $line['alternative_codes'] ?? [];
            Value::assert(is_array($alternatives) && array_is_list($alternatives) && count($alternatives) <= 12, '替代料列表无效。');
            $alternateIds = [];
            foreach ($alternatives as $code) {
                Value::assert(is_string($code), '替代料编码须为字符串。');
                $alternateIds[] = $this->itemId($scope->org, $code);
            }
            $lines[] = [
                'item' => $this->itemId($scope->org, $this->string($line, 'item_code')),
                'quantity' => $this->quantity($line),
                'uom' => $this->string($line, 'uom'),
                'loss_bps' => $this->integer($line, 'loss_bps', 0),
                'alternatives' => $alternateIds,
            ];
        }
        $product = $this->itemId($scope->org, $this->string($record, 'product_code'));
        return ['bom_id' => (new ProductEngineering($this->db, $scope))->publishBom($product, $this->string($record, 'effective_on'), $lines)];
    }

    private function itemId(int $org, string $code): int
    {
        $record = $this->db->row('SELECT i.id FROM org_items p JOIN items i ON i.id=p.item_id WHERE p.org_id=? AND i.code=?',
            [$org, Value::code($code, '物料编码')]);
        Value::assert($record !== null, '本账套没有指定的物料编码。', 404);
        return (int) $record['id'];
    }

    private function locationId(int $org, string $code): int
    {
        $record = $this->db->row('SELECT id FROM locations WHERE org_id=? AND code=?', [$org, Value::code($code, '库位编码')]);
        Value::assert($record !== null, '本账套没有指定的库位编码。', 404);
        return (int) $record['id'];
    }

    private function string(array $record, string $key, ?string $default = null): string
    {
        $value = $record[$key] ?? $default;
        Value::assert(is_string($value), '字段 ' . $key . ' 须为字符串。');
        return $value;
    }

    private function integer(array $record, string $key, ?int $default = null): int
    {
        $value = $record[$key] ?? $default;
        Value::assert(is_int($value), '字段 ' . $key . ' 须为整数。');
        return $value;
    }

    private function quantity(array $record): int
    {
        return Value::scaled($record['quantity'] ?? null, 3, Value::MAX_QUANTITY);
    }

    private function jobState(int $org, int $job): string
    {
        $counts = array_column($this->db->rows('SELECT state,COUNT(*) AS total FROM batch_rows WHERE org_id=? AND job_id=? GROUP BY state',
            [$org, $job]), 'total', 'state');
        if (isset($counts['pending'])) {
            return 'running';
        }
        if (!isset($counts['succeeded'])) {
            return 'failed';
        }
        return count($counts) === 1 ? 'completed' : 'partial';
    }
}
