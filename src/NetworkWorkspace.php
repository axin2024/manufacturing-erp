<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class NetworkWorkspace extends WorkspaceScreen
{
    private function ownSites(): array
    {
        return $this->db->rows('SELECT id,code,name FROM network_sites WHERE org_id=? ORDER BY code', [$this->scope->org]);
    }

    public function sites(array $parameters): array
    {
        $this->requireRole('planner', 'warehouse');
        $sites = $this->ownSites();
        $org = $this->scope->org;
        $lanes = $this->db->rows("SELECT l.id,l.revision,l.lead_days,s.code AS source,d.code AS destination,
            CONCAT(s.code,' → ',d.code) AS label FROM network_lanes l
            JOIN network_sites s ON s.id=l.source_site JOIN network_sites d ON d.id=l.destination_site
            WHERE l.tenant_id=? AND (s.org_id=? OR d.org_id=?) ORDER BY s.code,d.code", [$this->scope->tenant, $org, $org]);
        $forms = [];
        $siteOptions = $this->options($sites, 'code', 'name');
        $siteField = $this->field('site', '本厂', 'select', ['options' => $siteOptions]);
        $items = $this->items();
        $itemField = $this->field('item', '物料', 'select', ['options' => $items]);
        if ($this->scope->can('admin')) {
            $forms[] = $this->requestForm('登记工厂', 'create-network-site', $this->nameFields('工厂', 160));
        }
        if ($sites && $this->scope->can('planner')) {
            if ($items) {
                $forms[] = $this->form('启用工厂物料', 'map-site-item', [$siteField, $itemField]);
            }
            $forms[] = $this->form('设置例外工作日', 'set-site-calendar', [$siteField, $this->dateField('date', '日期'),
                $this->field('working', '安排', 'select', ['options' => [1 => '工作', 0 => '休息']])]);
            $destinations = $this->db->rows("SELECT s.id,CONCAT(o.code,' / ',s.code) AS code,s.name FROM network_sites s
                JOIN organizations o ON o.id=s.org_id WHERE s.tenant_id=? AND EXISTS (SELECT 1 FROM memberships m
                  WHERE m.org_id=s.org_id AND m.user_id=? AND m.enabled=1 AND m.role IN ('admin','planner')) ORDER BY o.code,s.code",
                [$this->scope->tenant, $this->scope->user]);
            $forms[] = $this->requestForm('维护运输线路', 'save-network-lane', [
                $this->field('source', '起运工厂', 'select', ['options' => $siteOptions]),
                $this->field('destination', '目的工厂', 'select', ['options' => $this->options($destinations, 'code', 'name')]),
                $this->field('days', '运输工作日', 'number', ['min'=>0, 'max'=>365]),
                $this->field('revision', '当前线路版本（新建填 0）', 'number', ['min'=>0, 'value'=>0]),
            ]);
        }
        $screen = ['title' => '工厂运输网络', 'forms' => $forms, 'tables' => [
            $this->table('本法人所属工厂', ['code'=>'编码', 'name'=>'工厂'], $sites),
            $this->table('运输线路', ['id'=>'线路编号','source'=>'起运工厂','destination'=>'目的工厂','lead_days'=>'运输工作日','revision'=>'版本'], $lanes),
        ]];
        if ($this->scope->can('admin')) {
            $screen['links'][] = $this->link('库位与工单归属核对', 'network-ownership');
        }
        if ($lanes && $items) {
            $screen['filters'] = [$this->field('lane', '线路', 'select', ['options'=>$this->options($lanes, 'label'), 'value'=>$parameters['lane'] ?? '']),
                $itemField + ['value'=>$parameters['item'] ?? ''], $this->field('departure', '起运日', 'date', ['value'=>$parameters['departure'] ?? gmdate('Y-m-d')])];
        }
        if (isset($parameters['lane'])) {
            $preview = (new FactoryNetwork($this->db, $this->scope))->preview(WebInput::id($parameters, 'lane'),
                WebInput::id($parameters, 'item'), WebInput::stringParameter($parameters, 'departure'));
            $screen['details'] = ['线路编号'=>$preview['lane_id'], '预计到货'=>$preview['arrival_date']];
            $screen['tables'][] = $this->table('缺少物料资格的工厂', ['site_id'=>'工厂编号', 'item_id'=>'物料编号'], $preview['gaps']);
            $screen['tables'][] = $this->table('可用等效料号',['code'=>'源料号','evidence'=>'等效依据','revision'=>'资格版本'],$preview['substitutes']);
        }
        if ($this->scope->can('planner')) {
            $sources = $this->db->rows("SELECT DISTINCT i.id,i.code,i.name,l.id AS lane_id,s.code AS source,d.code AS dest
                FROM network_lanes l JOIN network_sites d ON d.id=l.destination_site JOIN network_sites s ON s.id=l.source_site
                JOIN network_site_items m ON m.site_id=s.id JOIN items i ON i.id=m.item_id
                WHERE d.org_id=? AND EXISTS (SELECT 1 FROM memberships a WHERE a.org_id=s.org_id AND a.user_id=?
                    AND a.enabled=1 AND a.role IN ('admin','planner')) ORDER BY l.id,i.code",[$org,$this->scope->user]);
            $incoming = [];
            foreach ($sources as $row) {
                $incoming[$row['lane_id']] = $row['source'].' → '.$row['dest'];
            }
            if ($incoming && $items) {
                $screen['forms'][] = $this->requestForm('维护跨厂等效料号','qualify-transfer-item',[
                    $this->field('lane','收货线路','select',['options'=>$incoming]),
                    $this->field('source_item','起运厂料号','select',['options'=>$this->options($sources,'code','name')]),$itemField,
                    $this->field('evidence','等效依据或工程批准号'),$this->field('enabled','资格状态','select',['options'=>[1=>'启用',0=>'停用']]),
                    $this->field('revision','资格版本（新增填 0）','number',['min'=>0,'value'=>0])]);
                $rules = $this->db->rows('SELECT q.lane_id,a.code AS shipped,b.code AS required,q.evidence,q.enabled,q.revision
                    FROM network_substitutions q JOIN network_sites d ON d.id=q.destination_site JOIN items a ON a.id=q.source_item_id
                    JOIN items b ON b.id=q.item_id WHERE d.org_id=? ORDER BY q.lane_id,a.code,b.code',[$org]);
                $screen['tables'][] = $this->table('收货方等效资格',['lane_id'=>'线路','shipped'=>'起运料号','required'=>'收货料号',
                    'evidence'=>'工程依据','enabled'=>'启用','revision'=>'版本'],$rules);
            }
        }
        return $screen;
    }

    public function ownership(): array
    {
        $report = (new FactoryOwnership($this->db, $this->scope))->report();
        $pilot = (new NetworkPilot($this->db,$this->scope))->current();
        $kind = $this->field('kind', '记录类型', 'select', ['options'=>['location'=>'库位', 'work'=>'工单']]);
        $forms = [$this->requestForm('处理下一批', 'backfill-site-ownership', [$kind,
            $this->field('limit', '单批数量', 'number', ['min'=>1, 'max'=>500, 'value'=>100])],
            [], '单厂账套可自动归属，多厂账套需由管理员指定。游标会随每批处理结果一起保存。')];
        $sites = $this->ownSites();
        if ($sites) {
            $forms[] = $this->form('指定工厂', 'assign-site-ownership', [$kind, $this->field('entity', '记录编号', 'number', ['min'=>1]),
                $this->field('site', '工厂', 'select', ['options'=>$this->options($sites, 'code','name')])]);
        }
        $countRows = [];
        $next = $pilot['state']==='active' ? 'draining' : 'active';
        $forms[] = $this->requestForm($next==='active'?'启用集团计划':'停止受理新计划，继续执行已提交单据','change-network-pilot',[],
            ['state'=>$next,'revision'=>$pilot['revision']]);
        foreach ($report['counts'] as $type=>$counts) {
            $countRows[] = ['type'=>$type === 'location' ? '库位' : '工单'] + $counts;
        }
        $reasons = ['no_site'=>'本账套尚无工厂','ambiguous'=>'本账套有多个工厂','item_unmapped'=>'工厂缺少物料资格'];
        foreach ($report['issues'] as &$issue) {
            $issue['reason'] = $reasons[$issue['reason']];
        }
        unset($issue);
        return ['title'=>'工厂归属核对', 'parent'=>['network','工厂运输网络'], 'forms'=>$forms,
            'details'=>['集团试点状态'=>['setup'=>'待核对','active'=>'已启用','draining'=>'正在处理存量'][$pilot['state']],'状态版本'=>$pilot['revision']],
            'links'=>[$this->export('导出本法人未决单据','network-open')],'tables'=>[
            $this->table('账套记录', ['type'=>'类型','total'=>'总数','assigned'=>'已归属','unresolved'=>'未归属'], $countRows),
            $this->table('库存数量勾稽', ['code'=>'物料','base_uom'=>'基础单位','quality'=>'质量','total_milli'=>'账面总量',
                'assigned_milli'=>'已归属','unresolved_milli'=>'待归属'], $report['balances']),
            $this->table('待处理记录', ['kind'=>'类型','entity_id'=>'记录编号','reason'=>'原因'], $report['issues']),
            $this->table('已提交的批次进度', ['kind'=>'类型','last_id'=>'最近扫描编号'], $report['cursors']),
        ]];
    }

    public function forecasts(?int $id, int $before): array
    {
        $this->requireRole('planner', 'sales');
        if ($id !== null) {
            $detail = (new WeeklyForecast($this->db, $this->scope))->detail($id);
            return ['title'=>'需求消耗明细', 'parent'=>['forecasts','周预测与订单消耗'], 'details'=>[
                '预测行'=>$id, '当前有效行'=>$detail['current_line'], '来源批次'=>$detail['batch_code'],
                '工厂'=>$detail['site_code'], '物料'=>$detail['item_code'], '周一起始'=>$detail['week_start'],
                '单位'=>$detail['base_uom'], '原预测'=>WebView::scaled($detail['qty_milli'],3),
                '已消耗'=>WebView::scaled($detail['consumed_milli'],3), '独立剩余需求'=>WebView::scaled($detail['remaining_milli'],3),
                '接单量减预测量'=>WebView::scaled($detail['variance_milli'],3)],
                'tables'=>[$this->table('当周客户订单', ['number'=>'销售单号','line_no'=>'订单行','delivery_no'=>'交付行',
                    'due_on'=>'交期','booked_milli'=>'有效接单量','consumed_milli'=>'抵扣预测量'],
                    array_map(static function (array $order): array {
                        $order['_links']['number'] = WebView::url('sales',(int) $order['order_id']);
                        return $order;
                    }, $detail['orders']))]];
        }
        $forms = [];
        if ($this->scope->can('planner')) {
            $forms[] = $this->requestForm('导入每周需求 CSV', 'import-weekly-forecast', [
                $this->field('batch', '来源批次'), $this->field('forecast_file', '预测文件', 'file')],
                [], '列名为 site,item,week_start,quantity。使用工厂编码、物料编码、周一日期和基础单位数量。') + ['upload'=>true];
        }
        $sites = $this->ownSites();
        if ($sites) {
            $forms[] = $this->form('给销售交付行分配工厂', 'assign-demand-site', [
                $this->field('demand','销售交付行编号','number',['min'=>1]),
                $this->field('site','工厂','select',['options'=>$this->options($sites,'code','name')])]);
        }
        $rows = $this->db->rows('SELECT f.id,s.code AS site,i.code AS item,b.batch_code,f.week_start,f.qty_milli
            FROM network_forecast_lines f JOIN network_forecast_batches b ON b.id=f.batch_id
            JOIN network_sites s ON s.id=f.site_id JOIN items i ON i.id=f.item_id WHERE f.org_id=? AND f.id<?
            AND NOT EXISTS (SELECT 1 FROM network_forecast_lines v WHERE v.site_id=f.site_id AND v.item_id=f.item_id
                AND v.week_start=f.week_start AND v.id>f.id) ORDER BY f.id DESC LIMIT 51', [$this->scope->org,$before]);
        return ['title'=>'周预测与订单消耗','forms'=>$forms,'tables'=>[$this->listTable('有效预测',
            ['id'=>'预测行','site'=>'工厂','item'=>'物料','week_start'=>'周起始','qty_milli'=>'预测数量','batch_code'=>'来源批次'], $rows, 'forecasts')]];
    }
}
