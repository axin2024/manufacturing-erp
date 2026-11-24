<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;
use DateTimeZone;

final class SetupWorkspace extends WorkspaceScreen
{
    public function screen(string $page, ?int $id, int $before, array $parameters): array
    {
        return match ($page) {
            'organizations' => $this->organizations($before),
            'members' => $this->members($before),
            'master' => $id === null ? $this->itemsList($before) : $this->item($id, $before),
            'locations' => $this->locationsList($before),
            'parties' => $this->partiesList($before),
            'engineering' => $this->engineering($id, $before, $parameters),
            'bom', 'route' => $this->revision($page, $id),
            'capacity' => $this->capacity($parameters),
        };
    }

    private function organizations(int $before): array
    {
        $this->requireRole('admin');
        $org = $this->scope->org;
        $entities = $this->db->rows('SELECT o.code,o.name,o.currency FROM organizations o
            WHERE o.tenant_id=? AND EXISTS (SELECT 1 FROM memberships m WHERE m.org_id=o.id AND m.user_id=? AND m.enabled=1)
            ORDER BY o.code', [$this->scope->tenant, $this->scope->user]);
        $departments = $this->db->rows('SELECT id,code,name FROM departments WHERE org_id=? ORDER BY code', [$org]);
        $events = $this->paged($this->db->rows('SELECT e.id,e.event_type,e.entity_id,e.details AS payload,e.created_at,u.name AS actor
            FROM audit_events e JOIN users u ON u.id=e.actor_id WHERE e.org_id=? AND e.id<? ORDER BY e.id DESC LIMIT 51',
            [$org, $before]), 'id', 'organizations');
        return ['title' => '组织与审计', 'links' => [$this->link('账号与岗位权限', 'members')], 'forms' => [
            $this->form('新建法人账套', 'create-organization', [
                ...$this->nameFields('法人'), $this->field('currency', '本位币代码', 'text', ['value' => $this->scope->currency, 'maxlength' => 3]),
            ]),
            $this->form('登记部门', 'add-department', $this->nameFields('部门')),
        ], 'tables' => [
            $this->table('可切换的集团法人', ['code' => '法人编码', 'name' => '名称', 'currency' => '本位币'], $entities),
            $this->table('当前账套部门', ['code' => '部门编码', 'name' => '名称'], $departments),
            $this->table('当前账套审计', ['created_at' => '时间（UTC）', 'actor' => '经办人', 'event_type' => '事件',
                'entity_id' => '对象编号', 'payload' => '记录内容'], $events['rows'], null, null, $events['next']),
        ]];
    }

    private function members(int $before): array
    {
        $this->requireRole('admin');
        $org = $this->scope->org;
        $members = $this->paged($this->db->rows('SELECT u.id,u.name,u.email FROM users u
            WHERE u.enabled=1 AND u.id<? AND EXISTS (SELECT 1 FROM memberships m JOIN organizations o ON o.id=m.org_id
                WHERE o.tenant_id=? AND m.user_id=u.id) ORDER BY u.id DESC LIMIT 51',
            [$before, $this->scope->tenant]), 'id', 'members');
        $rows = $members['rows'];
        if ($rows !== []) {
            $roles = $this->db->rows('SELECT m.user_id,m.role,m.enabled FROM memberships m WHERE m.org_id=?
                AND m.user_id IN (' . implode(',', array_fill(0, count($rows), '?')) . ') ORDER BY m.role',
                [$org, ...array_column($rows, 'id')]);
            $byUser = [];
            foreach ($roles as $role) {
                $byUser[$role['user_id']][] = Identity::ROLES[$role['role']] . ((bool) $role['enabled'] ? '' : '（已撤销）');
            }
            foreach ($rows as &$row) {
                $row['roles'] = implode('、', $byUser[$row['id']] ?? []);
            }
            unset($row);
        }
        $role = $this->field('role', '岗位', 'select', ['options' => Identity::ROLES]);
        $forms = [$this->form('新建账号并授予岗位', 'add-member', [
            $this->field('name', '姓名', 'text', ['maxlength' => 140]),
            $this->field('email', '邮箱', 'email', ['maxlength' => 190, 'autocomplete' => 'off']),
            $this->field('password', '初始密码', 'password', ['maxlength' => 72, 'autocomplete' => 'new-password']),
            $role,
        ], [], '密码须为 12 至 72 字节。账号仅获得当前账套的所选岗位。')];
        if ($rows !== []) {
            $forms[] = $this->form('调整集团账号在当前账套的岗位', 'set-membership', [
                $this->field('user', '集团账号', 'select', ['options' => $this->options($rows, 'name', 'email')]), $role,
                $this->field('enabled', '权限', 'select', ['options' => [1 => '授予', 0 => '撤销']]),
            ]);
        }
        return ['title' => '账号与岗位权限', 'parent' => ['organizations', '组织与审计'], 'forms' => $forms, 'tables' => [
            $this->table('集团账号及当前账套岗位', ['name' => '姓名', 'email' => '邮箱', 'roles' => '当前岗位'], $rows, null, null, $members['next']),
        ]];
    }

    private function itemsList(int $before): array
    {
        $forms = $this->scope->can('planner') ? [$this->form('登记或启用集团物料', 'add-item', [
            ...$this->nameFields('物料', 160), $this->field('uom', '基础单位', 'text', ['maxlength' => 24]),
            $this->field('sourcing', '供应策略', 'select', ['options' => ['purchase' => '采购', 'manufacture' => '自制', 'subcontract' => '委外']]),
            $this->field('standard_cost', '标准单价（' . $this->scope->currency . '）', 'number', ['min' => '0', 'step' => '0.01']),
            $this->field('lead_days', '提前期（天）', 'number', ['min' => '0', 'max' => '365', 'value' => '0']),
        ], [], '启用集团已有编码时，名称和基础单位须与集团目录一致。')] : [];
        $links = [$this->link('库位目录', 'locations')];
        if ($this->scope->can('sales', 'buyer', 'finance', 'planner', 'warehouse')) {
            $links[] = $this->link('往来单位', 'parties');
        }
        return ['title' => '物料目录', 'forms' => $forms, 'links' => $links, 'tables' => [
            $this->listTable('当前账套物料', ['code' => '编码', 'name' => '名称', 'base_uom' => '基础单位',
                'kind' => '供应策略', 'lead_days' => '提前期（天）', 'standard_cost_minor' => '标准单价'], $this->itemRows($before), 'master', 'code'),
        ]];
    }

    private function item(int $id, int $before): array
    {
        $item = $this->organizationItem($id);
        $forms = $this->scope->can('planner') ? [$this->form('发布单位换算版本', 'publish-conversion', [
            $this->field('uom', '交易单位', 'text', ['maxlength' => 24]), $this->dateField('effective_on', '生效日'),
            $this->quantityField('factor', '每交易单位折合基础单位数量'),
        ], ['item' => $id], '收货和工程单据保留当时使用的换算率。')] : [];
        $conversions = $this->paged($this->db->rows('SELECT id,uom,effective_on,factor_milli FROM unit_conversions
            WHERE org_id=? AND item_id=? AND id<? ORDER BY id DESC LIMIT 51', [$this->scope->org, $id, $before]), 'id', 'master', $id);
        return ['title' => $item['code'] . ' · ' . $item['name'], 'parent' => ['master', '物料目录'], 'forms' => $forms,
            'links' => $this->scope->can('planner', 'production') ? [$this->link('查看工程版本', 'engineering', $id)] : [],
            'details' => ['物料编号' => $id, '基础单位' => $item['base_uom'], '供应策略' => WebView::state($item['sourcing']),
                '提前期（天）' => $item['lead_days'], '标准单价' => WebView::scaled($item['standard_cost_minor'], 2) . ' ' . $this->scope->currency],
            'tables' => [$this->table('已发布单位换算', ['id' => '版本号', 'uom' => '交易单位', 'effective_on' => '生效日',
                'factor_milli' => '折合基础单位数量'], $conversions['rows'], null, null, $conversions['next'])]];
    }

    private function locationsList(int $before): array
    {
        $list = $this->paged($this->db->rows('SELECT id,code,name FROM locations WHERE org_id=? AND id<? ORDER BY id DESC LIMIT 51',
            [$this->scope->org, $before]), 'id', 'locations');
        return ['title' => '库位目录', 'parent' => ['master', '物料目录'],
            'forms' => $this->scope->can('warehouse') ? [$this->form('登记库位', 'add-location', $this->nameFields('库位'))] : [],
            'tables' => [$this->table('当前账套库位', ['code' => '编码', 'name' => '名称'], $list['rows'], null, null, $list['next'])]];
    }

    private function partiesList(int $before): array
    {
        $this->requireRole('sales', 'buyer', 'finance', 'planner', 'warehouse');
        $types = ['customer' => '客户', 'supplier' => '供应商', 'both' => '客户兼供应商'];
        $allowed = array_intersect_key($types, array_filter([
            'customer' => $this->scope->can('sales'), 'supplier' => $this->scope->can('buyer'), 'both' => $this->scope->can('admin'),
        ]));
        $forms = $allowed === [] ? [] : [$this->form('登记往来单位', 'add-party', [
            ...$this->nameFields('往来单位', 160), $this->field('kind', '类型', 'select', ['options' => $allowed]),
        ])];
        $list = $this->paged($this->db->rows('SELECT id,code,name,kind FROM parties WHERE org_id=? AND id<? ORDER BY id DESC LIMIT 51',
            [$this->scope->org, $before]), 'id', 'parties');
        foreach ($list['rows'] as &$row) {
            $row['kind'] = $types[$row['kind']];
        }
        unset($row);
        return ['title' => '往来单位', 'parent' => ['master', '物料目录'], 'forms' => $forms,
            'tables' => [$this->table('当前账套往来单位', ['code' => '编码', 'name' => '名称', 'kind' => '类型'],
                $list['rows'], null, null, $list['next'])]];
    }

    private function engineering(?int $id, int $before, array $parameters): array
    {
        $this->requireRole('planner', 'production');
        if ($id === null) {
            return ['title' => 'BOM 与工艺路线', 'links' => [$this->link('物料目录', 'master'), $this->link('工作中心与产能', 'capacity')],
                'tables' => [$this->listTable('选择物料查看生效版本', ['code' => '编码', 'name' => '物料名称', 'kind' => '供应策略'],
                    $this->itemRows($before), 'engineering', 'code')]];
        }
        $day = Value::day(WebInput::stringParameter($parameters, 'on', gmdate('Y-m-d')));
        $item = $this->organizationItem($id);
        $links = [$this->link('单位换算与主数据', 'master', $id), $this->link('工作中心与产能', 'capacity')];
        $forms = $this->scope->can('planner') ? (new SetupForms($this->db, $this->scope))->engineering($item, $day) : [];
        $engineering = new ProductEngineering($this->db, $this->scope);
        $details = [];
        $tables = [];
        foreach ([
            ['boms', 'bom', 'BOM', $engineering->bom($id, $day), 'bom_before', 'route_before'],
            ['routes', 'route', '工艺路线', $engineering->route($id, $day), 'route_before', 'bom_before'],
        ] as [$source, $page, $label, $active, $cursor, $other]) {
            $cutoff = WebInput::optionalId($parameters, $cursor) ?? PHP_INT_MAX;
            $rows = $this->db->rows("SELECT id,effective_on,created_at FROM $source
                WHERE org_id=? AND item_id=? AND id<? ORDER BY id DESC LIMIT 51", [$this->scope->org, $id, $cutoff]);
            foreach ($rows as &$row) {
                $row['effective'] = $row['id'] === ($active['id'] ?? null);
            }
            unset($row);
            $list = $this->paged($rows, 'id', 'engineering', $id, $cursor, ['on' => $day, $other => WebInput::optionalId($parameters, $other)]);
            $tables[] = $this->table($label . '版本记录', ['id' => '版本号', 'effective_on' => '生效日', 'effective' => '查询日在用',
                'created_at' => '发布时间（UTC）'], $list['rows'], $page, 'id', $list['next']);
            $details[$label . '版本'] = $active['id'] ?? '未发布或未到生效日';
            if ($active !== null) {
                $links[] = $this->link('查看生效' . $label, $page, (int) $active['id']);
            }
        }
        return ['title' => $item['code'] . ' · ' . $item['name'], 'parent' => ['engineering', 'BOM 与工艺路线'],
            'filters' => [['name' => 'on', 'label' => '查询日期', 'type' => 'date', 'value' => $day]], 'filter_parameters' => ['id' => $id],
            'forms' => $forms, 'links' => $links, 'details' => $details, 'tables' => $tables];
    }

    private function revision(string $page, ?int $id): array
    {
        $this->requireRole('planner', 'production');
        Value::assert($id !== null, '缺少工程版本编号。', 404);
        $engineering = new ProductEngineering($this->db, $this->scope);
        if ($page === 'bom') {
            $record = $engineering->bomRevision($id);
            $components = $this->scopeLinks($record['lines'], 'item_id', 'master');
            $codes = $this->options((new MasterData($this->db, $this->scope))->items(), 'code');
            foreach ($components as &$row) {
                $row['alternatives'] = implode('、', array_map(static fn(int $item): string => $codes[$item], $row['alternatives']));
            }
            unset($row);
            $table = $this->table('BOM 组件及可替代物料', ['item_id' => '物料编号', 'code' => '编码', 'name' => '名称',
                'qty_per_milli' => '单件基础用量', 'base_uom' => '基础单位', 'source_uom' => '录入单位',
                'factor_milli' => '换算率', 'loss_bps' => '损耗（基点）', 'alternatives' => '替代物料'], $components);
        } else {
            $record = $engineering->routeRevision($id);
            $table = $this->table('工序标准工时', ['sequence_no' => '顺序', 'label' => '工序', 'code' => '工作中心',
                'setup_minutes' => '准备分钟', 'run_seconds_per_unit' => '单件加工秒数'], $record['steps']);
        }
        $item = $this->organizationItem((int) $record['item_id']);
        return ['title' => ($page === 'bom' ? 'BOM' : '工艺路线') . ' #' . $id,
            'links' => [$this->link('查看此物料全部版本', 'engineering', (int) $item['id'], ['on' => $record['effective_on']])],
            'details' => ['物料' => $item['code'] . ' · ' . $item['name'], '生效日' => $record['effective_on']], 'tables' => [$table]];
    }

    private function capacity(array $parameters): array
    {
        $this->requireRole('planner', 'production');
        $today = gmdate('Y-m-d');
        $start = Value::day(WebInput::stringParameter($parameters, 'from', $today));
        $end = Value::day(WebInput::stringParameter($parameters, 'to', gmdate('Y-m-d', time() + 6 * 86_400)));
        $utc = new DateTimeZone('UTC');
        $endDate = new DateTimeImmutable($end, $utc);
        $days = (new DateTimeImmutable($start, $utc))->diff($endDate)->days;
        Value::assert($start <= $end && $days < 31 && $end < '9999-12-31', '起止日期须按顺序且最多查询 31 天。');
        $from = $start . ' 00:00:00';
        $to = $endDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $scheduling = new Scheduling($this->db, $this->scope);
        $resources = $scheduling->resources();
        $centers = array_column($resources['centers'], 'code', 'id');
        foreach ($resources['machines'] as &$machine) {
            $machine['center_code'] = $centers[$machine['center_id']];
        }
        unset($machine);
        $shifts = $this->db->rows('SELECT w.code,s.starts_at,s.ends_at FROM center_shifts s JOIN work_centers w ON w.id=s.center_id
            WHERE s.org_id=? AND s.starts_at<? AND s.ends_at>? ORDER BY s.starts_at,w.code', [$this->scope->org, $to, $from]);
        $calendar = $scheduling->calendar($from, $to);
        foreach ($calendar as &$slot) {
            $slot['_links']['number'] = WebView::url('work', (int) $slot['work_id']);
        }
        unset($slot);
        $forms = $this->scope->can('planner') ? (new SetupForms($this->db, $this->scope))->capacity($resources['centers']) : [];
        return ['title' => '工作中心与产能', 'forms' => $forms, 'links' => [$this->link('生产工单', 'work'), $this->link('BOM 与工艺路线', 'engineering')],
            'filters' => [
                ['name' => 'from', 'label' => '开始日期（UTC）', 'type' => 'date', 'value' => $start],
                ['name' => 'to', 'label' => '截止日期（含）', 'type' => 'date', 'value' => $end],
            ], 'tables' => [
                $this->table('已占用工序时段', ['number' => '工单', 'label' => '工序', 'machine' => '机台',
                    'starts_at' => '开始（UTC）', 'ends_at' => '结束（UTC）', 'standard_seconds' => '标准秒数'], $calendar),
                $this->table('查询范围内的班次', ['code' => '工作中心', 'starts_at' => '开始（UTC）', 'ends_at' => '结束（UTC）'], $shifts),
                $this->table('工作中心', ['code' => '编码', 'name' => '名称'], $resources['centers']),
                $this->table('机台', ['code' => '编码', 'name' => '名称', 'center_code' => '工作中心'], $resources['machines']),
            ]];
    }

    private function itemRows(int $before): array
    {
        return $this->db->rows('SELECT i.id,i.code,i.name,i.base_uom,p.sourcing AS kind,p.standard_cost_minor,p.lead_days
            FROM org_items p JOIN items i ON i.id=p.item_id WHERE p.org_id=? AND i.id<? ORDER BY i.id DESC LIMIT 51',
            [$this->scope->org, $before]);
    }
}
