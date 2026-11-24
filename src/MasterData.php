<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class MasterData extends Service
{
    public function createOrganization(string $code, string $name, string $currency): int
    {
        $code = Value::code($code, '法人编码');
        $name = Value::text($name, 180, '法人名称');
        $currency = Identity::currency($currency);
        return $this->mutate(function (int $org) use ($code, $name, $currency): int {
            $this->db->row('SELECT id FROM tenants WHERE id=? FOR UPDATE', [$this->scope->tenant]);
            Value::assert($this->db->row('SELECT id FROM organizations WHERE tenant_id=? AND code=?',
                [$this->scope->tenant, $code]) === null, '法人编码已存在。', 409);
            $new = $this->db->insert('INSERT INTO organizations (tenant_id,code,name,currency) VALUES (?,?,?,?)',
                [$this->scope->tenant, $code, $name, $currency]);
            $this->db->run("INSERT INTO memberships (org_id,user_id,role) VALUES (?,?,'admin')", [$new, $this->scope->user]);
            $this->audit('organization.created', $new, ['code' => $code, 'from_org' => $org]);
            return $new;
        }, 'admin');
    }

    public function addMember(string $name, string $email, string $password, string $role): int
    {
        Value::assert(isset(Identity::ROLES[$role]), '该岗位不存在。');
        [$name, $email, $hash] = Identity::credentials($name, $email, $password);
        return $this->mutate(function (int $org) use ($name, $email, $hash, $role): int {
            Value::assert($this->db->row('SELECT id FROM users WHERE email=?', [$email]) === null,
                '该邮箱已有账号，请通过已有账号授权。', 409);
            $user = $this->db->insert('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)', [$name, $email, $hash]);
            $this->db->run('INSERT INTO memberships (org_id,user_id,role) VALUES (?,?,?)', [$org, $user, $role]);
            $this->audit('membership.created', $user, ['role' => $role]);
            return $user;
        }, 'admin');
    }

    public function setMembership(int $user, string $role, bool $enabled): void
    {
        Value::assert(isset(Identity::ROLES[$role]), '该岗位不存在。');
        Value::assert($enabled || $user !== $this->scope->user || $role !== 'admin', '不能撤销自己的管理员权限。');
        $this->mutate(function (int $org) use ($user, $role, $enabled): void {
            $known = $this->db->row('SELECT m.user_id FROM memberships m JOIN organizations o ON o.id=m.org_id
                WHERE m.user_id=? AND o.tenant_id=? LIMIT 1', [$user, $this->scope->tenant]);
            Value::assert($known !== null, '该账号在集团中不可授权。', 404);
            $this->db->run('INSERT INTO memberships (org_id,user_id,role,enabled) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE enabled=?', [$org, $user, $role, (int) $enabled, (int) $enabled]);
            $this->audit('membership.changed', $user, ['role' => $role, 'enabled' => $enabled]);
        }, 'admin');
    }

    public function createDepartment(string $code, string $name): int
    {
        $code = Value::code($code, '部门编码');
        $name = Value::text($name, 140, '部门名称');
        return $this->mutate(function (int $org) use ($code, $name): int {
            Value::assert($this->db->row('SELECT id FROM departments WHERE org_id=? AND code=?', [$org, $code]) === null,
                '部门编码已存在。', 409);
            $id = $this->db->insert('INSERT INTO departments (org_id,code,name) VALUES (?,?,?)', [$org, $code, $name]);
            $this->audit('department.created', $id, ['code' => $code]);
            return $id;
        }, 'admin');
    }

    public function addItem(string $code, string $name, string $unit, string $sourcing, int $standardCost, int $leadDays): int
    {
        $code = Value::code($code, '物料编码');
        $name = Value::text($name, 160, '物料名称');
        $unit = Value::unit($unit);
        Value::assert(in_array($sourcing, ['purchase', 'manufacture', 'subcontract'], true), '供应策略无效。');
        Value::assert($leadDays >= 0 && $leadDays <= 365, '提前期须为 0 至 365 天。');
        Value::unitPrice($standardCost);
        return $this->mutate(function (int $org) use ($code, $name, $unit, $sourcing, $standardCost, $leadDays): int {
            $tenant = $this->scope->tenant;
            $this->db->row('SELECT id FROM tenants WHERE id=? FOR UPDATE', [$tenant]);
            $shared = $this->db->row('SELECT id,name,base_uom FROM items WHERE tenant_id=? AND code=?', [$tenant, $code]);
            if ($shared !== null) {
                Value::assert($shared['name'] === $name && $shared['base_uom'] === $unit,
                    '该物料已存在于集团目录，名称和基础单位须一致。', 409);
            }
            $id = $shared === null
                ? $this->db->insert('INSERT INTO items (tenant_id,code,name,base_uom) VALUES (?,?,?,?)', [$tenant, $code, $name, $unit])
                : (int) $shared['id'];
            Value::assert($this->db->row('SELECT item_id FROM org_items WHERE org_id=? AND item_id=?', [$org, $id]) === null,
                '物料已在当前账套启用。', 409);
            $this->db->run('INSERT INTO org_items (org_id,item_id,tenant_id,sourcing,standard_cost_minor,lead_days)
                VALUES (?,?,?,?,?,?)', [$org, $id, $tenant, $sourcing, $standardCost, $leadDays]);
            $this->audit('item.enabled', $id, ['code' => $code, 'sourcing' => $sourcing, 'standard_cost_minor' => $standardCost]);
            return $id;
        }, 'planner');
    }

    public function publishConversion(int $item, string $unit, string $effective, int $factor): int
    {
        $unit = Value::unit($unit);
        Value::day($effective);
        Value::quantity($factor);
        return $this->mutate(function (int $org) use ($item, $unit, $effective, $factor): int {
            $master = $this->organizationItem($item);
            Value::assert(strcasecmp($master['base_uom'], $unit) !== 0,
                '基础单位的换算率固定为 1。');
            Value::assert($this->db->row('SELECT id FROM unit_conversions
                WHERE org_id=? AND item_id=? AND uom=? AND effective_on=?', [$org, $item, $unit, $effective]) === null,
                '该单位在相同日期已有版本。', 409);
            $id = $this->db->insert('INSERT INTO unit_conversions (org_id,item_id,uom,effective_on,factor_milli) VALUES (?,?,?,?,?)',
                [$org, $item, $unit, $effective, $factor]);
            $this->audit('conversion.published', $id, ['item' => $item, 'effective_on' => $effective, 'factor_milli' => $factor]);
            return $id;
        }, 'planner');
    }

    public function addLocation(string $code, string $name): int
    {
        $code = Value::code($code, '库位编码');
        $name = Value::text($name, 140, '库位名称');
        return $this->mutate(function (int $org) use ($code, $name): int {
            Value::assert($this->db->row('SELECT id FROM locations WHERE org_id=? AND code=?', [$org, $code]) === null,
                '库位编码已存在。', 409);
            $id = $this->db->insert('INSERT INTO locations (org_id,code,name) VALUES (?,?,?)', [$org, $code, $name]);
            $this->audit('location.created', $id, ['code' => $code]);
            return $id;
        }, 'warehouse');
    }

    public function addParty(string $code, string $name, string $kind): int
    {
        $code = Value::code($code, '往来单位编码');
        $name = Value::text($name, 160, '往来单位名称');
        Value::assert(in_array($kind, ['customer', 'supplier', 'both'], true), '往来单位类型无效。');
        $roles = match ($kind) {
            'customer' => ['sales'],
            'supplier' => ['buyer'],
            'both' => ['admin'],
        };
        return $this->mutate(function (int $org) use ($code, $name, $kind): int {
            Value::assert($this->db->row('SELECT id FROM parties WHERE org_id=? AND code=?', [$org, $code]) === null,
                '往来单位编码已存在。', 409);
            $id = $this->db->insert('INSERT INTO parties (org_id,code,name,kind) VALUES (?,?,?,?)', [$org, $code, $name, $kind]);
            $this->audit('party.created', $id, ['code' => $code, 'kind' => $kind]);
            return $id;
        }, ...$roles);
    }

    public function items(): array
    {
        return $this->db->rows('SELECT i.id,i.code,i.name,i.base_uom,p.sourcing,p.standard_cost_minor,p.lead_days
            FROM org_items p JOIN items i ON i.id=p.item_id WHERE p.org_id=? ORDER BY i.code', [$this->scope->org]);
    }

    public function locations(): array
    {
        return $this->db->rows('SELECT id,code,name FROM locations WHERE org_id=? ORDER BY code', [$this->scope->org]);
    }

    public function parties(): array
    {
        $this->requireRole('sales', 'buyer', 'finance', 'planner', 'warehouse');
        return $this->db->rows('SELECT id,code,name,kind FROM parties WHERE org_id=? ORDER BY code', [$this->scope->org]);
    }
}
