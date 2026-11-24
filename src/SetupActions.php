<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class SetupActions extends Service
{
    public function execute(string $action, array $input): array
    {
        $master = new MasterData($this->db, $this->scope);
        $engineering = new ProductEngineering($this->db, $this->scope);
        $scheduling = new Scheduling($this->db, $this->scope);
        $code = WebInput::stringParameter($input, 'code');
        $name = WebInput::stringParameter($input, 'name');
        $effective = WebInput::stringParameter($input, 'effective_on');
        [$page, $detail, $field, $id, $message] = match ($action) {
            'create-organization' => ['organizations', null, 'org_id',
                $master->createOrganization($code, $name, WebInput::stringParameter($input, 'currency')), '法人已建账，可从账套菜单切换。'],
            'add-department' => ['organizations', null, 'department_id', $master->createDepartment($code, $name), '部门已登记。'],
            'add-member' => ['members', null, 'user_id', $master->addMember($name, WebInput::stringParameter($input, 'email'),
                WebInput::stringParameter($input, 'password'), WebInput::stringParameter($input, 'role')), '账号及岗位已登记。'],
            'set-membership' => $this->setMembership($master, $input),
            'add-item' => ['master', null, 'item_id', $master->addItem($code, $name, WebInput::stringParameter($input, 'uom'),
                WebInput::stringParameter($input, 'sourcing'), WebInput::price($input, 'standard_cost'),
                WebInput::integer($input['lead_days'] ?? null, '提前期', 0, 365)), '物料已在当前账套启用。'],
            'publish-conversion' => ['master', WebInput::id($input, 'item'), 'conversion_id', $master->publishConversion(
                WebInput::id($input, 'item'), WebInput::stringParameter($input, 'uom'), $effective, WebInput::quantity($input, 'factor')),
                '换算版本已发布。'],
            'add-location' => ['locations', null, 'location_id', $master->addLocation($code, $name), '库位已登记。'],
            'add-party' => ['parties', null, 'party_id', $master->addParty($code, $name,
                WebInput::stringParameter($input, 'kind')), '往来单位已登记。'],
            'publish-bom' => ['bom', null, 'bom_id', $engineering->publishBom(WebInput::id($input, 'item'), $effective,
                $this->components($input)), 'BOM 版本已发布。'],
            'publish-route' => ['route', null, 'route_id', $engineering->publishRoute(WebInput::id($input, 'item'), $effective,
                $this->steps($input)), '工艺路线已发布。'],
            'add-center' => ['capacity', null, 'center_id', $engineering->addCenter(WebInput::id($input, 'department'), $code, $name),
                '工作中心已登记。'],
            'add-machine' => ['capacity', null, 'machine_id', $scheduling->addMachine(WebInput::id($input, 'center'), $code, $name),
                '机台已登记。'],
            'add-shift' => ['capacity', null, 'shift_id', $scheduling->addShift(WebInput::id($input, 'center'),
                WebInput::timestamp($input['starts_at'] ?? null), WebInput::timestamp($input['ends_at'] ?? null)), '班次已登记。'],
        };
        $target = WebView::url($page, in_array($action, ['add-item','publish-bom','publish-route'], true) ? $id : $detail);
        if ($action === 'add-shift') {
            $target = WebView::url($page, null, ['from' => substr($input['starts_at'], 0, 10), 'to' => substr($input['ends_at'], 0, 10)]);
        }
        return [
            'target' => $target,
            'message' => $message,
            'status' => $action === 'set-membership' ? 200 : 201,
            'result' => [$field => $id, 'url' => $target],
        ];
    }

    private function setMembership(MasterData $master, array $input): array
    {
        $id = WebInput::id($input, 'user');
        $enabled = WebInput::integer($input['enabled'] ?? null, '授权状态', 0, 1) === 1;
        $master->setMembership($id, WebInput::stringParameter($input, 'role'), $enabled);
        return ['members', null, 'user_id', $id, $enabled ? '岗位权限已授予。' : '岗位权限已撤销。'];
    }

    private function components(array $input): array
    {
        return WebInput::lines($input, 'lines', static function (array $line): array {
            $alternatives = $line['alternatives'] ?? [];
            Value::assert(is_array($alternatives) && array_is_list($alternatives) && count($alternatives) <= 12, '替代料最多 12 项。');
            return [
                'item' => WebInput::id($line, 'item'),
                'quantity' => WebInput::quantity($line, 'quantity'),
                'uom' => WebInput::stringParameter($line, 'uom'),
                'loss_bps' => WebInput::decimal($line['loss_percent'] ?? '0', 2, 9999),
                'alternatives' => array_map(static fn(mixed $id): int => WebInput::integer($id, '替代料编号'), $alternatives),
            ];
        });
    }

    private function steps(array $input): array
    {
        return WebInput::lines($input, 'steps', static fn(array $step): array => [
            'center' => WebInput::id($step, 'center'),
            'label' => WebInput::stringParameter($step, 'label'),
            'setup_minutes' => WebInput::integer($step['setup_minutes'] ?? null, '准备分钟数', 0, 1440),
            'run_seconds_per_unit' => WebInput::integer($step['run_seconds_per_unit'] ?? null, '单件加工秒数', 1, 86_400),
        ], 30);
    }
}
