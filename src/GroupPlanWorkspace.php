<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class GroupPlanWorkspace extends WorkspaceScreen
{
    public function screen(?int $id, int $before, array $params = []): array
    {
        $this->requireRole('planner');
        if ($id === null) {
            $sites = $this->db->rows("SELECT s.id,CONCAT(o.code,' / ',s.code) AS code,s.name FROM network_sites s
                JOIN organizations o ON o.id=s.org_id WHERE s.tenant_id=? AND EXISTS (SELECT 1 FROM memberships m
                WHERE m.org_id=s.org_id AND m.user_id=? AND m.enabled=1 AND m.role IN ('admin','planner')) ORDER BY o.code,s.code",
                [$this->scope->tenant,$this->scope->user]);
            $history = $this->db->rows("SELECT n.id,n.revision,n.parent_id,n.as_of,n.horizon_end,n.created_at FROM network_scenarios n
                WHERE n.org_id=? AND n.id<? AND NOT EXISTS (SELECT 1 FROM JSON_TABLE(n.snapshot,
                    '$.inputs.sites[*]' COLUMNS(org_id BIGINT PATH '$.org_id')) j WHERE NOT EXISTS
                    (SELECT 1 FROM memberships m WHERE m.org_id=j.org_id AND m.user_id=? AND m.enabled=1 AND m.role IN ('admin','planner')))
                ORDER BY n.id DESC LIMIT 51", [$this->scope->org,$before,$this->scope->user]);
            return ['title'=>'集团供需情景','forms'=>$sites ? [$this->requestForm('冻结所选工厂的供需','freeze-network-plan',[
                $this->field('sites','工厂范围','select',['multiple'=>true,'options'=>$this->options($sites,'code','name')]),
                $this->dateField('start','计划开始日'),$this->dateField('end','计划截止日')])] : [],
                'tables'=>[$this->listTable('情景版本',['id'=>'情景','revision'=>'版本','parent_id'=>'派生来源','as_of'=>'开始日',
                    'horizon_end'=>'截止日','created_at'=>'冻结时间'],$history,'scenarios')]];
        }
        $record = (new GroupScenarios($this->db,$this->scope))->detail($id);
        $routes = ['transfer'=>'跨厂调拨','manufacture'=>'自产','subcontract'=>'委外','uncovered'=>'未覆盖'];
        $reasons = ['sourcing_not_enabled'=>'未启用此供应策略','missing_bom'=>'缺少有效物料清单','missing_route'=>'缺少有效工艺路线'];
        $options = [];
        foreach ($record['lines'] as &$line) {
            $line['method'] = $routes[$line['kind']];
            foreach ($line['alternatives'] as $option) {
                $options[] = ['line'=>$line['id'], 'method'=>$routes[$option['kind']], 'condition'=>$reasons[$option['reason'] ?? ''] ?? '',
                    'source_site'=>$option['source_site'],'source_item_id'=>$option['source_item_id'] ?? $line['item_id'],
                    'qualification'=>$option['qualification'] ?? null,'available_milli'=>$option['available_milli'] ?? null,
                    'promised_on'=>$option['arrival_date'],'unit_cost_minor'=>$option['unit_cost_minor']];
            }
        }
        unset($line);
        $forms = [$this->requestForm('派生版本','freeze-network-plan',[$this->field('refresh','供需来源','select',
            ['options'=>[0=>'复制冻结的供需',1=>'读取最新业务记录并重新计算']])],['parent'=>$id])];
        if ($record['lines']) {
            $selection = [$this->field('selected_line_ids','选择建议行','select',['multiple'=>true,
                'options'=>$this->options($record['lines'],'id','method')])];
            $identifiers = ['scenario'=>$id,'revision'=>$record['revision'],'horizon_end'=>$record['horizon_end']];
            $forms[] = $this->requestForm('提交分厂签认','submit-network-plan',$selection,$identifiers);
            if ($this->scope->can('admin')) {
                $forms[] = $this->requestForm('发布已签认建议','publish-network-plan',$selection,$identifiers);
            }
        }
        $details = ['版本'=>$record['revision'],'计划区间'=>$record['as_of'].' 至 '.$record['horizon_end'],'本位币'=>$this->scope->currency];
        $eligible = array_column(array_filter($record['lines'],static fn(array $line): bool=>$line['promised_on']!==null),null,'id');
        $offset = WebInput::integer($params['shift_days'] ?? 0,'交期调整天数',-30,30);
        $selected = WebInput::optionalId($params,'preview_line');
        if ($selected!==null) {
            Value::assert(isset($eligible[$selected]),'本情景没有此可预览建议。',404);
            $line = $eligible[$selected];
            $signed = $this->db->row('SELECT MAX(d.promised_on) AS latest FROM network_plan_decisions d
                WHERE d.line_id=? AND d.accepted=1 AND NOT EXISTS (SELECT 1 FROM network_plan_decisions n
                    WHERE n.line_id=d.line_id AND n.site_id=d.site_id AND n.id>d.id)',[$selected]);
            $earliest = max($line['promised_on'],$signed['latest'] ?? '');
            $requested = (new \DateTimeImmutable($line['needed_on']))->modify(sprintf('%+d days',$offset))->format('Y-m-d');
            $possible = max($earliest,$requested);
            $details += ['预览建议'=>$selected,'原需求日'=>$line['needed_on'],'试排交期'=>$requested,'冻结路径最早到货'=>$line['promised_on'],
                '最近签认的最晚承诺'=>$signed['latest'],'预计可交日'=>$possible,
                '试排交期缺量'=>WebView::scaled($possible>$requested ? $line['qty_milli'] : 0,3),
                '超出本期'=>$possible>$record['horizon_end']?'是':'否'];
        }
        return ['title'=>'集团计划情景 '.$id,'parent'=>['scenarios','集团供需情景'],
            'details'=>$details,'filter_parameters'=>['id'=>$id],
            'filters'=>$eligible ? [$this->field('preview_line','交期预览建议','select',['options'=>$this->options($eligible,'id','method'),'value'=>$selected]),
                $this->field('shift_days','相对需求日调整天数','range',['value'=>$offset,'min'=>-30,'max'=>30,'step'=>1])] : [],
            'forms'=>$forms,
            'tables'=>[$this->table('各厂需求和缺口',['site_id'=>'工厂','item_id'=>'物料','needed_on'=>'需求日','demand_milli'=>'净需求',
                'available_milli'=>'可支配库存','stock_used_milli'=>'已分配库存','released_used_milli'=>'已释放工单覆盖',
                'transit_used_milli'=>'已发运在途覆盖','committed_used_milli'=>'已签执行单覆盖','shortage_milli'=>'缺口'],$record['balances']),
                $this->table('执行建议',['id'=>'建议编号','source_site'=>'供应厂','destination_site'=>'需求厂','item_id'=>'物料','method'=>'路径',
                    'source_item_id'=>'供应料号','needed_on'=>'需求日','promised_on'=>'预计可用','qty_milli'=>'建议数量','unit_cost_minor'=>'参考单价'],$record['lines']),
                $this->table('交期与成本比较',['line'=>'建议编号','method'=>'路径','source_site'=>'供应厂','available_milli'=>'可调量',
                    'source_item_id'=>'供应料号','qualification'=>'等效依据','promised_on'=>'预计可用','unit_cost_minor'=>'参考单价','condition'=>'限制'],$options)]];
    }

    public function inbox(): array
    {
        $rows = (new PlantConfirmations($this->db,$this->scope))->inbox();
        $forms = [];
        $org = $this->scope->org;
        if ($this->scope->can('admin')) {
            $sites = $this->db->rows('SELECT id,code,name FROM network_sites WHERE org_id=? ORDER BY code',[$org]);
            $people = $this->db->rows("SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN memberships m ON m.user_id=u.id
                WHERE u.enabled=1 AND m.org_id=? AND m.enabled=1 AND m.role IN ('admin','planner','production') ORDER BY u.name,u.id",[$org]);
            if ($sites && $people) {
                $forms[] = $this->form('维护工厂签认人','authorize-site-signer',[
                    $this->field('site','工厂','select',['options'=>$this->options($sites,'code','name')]),
                    $this->field('user','签认人','select',['options'=>$this->options($people,'name','email')]),
                    $this->field('enabled','授权','select',['options'=>[1=>'授予',0=>'撤销']])]);
            }
        }
        $departments = $this->db->rows('SELECT id,code,name FROM departments WHERE org_id=? ORDER BY code',[$org]);
        if ($departments) {
            foreach ($rows as $row) {
                $forms[] = $this->requestForm('签认 '.$row['site'].' 的建议 '.$row['line_id'],'decide-network-line',[
                    $this->field('department','责任中心','select',['options'=>$this->options($departments,'code','name')]),
                    $this->field('accepted','决定','select',['options'=>[1=>'接受',0=>'否决']]),
                    $this->field('promised_on','承诺日期','date',['value'=>$row['needed_on']]),$this->field('reason','依据或否决原因')],
                    ['line'=>$row['line_id'],'site'=>$row['site_id'],'previous'=>$row['approval_id'] ?? 0]);
            }
        }
        return ['title'=>'分厂产能签认','forms'=>$forms,'tables'=>[$this->table('本厂待办与签认结果',
            ['site'=>'工厂','scenario_id'=>'情景','line_id'=>'建议','kind'=>'路径','qty_milli'=>'数量','needed_on'=>'需求日',
                'approval_id'=>'签认编号','reason'=>'说明','promised_on'=>'承诺日'],$rows)]];
    }
}
