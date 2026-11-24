<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Identity
{
    public const ROLES = [
        'admin' => '系统管理员',
        'sales' => '销售',
        'planner' => '计划',
        'buyer' => '采购',
        'warehouse' => '仓储',
        'production' => '车间',
        'quality' => '质检',
        'finance' => '财务',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function authenticate(string $email, string $password): int
    {
        $email = strtolower(trim($email));
        Value::assert(strlen($email) <= 190 && strlen($password) <= 72, '邮箱或密码错误。', 401);
        $user = $this->db->row('SELECT id, password_hash FROM users WHERE email=? AND enabled=1', [$email]);
        if ($user === null) {
            password_verify($password, '$2y$10$zeAD7J9B68IGi6D4nJjQvuG2uh6/UpL8D908hXO/aWNQgLyyPQiIu');
        }
        Value::assert($user !== null && password_verify($password, $user['password_hash']), '邮箱或密码错误。', 401);
        return (int) $user['id'];
    }

    public function organizations(int $user): array
    {
        return $this->db->rows('SELECT DISTINCT o.id, o.code, o.name, o.currency, t.name AS tenant
            FROM memberships m JOIN users u ON u.id=m.user_id AND u.enabled=1
            JOIN organizations o ON o.id=m.org_id JOIN tenants t ON t.id=o.tenant_id
            WHERE m.user_id=? AND m.enabled=1 ORDER BY t.name, o.code', [$user]);
    }

    public function scope(int $user, int $organization): Scope
    {
        $rows = $this->db->rows('SELECT o.id, o.tenant_id, o.code, o.name, o.currency, u.name AS actor_name, m.role
            FROM memberships m JOIN users u ON u.id=m.user_id AND u.enabled=1
            JOIN organizations o ON o.id=m.org_id
            WHERE m.user_id=? AND m.org_id=? AND m.enabled=1 ORDER BY m.role', [$user, $organization]);
        Value::assert($rows !== [], '没有此法人账套的访问权限。', 403);
        return new Scope($user, $organization, (int) $rows[0]['tenant_id'], $rows[0]['currency'],
            $rows[0]['code'], $rows[0]['name'], $rows[0]['actor_name'], array_column($rows, 'role'));
    }

    public function initialize(string $tenantName, string $orgCode, string $orgName, string $currency, string $name,
        string $email, string $password): array
    {
        $tenantName = Value::text($tenantName, 180, '集团名称');
        $orgCode = Value::code($orgCode, '法人编码');
        $orgName = Value::text($orgName, 180, '法人名称');
        $currency = self::currency($currency);
        [$name, $email, $hash] = self::credentials($name, $email, $password);
        return $this->db->transaction(function () use ($tenantName, $orgCode, $orgName, $currency, $name, $email, $hash): array {
            $tenant = $this->db->insert('INSERT INTO tenants (name) VALUES (?)', [$tenantName]);
            $org = $this->db->insert('INSERT INTO organizations (tenant_id,code,name,currency) VALUES (?,?,?,?)',
                [$tenant, $orgCode, $orgName, $currency]);
            $user = $this->db->insert('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)', [$name, $email, $hash]);
            $this->db->run("INSERT INTO memberships (org_id,user_id,role) VALUES (?,?,'admin')", [$org, $user]);
            return ['tenant' => $tenant, 'org' => $org, 'user' => $user];
        });
    }

    public static function currency(string $currency): string
    {
        $currency = strtoupper($currency);
        Value::assert((bool) preg_match('/\A[A-Z]{3}\z/', $currency), '本位币必须是三位代码。');
        return $currency;
    }

    public static function credentials(string $name, string $email, string $password): array
    {
        $email = strtolower(trim($email));
        Value::assert(strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false, '邮箱无效。');
        Value::assert(strlen($password) >= 12 && strlen($password) <= 72 && !str_contains($password, "\0"),
            '密码长度须为 12 至 72 字节。');
        return [Value::text($name, 140, '姓名'), $email, password_hash($password, PASSWORD_BCRYPT)];
    }
}
