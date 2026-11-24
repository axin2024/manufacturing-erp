<?php

declare(strict_types=1);

namespace ManufacturingErp;

abstract class Service
{
    public function __construct(protected readonly Database $db, protected readonly Scope $scope)
    {
    }

    protected function requireRole(string ...$roles): void
    {
        Value::assert($this->scope->can(...$roles), '此账号没有执行该操作的权限。', 403);
    }

    protected function mutate(callable $work, string ...$roles): mixed
    {
        $this->requireRole(...$roles);
        return $this->db->transaction(function () use ($work, $roles): mixed {
            $this->db->row('SELECT id FROM organizations WHERE id=? FOR UPDATE', [$this->scope->org]);
            $actor = (new Identity($this->db))->scope($this->scope->user, $this->scope->org);
            Value::assert($actor->can(...$roles), '权限已变更，请刷新重试。', 403);
            return $work($actor->org);
        });
    }

    protected function replay(string $kind, string $key, array $payload, callable $work): array
    {
        $key = trim($key);
        Value::assert((bool) preg_match('/\A[A-Za-z0-9_.:@-]{1,100}\z/', $key), '请求标识格式无效。');
        $digest = hash('sha256', Value::json($payload));
        $prior = $this->db->row('SELECT payload_digest,result FROM operation_receipts WHERE org_id=? AND kind=? AND request_key=?',
            [$this->scope->org, $kind, $key]);
        if ($prior !== null) {
            Value::assert(hash_equals($prior['payload_digest'], $digest), '同一请求标识已用于不同的参数。', 409);
            return Value::fromJson($prior['result']);
        }
        $result = $work();
        $this->db->run('INSERT INTO operation_receipts (org_id,kind,request_key,payload_digest,result,actor_id)
            VALUES (?,?,?,?,?,?)', [$this->scope->org, $kind, $key, $digest, Value::json($result), $this->scope->user]);
        return $result;
    }

    protected function audit(string $type, int $entity, array $facts): void
    {
        $this->db->run('INSERT INTO audit_events (org_id,actor_id,event_type,entity_id,details) VALUES (?,?,?,?,?)',
            [$this->scope->org, $this->scope->user, $type, $entity, Value::json($facts)]);
    }

    protected function openPeriod(string $date): string
    {
        $period = substr(Value::day($date), 0, 7) . '-01';
        $this->db->run('INSERT INTO fiscal_periods (org_id,period_start) VALUES (?,?)
            ON DUPLICATE KEY UPDATE period_start=period_start', [$this->scope->org, $period]);
        $row = $this->db->row('SELECT state FROM fiscal_periods WHERE org_id=? AND period_start=? FOR UPDATE',
            [$this->scope->org, $period]);
        Value::assert($row['state'] === 'open', '该会计期间已关闭，请在开放期间追加调整。', 409);
        return $period;
    }

    protected function organizationItem(int $item): array
    {
        $row = $this->db->row('SELECT i.id,i.code,i.name,i.base_uom,p.sourcing,p.standard_cost_minor,p.lead_days
            FROM org_items p JOIN items i ON i.id=p.item_id WHERE p.org_id=? AND p.item_id=?',
            [$this->scope->org, $item]);
        Value::assert($row !== null, '此法人账套没有该物料。', 404);
        return $row;
    }

    protected function location(int $location): array
    {
        $row = $this->db->row('SELECT id,code,name FROM locations WHERE org_id=? AND id=?', [$this->scope->org, $location]);
        Value::assert($row !== null, '库位不存在。', 404);
        return $row;
    }

    protected function party(int $party, string $kind): array
    {
        $row = $this->db->row("SELECT id,code,name,kind FROM parties WHERE org_id=? AND id=? AND kind IN (?, 'both')",
            [$this->scope->org, $party, $kind]);
        Value::assert($row !== null, '往来单位不存在或类型不符。', 404);
        return $row;
    }

    protected function conversion(int $item, string $uom, string $day): array
    {
        $base = $this->organizationItem($item)['base_uom'];
        if (strcasecmp($base, $uom) === 0) {
            return ['id' => null, 'factor_milli' => Value::SCALE];
        }
        $version = $this->db->row('SELECT id,factor_milli FROM unit_conversions
            WHERE org_id=? AND item_id=? AND uom=? AND effective_on<=? ORDER BY effective_on DESC LIMIT 1',
            [$this->scope->org, $item, $uom, $day]);
        Value::assert($version !== null, '计量单位在此日期没有生效的换算版本。');
        return $version;
    }
}
