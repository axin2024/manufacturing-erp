<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class TransitWorkspace extends WorkspaceScreen
{
    public function screen(?int $id,int $before): array
    {
        $register = new TransferRegister($this->db,$this->scope);
        if ($id===null) {
            $forms = [];
            if ($this->scope->can('admin')) {
                $sites = $this->db->rows('SELECT id,code,name FROM network_sites WHERE org_id=? ORDER BY code',[$this->scope->org]);
                $people = $this->db->rows("SELECT DISTINCT u.id,u.name,u.email FROM memberships m JOIN users u ON u.id=m.user_id
                    WHERE m.org_id=? AND m.enabled=1 AND u.enabled=1 AND m.role IN ('warehouse','admin') ORDER BY u.id",[$this->scope->org]);
                if ($sites && $people) {
                    $forms[] = $this->form('维护工厂仓储授权','grant-warehouse-site',[
                        $this->field('site','工厂','select',['options'=>$this->options($sites,'code','name')]),
                        $this->field('user','仓管','select',['options'=>$this->options($people,'name','email')]),
                        $this->field('enabled','授权','select',['options'=>[1=>'授予',0=>'撤销']])]);
                }
            }
            return ['title'=>'跨厂在途台账','forms'=>$forms,'tables'=>[$this->listTable('已下达的调拨单',['id'=>'调拨号','source'=>'起运厂',
                'destination'=>'目的厂','item'=>'物料','ordered_milli'=>'调拨量','shipped_milli'=>'已发','received_milli'=>'已收','transit_milli'=>'在途','status'=>'状态'],
                $register->list($before),'transit')]];
        }
        $order = $register->detail($id);
        $forms = [];
        if ($this->mayHandle($order['source_org'],$order['source_site']) && $order['ordered_milli']>$order['shipped_milli']) {
            $lots = $this->db->rows("SELECT DISTINCT l.id,l.lot_code FROM network_location_sites m JOIN stock_balances b ON b.org_id=m.org_id
                AND b.location_id=m.location_id JOIN stock_lots l ON l.id=b.lot_id WHERE m.site_id=? AND b.quality='available'
                AND b.qty_milli>0 AND l.item_id=? ORDER BY l.lot_code",[$order['source_site'],$order['source_item_id']]);
            if ($lots) {
                $forms[] = $this->requestForm('按批次发运','dispatch-transfer',[
                    $this->field('reference','本厂发运单号'),$this->field('lot','原批号','select',['options'=>$this->options($lots,'lot_code')]),
                    $this->locationField($order['source_site']),$this->quantityField('quantity','实发数量'),$this->dateField('posted_on','发运日')],['transfer'=>$id]);
            }
        }
        if ($this->mayHandle($order['destination_org'],$order['destination_site'])) {
            $open = array_filter($order['shipments'],static fn(array $row): bool => $row['transit_milli']>$row['lost_milli']);
            if ($open) {
                $shorts = array_filter($order['variances'],static fn(array $v): bool => $v['kind']==='short' && $v['pending_milli']>0);
                $forms[] = $this->requestForm('登记分批验收','receive-transfer',[
                    $this->field('reference','本厂收货单号'),$this->locationField($order['destination_site']),
                    $this->field('shipment','发运单与批号','select',['options'=>$this->options($open,'local_ref','shipment_lot')]),
                    $this->quantityField('expected','本批预计量'),$this->quantityField('counted','卸货实点量',true),
                    $this->field('variance','补收关联的短收单','select',['options'=>$this->options($shorts,'id','explanation'),'required'=>false]),
                    $this->field('reason','短溢收说明','text',['required'=>false]),$this->dateField('posted_on','入库日')],['transfer'=>$id]);
            }
            foreach ($order['variances'] as $variance) {
                if ($variance['kind']==='over' && $variance['pending_milli']>0) {
                    $forms[] = $this->requestForm('退回溢收件 · 差异单 '.$variance['id'],'return-transfer-excess',[
                        $this->quantityField('quantity','实际退回量'),$this->field('evidence','退回交接单或凭据')],['transfer'=>$id,'variance'=>$variance['id']]);
                }
            }
        }
        if ($this->scope->org===$order['destination_org'] && $this->scope->can('finance')) {
            foreach ($order['variances'] as $v) {
                if ($v['kind']==='short' && $v['pending_milli']>0) {
                    $forms[] = $this->requestForm('目的厂承担盘亏 · 差异单 '.$v['id'],'record-transfer-loss',[
                        $this->field('reference','独立盘亏单号'),$this->quantityField('quantity','承担数量'),
                        $this->dateField('posted_on','记账日'),$this->field('evidence','责任认定或双方协议编号')],['transfer'=>$id,'variance'=>$v['id']]);
                }
            }
        }
        if ($this->scope->can('finance') && !$order['clearance'] && $order['received_milli']+$order['lost_milli']===$order['ordered_milli']) {
            $forms[] = $this->requestForm('集团确认对清','clear-transfer',[$this->dateField('posted_on','对清日期'),$this->field('evidence','核对依据')],['transfer'=>$id]);
        }
        return ['title'=>'调拨单 '.$id,'parent'=>['transit','跨厂在途台账'],'forms'=>$forms,
            'details'=>['起运厂'=>$order['source'],'目的厂'=>$order['destination'],'物料'=>$order['item'],'起运料号'=>$order['source_item'],
                '已发'=>WebView::scaled($order['shipped_milli'],3),'已收'=>WebView::scaled($order['received_milli'],3),
                '在途'=>WebView::scaled($order['transit_milli'],3),'其中已认定盘亏'=>WebView::scaled($order['lost_milli'],3),
                '可继续验收'=>WebView::scaled($order['transit_milli']-$order['lost_milli'],3),'状态'=>$order['status'],
                '对清日期'=>$order['clearance']['posted_on'] ?? null,'核对依据'=>$order['clearance']['evidence'] ?? null,
                '确认人'=>$order['clearance']['actor'] ?? null],'tables'=>[
                $this->table('发运批次',['id'=>'发运编号','local_ref'=>'发运单','shipment_lot'=>'原批号','posted_on'=>'发运日','qty_milli'=>'已发',
                    'received_milli'=>'已收','transit_milli'=>'在途','movement_id'=>'出库流水','journal_id'=>'凭证'],$order['shipments']),
                $this->table('目的厂验收',['local_ref'=>'收货单','posted_on'=>'入库日','shipment_lot'=>'原批号','destination_lot'=>'入库子批',
                    'location_id'=>'库位','expected_milli'=>'预计','counted_milli'=>'实点','accepted_milli'=>'入库','journal_id'=>'凭证'],$order['receipts']),
                $this->table('收货差异',['id'=>'差异单','receipt_id'=>'收货编号','site_id'=>'责任工厂','kind'=>'类型','qty_milli'=>'原差异',
                    'pending_milli'=>'待处理','explanation'=>'说明'],$order['variances']),
                $this->table('差异处理记录',['variance_id'=>'差异单','linked_receipt_id'=>'补收单','kind'=>'处置','qty_milli'=>'处理数量',
                    'evidence'=>'交接凭据','local_ref'=>'盘亏单号','posted_on'=>'记账日','journal_id'=>'凭证','actor'=>'经办人','created_at'=>'登记时间'],$order['resolutions'])]];
    }

    public function finance(?int $id,array $params): array
    {
        $through = Value::day(WebInput::stringParameter($params,'through',gmdate('Y-m-t')));
        $since = Value::day(WebInput::stringParameter($params,'since',substr($through,0,7).'-01'));
        $pairs = (new TransferRegister($this->db,$this->scope))->elimination($through,$id,$since);
        $query = ['since'=>$since,'through'=>$through];
        $tables = [];
        if ($id===null) {
            $groups = [];
            foreach ($pairs as &$pair) {
                $currency = $pair['currency'];
                $groups[$currency] ??= ['currency'=>$currency,'ar_minor'=>0,'ap_minor'=>0,'matched_minor'=>0,'outstanding_minor'=>0];
                foreach (['ar_minor','ap_minor','matched_minor'] as $column) {
                    $groups[$currency][$column] += $pair[$column];
                }
                $groups[$currency]['outstanding_minor'] += abs($pair['remaining_minor']);
                $pair['_links']['pair_id'] = WebView::url('eliminations',$pair['pair_id'],$query);
            }
            unset($pair);
            $tables[] = $this->table('分币种抵销汇总',['currency'=>'本位币','ar_minor'=>'累计应收','ap_minor'=>'累计应付',
                'matched_minor'=>'按交易配对','outstanding_minor'=>'未配平金额合计'],array_values($groups));
        }
        $tables[] = $this->table('内部交易',['pair_id'=>'交易编号','dispatch_ref'=>'发运单','shipment_lot'=>'发运批次','currency'=>'币种',
            'period_ar_minor'=>'期间应收','period_ap_minor'=>'期间应付','ar_minor'=>'截至期末应收','ap_minor'=>'截至期末应付',
            'matched_minor'=>'可抵销','remaining_minor'=>'单边差额','dispatch_journal'=>'发出凭证'],$pairs);
        if ($id!==null) {
            $pair = $pairs[0];
            $tables[] = $this->table('对应收货批',['receipt_ref'=>'本厂收货单','received_on'=>'日期','destination_lot'=>'子批号',
                'accepted_milli'=>'实收量','ap_minor'=>'应付金额','receipt_journal'=>'收货凭证'],$pair['receipts']);
            $identity = new Identity($this->db);
            $vouchers = [[$pair['source_org'],$pair['dispatch_journal']]];
            foreach ($pair['receipts'] as $receipt) {
                $vouchers[] = [$pair['destination_org'],$receipt['receipt_journal']];
            }
            if (!empty($pair['losses'])) {
                $tables[] = $this->table('目的厂已承担盘亏',['local_ref'=>'盘亏单','posted_on'=>'日期','qty_milli'=>'盘亏数量',
                    'ap_minor'=>'应付金额','journal_id'=>'凭证','evidence'=>'认定依据'],$pair['losses']);
                foreach ($pair['losses'] as $loss) {
                    $vouchers[] = [$pair['destination_org'],$loss['journal_id']];
                }
            }
            foreach ($vouchers as [$org,$journal]) {
                if ($journal!==null) {
                    $ledger = new GeneralLedger($this->db,$identity->scope($this->scope->user,$org));
                    $record = $ledger->journal($journal);
                    $tables[] = $this->table('法人 '.$org.' · 凭证 '.$journal.' · '.$record['memo'],
                        ['account_code'=>'科目','currency'=>'币种','debit_minor'=>'借方','credit_minor'=>'贷方'],$record['lines']);
                }
            }
        }
        return ['title'=>'集团往来抵销','filters'=>[$this->field('since','期间起始','date',['value'=>$since]),
            $this->field('through','期间截止','date',['value'=>$through])],'filter_parameters'=>$id===null?[]:['id'=>$id],'tables'=>$tables];
    }

    private function mayHandle(int $org,int $site): bool
    {
        return $org===$this->scope->org && $this->scope->can('warehouse') && ($this->scope->can('admin') ||
            $this->db->row('SELECT user_id FROM network_warehouse_grants WHERE site_id=? AND user_id=? AND enabled=1',[$site,$this->scope->user])!==null);
    }

    private function locationField(int $site): array
    {
        $rows = $this->db->rows('SELECT l.id,l.code,l.name FROM network_location_sites m JOIN locations l ON l.id=m.location_id
            WHERE m.site_id=? ORDER BY l.code',[$site]);
        return $this->field('location','本厂库位','select',['options'=>$this->options($rows,'code','name')]);
    }
}
