<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class TransferRegister extends Service
{
    private function visibility(): string
    {
        if ($this->scope->can('finance','planner')) {
            return '(s.org_id=? OR d.org_id=?)';
        }
        $this->requireRole('warehouse');
        return 'EXISTS (SELECT 1 FROM network_warehouse_grants g WHERE g.org_id=? AND g.user_id=? AND g.enabled=1
            AND g.site_id IN (s.id,d.id))';
    }

    private function parameters(): array
    {
        return [$this->scope->tenant,$this->scope->org,$this->scope->can('finance','planner') ? $this->scope->org : $this->scope->user];
    }

    private function query(): string
    {
        return 'SELECT t.*,s.code AS source,d.code AS destination,s.org_id AS source_org,d.org_id AS destination_org,i.code AS item,si.code AS source_item,
            t.shipped_milli-t.received_milli AS transit_milli,CASE WHEN c.transfer_id IS NOT NULL THEN \'已对清\'
                WHEN t.shipped_milli=t.ordered_milli AND t.received_milli+t.lost_milli=t.ordered_milli THEN IF(t.lost_milli>0,\'盘亏已处理\',\'收齐\')
                WHEN t.received_milli>0 THEN \'部分到货\' WHEN t.shipped_milli>0 THEN \'已发运\' ELSE \'双厂已确认\' END AS status
            FROM network_transfer_orders t LEFT JOIN network_clearances c ON c.transfer_id=t.id
            JOIN network_sites s ON s.id=t.source_site JOIN network_sites d ON d.id=t.destination_site JOIN items i ON i.id=t.item_id JOIN items si ON si.id=t.source_item_id
            WHERE t.tenant_id=? AND '.$this->visibility();
    }

    public function list(int $before): array
    {
        return $this->db->rows($this->query().' AND t.id<? ORDER BY t.id DESC LIMIT 51',[...$this->parameters(),$before]);
    }

    public function detail(int $order): array
    {
        $row = $this->db->row($this->query().' AND t.id=?',[...$this->parameters(),$order]);
        Value::assert($row!==null,'本账套没有该调拨单。',404);
        $row['shipments'] = $this->db->rows('SELECT s.id,s.local_ref,s.posted_on,l.lot_code AS shipment_lot,s.location_id,
            s.qty_milli,s.received_milli,s.lost_milli,s.qty_milli-s.received_milli AS transit_milli,s.movement_id,s.journal_id
            FROM network_shipments s JOIN stock_lots l ON l.id=s.lot_id WHERE s.transfer_id=? ORDER BY s.id',[$order]);
        $row['receipts'] = $this->db->rows('SELECT r.id,r.shipment_id,r.local_ref,r.posted_on,o.lot_code AS shipment_lot,
            l.lot_code AS destination_lot,r.expected_milli,r.counted_milli,r.accepted_milli,r.movement_id,r.journal_id,r.location_id
            FROM network_receipts r JOIN network_shipments s ON s.id=r.shipment_id JOIN stock_lots o ON o.id=s.lot_id
            LEFT JOIN stock_lots l ON l.id=r.lot_id WHERE s.transfer_id=? ORDER BY r.id',[$order]);
        $row['variances'] = $this->db->rows('SELECT v.*,r.shipment_id,v.qty_milli-COALESCE((SELECT SUM(x.qty_milli)
            FROM network_variance_resolutions x WHERE x.variance_id=v.id),0) AS pending_milli FROM network_variances v
            JOIN network_receipts r ON r.id=v.receipt_id JOIN network_shipments s ON s.id=r.shipment_id
            WHERE s.transfer_id=? ORDER BY v.id',[$order]);
        $row['resolutions'] = $this->db->rows('SELECT x.id,x.variance_id,x.linked_receipt_id,x.kind,x.qty_milli,x.evidence,x.local_ref,x.posted_on,x.journal_id,
            u.name AS actor,x.created_at FROM network_variance_resolutions x JOIN users u ON u.id=x.actor_id
            JOIN network_variances v ON v.id=x.variance_id JOIN network_receipts r ON r.id=v.receipt_id
            JOIN network_shipments s ON s.id=r.shipment_id WHERE s.transfer_id=? ORDER BY x.id',[$order]);
        $row['clearance'] = $this->db->row('SELECT c.posted_on,c.evidence,u.name AS actor FROM network_clearances c JOIN users u ON u.id=c.actor_id
            WHERE c.transfer_id=?',[$order]);
        return $row;
    }

    public function clear(string $key,int $order,string $day,string $evidence): array
    {
        Value::day($day);
        $evidence = Value::text($evidence,255,'对清依据');
        return $this->mutate(function (int $org) use ($key,$order,$day,$evidence): array {
            $lock = $this->db->row('SELECT t.id FROM network_transfer_orders t JOIN network_sites s ON s.id=t.source_site
                JOIN network_sites d ON d.id=t.destination_site WHERE t.tenant_id=? AND t.id=? AND ? IN (s.org_id,d.org_id) FOR UPDATE',
                [$this->scope->tenant,$order,$org]);
            Value::assert($lock!==null,'当前账套没有该调拨单。',404);
            $record = $this->detail($order);
            $identity = new Identity($this->db);
            foreach (array_unique([$record['source_org'],$record['destination_org']]) as $party) {
                Value::assert($identity->scope($this->scope->user,$party)->can('finance'),'集团对清需要双方财务权限。',403);
            }
            return $this->replay('network.clear',$key,[$order,$day,$evidence],function () use ($org,$record,$order,$day,$evidence): array {
                if ($record['clearance']) {
                    Value::assert($record['clearance']['posted_on']===$day && $record['clearance']['evidence']===$evidence,'已按其他依据对清。',409);
                } else {
                    Value::assert($record['received_milli']+$record['lost_milli']===$record['ordered_milli']
                        && array_sum(array_column($record['variances'],'pending_milli'))===0,'仍有未发、未收或待处理差异。',409);
                    $dates = [...array_column($record['receipts'],'posted_on'),...array_filter(array_column($record['resolutions'],'posted_on'))];
                    Value::assert(!$dates || max($dates)<=$day,'对清日期早于单据记账日。',409);
                    if ($record['source_org']!==$record['destination_org']) {
                        foreach ($record['shipments'] as $shipment) {
                            $pair = $this->elimination($day,$shipment['id'])[0];
                            Value::assert($pair['remaining_minor']===0,'发出与接收方凭证尚有差额。',409);
                        }
                    }
                    $this->db->run('INSERT INTO network_clearances (transfer_id,org_id,actor_id,posted_on,evidence) VALUES (?,?,?,?,?)',
                        [$order,$org,$this->scope->user,$day,$evidence]);
                    $this->audit('network.cleared',$order,['posted_on'=>$day,'evidence'=>$evidence]);
                }
                return ['transfer_id'=>$order,'posted_on'=>$day,'evidence'=>$evidence,'status'=>'已对清'];
            });
        },'finance');
    }

    /** Posted balances for each shipment. A gap on one shipment never offsets a gap on another. */
    public function elimination(string $through, ?int $shipment=null, ?string $since=null): array
    {
        $this->requireRole('finance');
        Value::day($through);
        $since = $since===null ? '1000-01-01' : Value::day($since);
        Value::assert($since<=$through,'起始日期晚于截止日期。');
        $rows = $this->db->rows("SELECT h.id AS pair_id,t.id AS transfer_id,h.local_ref AS dispatch_ref,h.posted_on,
            l.lot_code AS shipment_lot,t.currency,h.org_id AS source_org,d.org_id AS destination_org,
            h.journal_id AS dispatch_journal,h.qty_milli,h.transfer_minor,
            COALESCE((SELECT SUM(CAST(j.debit_minor AS SIGNED)-CAST(j.credit_minor AS SIGNED))
                FROM journal_lines j WHERE j.org_id=h.org_id AND j.journal_id=h.journal_id AND j.account_code='DUE_FROM' AND j.currency=t.currency),0) AS ar_minor,
            r.id AS receipt_id,r.local_ref AS receipt_ref,r.journal_id AS receipt_journal,r.posted_on AS received_on,
            r.accepted_milli,dl.lot_code AS destination_lot,
            COALESCE((SELECT SUM(CAST(j.credit_minor AS SIGNED)-CAST(j.debit_minor AS SIGNED)) FROM journal_lines j
                WHERE j.org_id=r.org_id AND j.journal_id=r.journal_id AND j.account_code='DUE_TO' AND j.currency=t.currency),0) AS ap_minor
            FROM network_shipments h JOIN network_transfer_orders t ON t.id=h.transfer_id JOIN network_sites d ON d.id=t.destination_site
            JOIN stock_lots l ON l.id=h.lot_id LEFT JOIN network_receipts r ON r.shipment_id=h.id AND r.posted_on<=?
            LEFT JOIN stock_lots dl ON dl.id=r.lot_id WHERE t.tenant_id=? AND h.org_id<>d.org_id AND h.posted_on<=?
            AND EXISTS (SELECT 1 FROM memberships m WHERE m.org_id=h.org_id AND m.user_id=? AND m.enabled=1 AND m.role IN ('admin','finance'))
            AND EXISTS (SELECT 1 FROM memberships m WHERE m.org_id=d.org_id AND m.user_id=? AND m.enabled=1 AND m.role IN ('admin','finance'))"
            .($shipment===null?'':' AND h.id=?').' ORDER BY h.id,r.id',
            [$through,$this->scope->tenant,$through,$this->scope->user,$this->scope->user,...($shipment===null?[]:[$shipment])]);
        $pairs = [];
        foreach ($rows as $row) {
            $id = $row['pair_id'];
            if (!isset($pairs[$id])) {
                $pairs[$id] = ['pair_id'=>$id,'transfer_id'=>$row['transfer_id'],'currency'=>$row['currency'],
                    'shipment_lot'=>$row['shipment_lot'],'dispatch_ref'=>$row['dispatch_ref'],'posted_on'=>$row['posted_on'],
                    'source_org'=>$row['source_org'],'destination_org'=>$row['destination_org'],'dispatch_journal'=>$row['dispatch_journal'],
                    'ar_minor'=>(int)$row['ar_minor'],'ap_minor'=>0,'period_ar_minor'=>$row['posted_on'] >= $since ? (int)$row['ar_minor'] : 0,
                    'period_ap_minor'=>0,'receipts'=>[]];
            }
            if ($row['receipt_id']!==null) {
                $pairs[$id]['ap_minor'] += (int)$row['ap_minor'];
                if ($row['received_on'] >= $since) {
                    $pairs[$id]['period_ap_minor'] += (int)$row['ap_minor'];
                }
                $pairs[$id]['receipts'][] = array_intersect_key($row,array_flip(['receipt_id','receipt_ref','receipt_journal','received_on',
                    'accepted_milli','destination_lot','ap_minor']));
            }
        }
        if ($shipment!==null) {
            Value::assert(isset($pairs[$shipment]),'该期间没有可见的双法人往来。',404);
        }
        $losses = $this->db->rows("SELECT h.id AS pair_id,x.local_ref,x.posted_on,x.qty_milli,x.evidence,x.journal_id,
            COALESCE(j.credit_minor,0) AS ap_minor FROM network_variance_resolutions x
            JOIN network_variances v ON v.id=x.variance_id JOIN network_receipts r ON r.id=v.receipt_id JOIN network_shipments h ON h.id=r.shipment_id
            JOIN network_transfer_orders t ON t.id=h.transfer_id LEFT JOIN journal_lines j ON j.org_id=x.org_id AND j.journal_id=x.journal_id
            AND j.account_code='DUE_TO' AND j.currency=t.currency WHERE t.tenant_id=? AND x.kind='loss' AND x.posted_on<=?
            AND x.org_id<>h.org_id AND EXISTS (SELECT 1 FROM memberships a WHERE a.org_id=x.org_id AND a.user_id=? AND a.enabled=1 AND a.role IN ('admin','finance'))
            AND EXISTS (SELECT 1 FROM memberships a WHERE a.org_id=h.org_id AND a.user_id=? AND a.enabled=1 AND a.role IN ('admin','finance'))"
            .($shipment===null?'':' AND h.id=?').' ORDER BY x.id',[$this->scope->tenant,$through,$this->scope->user,$this->scope->user,...($shipment===null?[]:[$shipment])]);
        foreach ($losses as $loss) {
            $id = $loss['pair_id'];
            $pairs[$id]['losses'][] = $loss;
            $pairs[$id]['ap_minor'] += (int)$loss['ap_minor'];
            if ($loss['posted_on'] >= $since) {
                $pairs[$id]['period_ap_minor'] += (int)$loss['ap_minor'];
            }
        }
        foreach ($pairs as &$pair) {
            $pair['matched_minor'] = min($pair['ar_minor'],$pair['ap_minor']);
            $pair['remaining_minor'] = $pair['ar_minor']-$pair['ap_minor'];
        }
        unset($pair);
        return array_values($pairs);
    }
}
