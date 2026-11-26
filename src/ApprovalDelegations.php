<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class ApprovalDelegations extends Service
{
    public const READ_ROLES = [
        'plan' => ['planner', 'buyer', 'production'],
        'work' => ['planner', 'production', 'warehouse', 'quality', 'finance', 'buyer'],
    ];

    public function candidates(string $kind): array
    {
        $this->requireRole('planner');
        $roles = [...$this->readRoles($kind), 'admin'];
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        return $this->db->rows("SELECT DISTINCT u.id,u.name FROM memberships m JOIN users u ON u.id=m.user_id
            WHERE m.org_id=? AND m.enabled=1 AND u.enabled=1 AND u.id<>? AND m.role IN ($placeholders)
            ORDER BY u.name,u.id", [$this->scope->org, $this->scope->user, ...$roles]);
    }

    public function assign(string $kind, int $id, int $delegate, int $hours): array
    {
        $roles = $this->readRoles($kind);
        Value::assert($delegate !== $this->scope->user && $hours >= 1 && $hours <= 168, '请选择其他代办人及 1 至 168 小时的期限。');
        return $this->mutate(function (int $org) use ($kind, $id, $delegate, $hours, $roles): array {
            $this->assertPending($kind, $id);
            $member = (new Identity($this->db))->scope($delegate, $org);
            Value::assert($member->can(...$roles), '代办人没有该类单据的查看权限。', 403);
            $this->db->run("INSERT INTO approval_delegations (org_id,kind,object_id,assigned_by,assigned_to,expires_at)
                VALUES (?,?,?,?,?,TIMESTAMPADD(HOUR,?,UTC_TIMESTAMP(6))) AS incoming
                ON DUPLICATE KEY UPDATE assigned_by=incoming.assigned_by,assigned_to=incoming.assigned_to,
                    expires_at=incoming.expires_at,state='pending'", [$org, $kind, $id, $this->scope->user, $delegate, $hours]);
            $record = $this->record($kind, $id);
            $result = ['kind' => $kind, 'object_id' => $id, 'assigned_to' => $delegate, 'expires_at' => $record['expires_at']];
            $this->audit('approval.delegated', $id, $result);
            return $result;
        }, 'planner');
    }

    public function revoke(string $kind, int $id): array
    {
        $this->readRoles($kind);
        return $this->mutate(function (int $org) use ($kind, $id): array {
            $affected = $this->db->run("UPDATE approval_delegations SET state='revoked'
                WHERE org_id=? AND kind=? AND object_id=? AND state='pending'", [$org, $kind, $id])->rowCount();
            Value::assert($affected === 1, '没有待撤销的审批委托。', 409);
            $result = ['kind' => $kind, 'object_id' => $id, 'state' => 'revoked'];
            $this->audit('approval.revoked', $id, $result);
            return $result;
        }, 'planner');
    }

    public function approve(string $kind, int $id, bool $actingFor, callable $decision): array
    {
        $roles = $actingFor ? $this->readRoles($kind) : ['planner'];
        return $this->mutate(function (int $org) use ($kind, $id, $actingFor, $decision): array {
            $facts = [];
            if ($actingFor) {
                $record = $this->record($kind, $id);
                Value::assert($record !== null && $this->availableToActor($kind, $record), '该单据没有可用的本人审批委托。', 403);
                $this->assertPending($kind, $id);
                $facts = ['delegated_by' => (int) $record['assigned_by'], 'delegate_id' => $this->scope->user];
            }
            $result = $decision($org, $facts);
            $this->db->run("UPDATE approval_delegations SET state=? WHERE org_id=? AND kind=? AND object_id=? AND state='pending'",
                [$actingFor ? 'completed' : 'revoked', $org, $kind, $id]);
            return $result + $facts;
        }, ...$roles);
    }

    public function record(string $kind, int $id): ?array
    {
        $this->requireRole(...$this->readRoles($kind));
        return $this->db->row("SELECT d.*,u.name AS delegate_name,a.name AS assigner_name,
                d.expires_at>UTC_TIMESTAMP(6) AND a.enabled=1 AND EXISTS (
                    SELECT 1 FROM memberships m WHERE m.org_id=d.org_id AND m.user_id=d.assigned_by
                        AND m.enabled=1 AND m.role IN ('admin','planner')) AS current_grant
            FROM approval_delegations d JOIN users u ON u.id=d.assigned_to JOIN users a ON a.id=d.assigned_by
            WHERE d.org_id=? AND d.kind=? AND d.object_id=?", [$this->scope->org, $kind, $id]);
    }

    public function availableToActor(string $kind, array $record): bool
    {
        return $record['state'] === 'pending' && (bool) $record['current_grant']
            && (int) $record['assigned_to'] === $this->scope->user && $this->scope->can(...$this->readRoles($kind));
    }

    public function inbox(string $kind, int $before = PHP_INT_MAX): array
    {
        if (!$this->scope->can(...$this->readRoles($kind))) {
            return [];
        }
        [$table, $condition] = $this->pendingSource($kind);
        return $this->db->rows("SELECT d.object_id AS id,d.expires_at,u.name AS delegated_by
            FROM approval_delegations d JOIN $table o ON o.org_id=d.org_id AND o.id=d.object_id
            JOIN users u ON u.id=d.assigned_by AND u.enabled=1
            WHERE d.org_id=? AND d.kind=? AND d.assigned_to=? AND d.state='pending' AND d.expires_at>UTC_TIMESTAMP(6)
                AND d.object_id<? AND $condition AND EXISTS (SELECT 1 FROM memberships m WHERE m.org_id=d.org_id
                    AND m.user_id=d.assigned_by AND m.enabled=1 AND m.role IN ('admin','planner'))
            ORDER BY d.object_id DESC LIMIT 51", [$this->scope->org, $kind, $this->scope->user, $before]);
    }

    public function isPending(string $kind, int $id): bool
    {
        [$table, $condition] = $this->pendingSource($kind);
        return $this->db->row("SELECT o.id FROM $table o WHERE o.org_id=? AND o.id=? AND $condition", [$this->scope->org, $id]) !== null;
    }

    private function assertPending(string $kind, int $id): void
    {
        Value::assert($this->isPending($kind, $id), '单据不存在或已离开待审批状态。', 409);
    }

    private function pendingSource(string $kind): array
    {
        return match ($kind) {
            'plan' => ['mrp_runs', "o.state='review' AND o.id=(SELECT MAX(id) FROM mrp_runs WHERE org_id=o.org_id)"],
            'work' => ['work_orders', "o.state='draft'"],
            default => throw new BusinessError('找不到此审批类型。', 404),
        };
    }

    private function readRoles(string $kind): array
    {
        return self::READ_ROLES[$kind] ?? throw new BusinessError('找不到此审批类型。', 404);
    }
}
