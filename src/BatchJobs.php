<?php

declare(strict_types=1);

namespace ManufacturingErp;

use JsonException;

final class BatchJobs extends Service
{
    public const ROLES = ['items' => 'planner', 'bom' => 'planner', 'opening' => 'warehouse', 'mrp' => 'planner',
        'receipt_match' => 'warehouse'];
    public const STATES = ['queued','running','waiting_external','completed','partial','failed','cancelled'];

    public function enqueue(string $key, string $kind, int $version, array $rows): array
    {
        $role = self::ROLES[$kind] ?? null;
        Value::assert($role !== null && $version === 1, '导入类型或格式版本不受支持。');
        Value::assert(array_is_list($rows) && count($rows) >= 1 && count($rows) <= (in_array($kind, ['mrp','receipt_match'], true) ? 1 : 1000),
            '批任务行数超出范围。');
        foreach ($rows as $record) {
            $this->checkPayload($record);
        }
        if ($kind === 'receipt_match') {
            $notice = $rows[0];
            Value::assert(is_int($notice['order_id'] ?? null) && $notice['order_id'] > 0
                && is_string($notice['source'] ?? null) && is_string($notice['external_number'] ?? null), '外部回执跟踪参数无效。');
            $rows = [['order_id' => $notice['order_id']] + ReceiptNotice::identity($notice['source'], $notice['external_number'])];
        }
        return $this->mutate(fn(int $org): array => $this->replay('jobs.enqueue', $key, [$kind, $version, $rows],
            function () use ($org, $kind, $version, $rows): array {
                if ($kind === 'receipt_match') {
                    $purchase = (new Purchasing($this->db, $this->scope))->order($rows[0]['order_id']);
                    Value::assert(in_array($purchase['state'], ['issued','received'], true), '须使用已下达或已收货的采购单。', 409);
                }
                $id = $this->db->insert('INSERT INTO batch_jobs (org_id,kind,format_version,total_rows,created_by) VALUES (?,?,?,?,?)',
                    [$org, $kind, $version, count($rows), $this->scope->user]);
                $insert = [];
                foreach ($rows as $index => $row) {
                    $insert[] = [$org, $id, $index + 1, Value::json($row), $this->scope->user];
                }
                $this->db->insertRows('INSERT INTO batch_rows (org_id,job_id,row_no,payload,executor_id)', $insert);
                $result = ['job_id' => $id, 'kind' => $kind, 'state' => 'queued', 'total_rows' => count($rows)];
                if ($kind === 'receipt_match') {
                    $result += $rows[0];
                }
                $this->audit('jobs.queued', $id, $result);
                return $result;
            }), $role);
    }

    public function watchReceipt(string $key, int $order, string $source, string $number): array
    {
        return $this->enqueue($key, 'receipt_match', 1, [['order_id' => $order, 'source' => $source, 'external_number' => $number]]);
    }

    public function retry(int $id, array $corrections): array
    {
        $role = self::ROLES[$this->job($id)['kind']];
        Value::assert(count($corrections) <= 1000, '一次重试的修改数超出范围。');
        foreach ($corrections as $row => $replacement) {
            Value::assert(is_int($row) && $row > 0, '重试行号无效。');
            $this->checkPayload($replacement);
        }
        return $this->mutate(function (int $org) use ($id, $corrections): array {
            $job = $this->job($id);
            Value::assert($job['state'] !== 'cancelled', '已取消的任务须另建新任务。', 409);
            $retries = $this->db->rows("SELECT id,row_no,payload,attempts FROM batch_rows
                WHERE org_id=? AND job_id=? AND state IN ('rejected','retryable') ORDER BY row_no", [$org, $id]);
            Value::assert($retries !== [], '没有可以重试的任务行。', 409);
            $known = array_column($retries, null, 'row_no');
            Value::assert(array_diff_key($corrections, $known) === [], '只可修改失败行，成功或尚未执行的行保持原输入。', 409);
            Value::assert($job['kind'] !== 'receipt_match' || $corrections === [], '外部回执标识不可改写，请另建跟踪任务。', 409);
            $rows = $corrections === [] ? $known : array_intersect_key($known, $corrections);
            foreach ($rows as $no => $line) {
                Value::assert((int) $line['attempts'] < 100, '任务行已达尝试次数上限，请另建新任务。', 409);
                $payload = array_key_exists($no, $corrections) ? Value::json($corrections[$no]) : $line['payload'];
                $this->db->run("UPDATE batch_rows SET payload=?,executor_id=?,state='pending',error_text=NULL,result=NULL WHERE org_id=? AND id=?",
                    [$payload, $this->scope->user, $org, $line['id']]);
            }
            $this->db->run("UPDATE batch_jobs SET state='queued' WHERE org_id=? AND id=?", [$org, $id]);
            $this->audit('jobs.requeued', $id, ['row_numbers' => array_keys($rows), 'corrected_rows' => array_keys($corrections)]);
            return ['job_id' => $id, 'state' => 'queued', 'requeued_rows' => count($rows)];
        }, $role);
    }

    public function cancel(int $id): array
    {
        $role = self::ROLES[$this->job($id)['kind']];
        return $this->mutate(function (int $org) use ($id): array {
            $job = $this->job($id);
            if ($job['state'] === 'cancelled') {
                return ['job_id' => $id, 'state' => 'cancelled'];
            }
            $removed = $this->db->run("UPDATE batch_rows SET state='cancelled' WHERE org_id=? AND job_id=? AND state IN ('pending','waiting_external')",
                [$org, $id])->rowCount();
            Value::assert($removed > 0, '该任务没有可以取消的等待行。', 409);
            $this->db->run("UPDATE batch_jobs SET state='cancelled' WHERE org_id=? AND id=?", [$org, $id]);
            $this->audit('jobs.cancelled', $id, ['cancelled_rows' => $removed]);
            return ['job_id' => $id, 'state' => 'cancelled', 'cancelled_rows' => $removed];
        }, $role);
    }

    public function jobs(int $before = PHP_INT_MAX, string $state = '', string $since = '', string $beforeAt = ''): array
    {
        Value::assert($state === '' || in_array($state, self::STATES, true), '批任务状态无效。');
        $kinds = [];
        foreach (self::ROLES as $kind => $role) {
            if ($this->scope->can($role)) {
                $kinds[] = $kind;
            }
        }
        if ($kinds === []) {
            return [];
        }
        $list = implode(',', array_fill(0, count($kinds), '?'));
        $where = ["j.org_id=?", "j.kind IN ($list)"];
        $parameters = [$this->scope->org, ...$kinds];
        if ($state !== '') {
            $where[] = 'j.state=?';
            $parameters[] = $state;
        }
        if ($since !== '') {
            $where[] = 'j.updated_at>=?';
            $parameters[] = Value::day($since) . ' 00:00:00';
        }
        if ($beforeAt !== '') {
            Value::assert((bool) preg_match('/\A[0-9-]{10} [0-9:]{8}\.[0-9]{6}\z/', $beforeAt), '任务游标时间无效。');
            Value::timestamp(substr($beforeAt, 0, 19));
            $where[] = '(j.updated_at<? OR (j.updated_at=? AND j.id<?))';
            array_push($parameters, $beforeAt, $beforeAt, $before);
        } else {
            $where[] = 'j.id<?';
            $parameters[] = $before;
        }
        $filters = implode(' AND ', $where);
        return $this->db->rows("SELECT j.*,u.name AS creator FROM batch_jobs j JOIN users u ON u.id=j.created_by
            WHERE $filters ORDER BY j.updated_at DESC,j.id DESC LIMIT 51", $parameters);
    }

    public function details(int $id, int $after = 0): array
    {
        $job = $this->job($id);
        $this->requireRole(self::ROLES[$job['kind']]);
        $org = $this->scope->org;
        $job['counts'] = array_column($this->db->rows('SELECT state,COUNT(*) AS count FROM batch_rows WHERE org_id=? AND job_id=?
            GROUP BY state', [$org, $id]), 'count', 'state');
        $job['rows'] = $this->db->rows('SELECT r.*,u.name AS executor FROM batch_rows r JOIN users u ON u.id=r.executor_id
            WHERE r.org_id=? AND r.job_id=? AND r.row_no>? ORDER BY r.row_no LIMIT 51', [$org, $id, $after]);
        return $job;
    }

    public function attempts(int $id, int $row): array
    {
        $job = $this->job($id);
        $this->requireRole(self::ROLES[$job['kind']]);
        return $this->db->rows('SELECT a.*,u.name AS actor FROM batch_attempts a JOIN users u ON u.id=a.actor_id
            JOIN batch_rows r ON r.org_id=a.org_id AND r.id=a.row_id
            WHERE a.org_id=? AND r.job_id=? AND r.row_no=? ORDER BY a.ordinal', [$this->scope->org, $id, $row]);
    }

    private function job(int $id): array
    {
        $job = $this->db->row('SELECT j.*,u.name AS creator FROM batch_jobs j JOIN users u ON u.id=j.created_by
            WHERE j.org_id=? AND j.id=?', [$this->scope->org, $id]);
        Value::assert($job !== null, '批任务不存在。', 404);
        return $job;
    }

    private function checkPayload(mixed $row): void
    {
        Value::assert(is_array($row) && !array_is_list($row), '单行输入必须是 JSON 对象。');
        try {
            $json = Value::json($row);
            Value::assert(strlen($json) <= 16_384, '单行输入不得超过 16 KiB。');
            Value::fromJson($json);
        } catch (JsonException) {
            throw new BusinessError('任务行包含无法读取的字符或 JSON 层级超限。');
        }
    }
}
