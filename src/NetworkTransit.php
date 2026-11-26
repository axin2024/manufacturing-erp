<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class NetworkTransit extends Service
{
    public function authorize(int $site,int $user,bool $enabled): void
    {
        $this->mutate(function(int $org) use ($site,$user,$enabled): void {
            Value::assert($this->db->row('SELECT id FROM network_sites WHERE org_id=? AND id=?',[$org,$site])!==null,'工厂不存在。',404);
            if ($enabled) {
                Value::assert((new Identity($this->db))->scope($user,$org)->can('warehouse'),'请先授予本法人仓储岗位。',409);
            }
            $this->db->run('INSERT INTO network_warehouse_grants (org_id,site_id,user_id,enabled) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE enabled=?',[$org,$site,$user,(int)$enabled,(int)$enabled]);
            $this->audit('network.warehouse.grant',$site,['user'=>$user,'enabled'=>$enabled]);
        },'admin');
    }

    private function lockOrder(int $id,string $side): array
    {
        $row = $this->db->row("SELECT t.*,s.org_id AS source_org,d.org_id AS destination_org FROM network_transfer_orders t
            JOIN network_sites s ON s.id=t.source_site JOIN network_sites d ON d.id=t.destination_site
            WHERE t.id=? AND t.tenant_id=? AND $side.org_id=? FOR UPDATE",[$id,$this->scope->tenant,$this->scope->org]);
        Value::assert($row!==null,'当前工厂没有此调拨操作。',404);
        $site = $side==='s' ? $row['source_site'] : $row['destination_site'];
        Value::assert($this->scope->can('admin') || $this->db->row('SELECT site_id FROM network_warehouse_grants
            WHERE site_id=? AND user_id=? AND enabled=1',[$site,$this->scope->user])!==null,'缺少本厂仓储授权。',403);
        return $row;
    }

    private function checkLocation(int $site,int $location): void
    {
        Value::assert($this->db->row('SELECT site_id FROM network_location_sites WHERE site_id=? AND org_id=? AND location_id=?',
            [$site,$this->scope->org,$location])!==null,'库位不属于本次收发工厂。',404);
    }

    public function ship(string $key,int $order,string $reference,int $lot,int $location,int $quantity,string $date): array
    {
        $reference = Value::code($reference,'本厂发运单号');
        Value::quantity($quantity);
        Value::day($date);
        $args = [$order,$reference,$lot,$location,$quantity,$date];
        return $this->mutate(function(int $org) use ($args,$key,$order,$reference,$lot,$location,$quantity,$date): array {
            $transfer = $this->lockOrder($order,'s');
            return $this->replay('network.ship',$key,$args,function() use ($args,$org,$order,$transfer,$reference,$lot,$location,$quantity,$date): array {
                $digest = hash('sha256',Value::json($args));
                $prior = $this->db->row('SELECT id,payload_digest FROM network_shipments WHERE org_id=? AND local_ref=?',[$org,$reference]);
                if ($prior!==null) {
                    Value::assert(hash_equals($prior['payload_digest'],$digest),'发运单号已用于不同业务。',409);
                    return $this->shipmentResult($prior['id']);
                }
                $this->checkLocation($transfer['source_site'],$location);
                Value::assert($quantity<=$transfer['ordered_milli']-$transfer['shipped_milli'],'发运数量超过调拨未发量。',409);
                $stock = new StockLedger($this->db,$this->scope);
                $origin = $stock->lot($lot);
                Value::assert($origin['item_id']===$transfer['source_item_id'],'批次物料与已签认的发运料号不符。',409);
                $cost = (new StockValuation($this->db,$this->scope))->take($lot,$quantity);
                $sameOrg = $transfer['source_org']===$transfer['destination_org'];
                $price = $transfer['unit_price_minor'];
                $value = $sameOrg ? $cost : Value::extendedPrice($transfer['shipped_milli']+$quantity,$price)
                    - Value::extendedPrice($transfer['shipped_milli'],$price);
                $id = $this->db->insert('INSERT INTO network_shipments (transfer_id,org_id,local_ref,payload_digest,lot_id,location_id,
                    qty_milli,carrying_minor,transfer_minor,posted_on,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$order,$org,$reference,$digest,$lot,$location,$quantity,$cost,$value,$date,$this->scope->user]);
                $movement = $stock->post($lot,$location,'available',-$quantity,$date,'network_dispatch',$id);
                $ledger = new FinanceLedger($this->db,$this->scope);
                $lines = [];
                foreach ([($sameOrg?'FACTORY_TRANSIT':'DUE_FROM')=>$value,'STOCK'=>-$cost,'TRANSFER_MARGIN'=>$cost-$value] as $account=>$signed) {
                    if ($signed!==0) {
                        $lines[] = $ledger->line($account,$signed);
                    }
                }
                $journal = $lines ? $ledger->post('network_dispatch',$id,$date,'跨厂发运 '.$reference,$lines) : null;
                $this->db->run('UPDATE network_shipments SET movement_id=?,journal_id=? WHERE id=?',[$movement,$journal,$id]);
                $this->db->run('UPDATE network_transfer_orders SET shipped_milli=shipped_milli+? WHERE id=?',[$quantity,$order]);
                $this->audit('network.dispatched',$id,['transfer_id'=>$order,'movement_id'=>$movement,'journal_id'=>$journal]);
                return $this->shipmentResult($id);
            });
        },'warehouse');
    }

    private function shipmentResult(int $id): array
    {
        return $this->db->row('SELECT s.id AS shipment_id,s.transfer_id,s.local_ref,l.lot_code AS shipment_lot,s.qty_milli,
            s.movement_id,s.journal_id,s.transfer_minor FROM network_shipments s JOIN stock_lots l ON l.id=s.lot_id WHERE s.id=?',[$id]);
    }

    public function receive(string $key,int $order,int $shipment,string $reference,int $location,int $expected,int $counted,
        string $date,string $reason='',?int $priorVariance=null): array
    {
        $reference = Value::code($reference,'本厂收货单号');
        Value::quantity($expected);
        Value::assert($counted>=0 && $counted<=Value::MAX_QUANTITY,'实点数量越界。');
        Value::day($date);
        $reason = trim($reason);
        if ($expected!==$counted || $reason!=='') {
            $reason = Value::text($reason,255,'差异说明');
        }
        $args = [$order,$shipment,$reference,$location,$expected,$counted,$date,$reason,$priorVariance];
        return $this->mutate(function(int $org) use ($args,$key,$order,$shipment,$reference,$location,$expected,$counted,$date,$reason,$priorVariance): array {
            $transfer = $this->lockOrder($order,'d');
            return $this->replay('network.receive',$key,$args,function() use ($args,$org,$transfer,$order,$shipment,$reference,$location,$expected,$counted,$date,$reason,$priorVariance): array {
                $digest = hash('sha256',Value::json($args));
                $prior = $this->db->row('SELECT id,payload_digest FROM network_receipts WHERE org_id=? AND local_ref=?',[$org,$reference]);
                if ($prior!==null) {
                    Value::assert(hash_equals($prior['payload_digest'],$digest),'收货单号已用于不同业务。',409);
                    return $this->receiptResult($prior['id']);
                }
                $this->checkLocation($transfer['destination_site'],$location);
                $ship = $this->db->row('SELECT s.*,l.lot_code,i.base_uom,l.unit_cost_minor FROM network_shipments s
                    JOIN stock_lots l ON l.id=s.lot_id JOIN items i ON i.id=l.item_id WHERE s.id=? AND s.transfer_id=? FOR UPDATE',[$shipment,$order]);
                Value::assert($ship!==null,'发运批次不属于该调拨单。',404);
                Value::assert($date>=$ship['posted_on'],'收货日期早于发运。',409);
                $outstanding = $ship['qty_milli']-$ship['received_milli']-$ship['lost_milli'];
                $shorts = $this->db->rows("SELECT v.id,v.qty_milli-COALESCE((SELECT SUM(x.qty_milli) FROM network_variance_resolutions x
                    WHERE x.variance_id=v.id),0) AS remaining FROM network_variances v JOIN network_receipts r ON r.id=v.receipt_id
                    WHERE r.shipment_id=? AND v.kind='short'",[$shipment]);
                $open = array_column($shorts,'remaining','id');
                if ($priorVariance===null) {
                    Value::assert($expected<=$outstanding-array_sum($open),'预计本批量超过未验数量，补收请关联原差异单。',409);
                } else {
                    Value::assert(isset($open[$priorVariance]) && $expected<=$open[$priorVariance] && $counted===$expected,
                        '补收数量须与本次实收一致且不超过该短收差异。',409);
                }
                $this->openPeriod($date);
                $accepted = min($expected,$counted);
                $settled = $ship['received_milli']+$ship['lost_milli'];
                $amount = Value::allocation($ship['transfer_minor'],$settled+$accepted,$ship['qty_milli'])
                    - Value::allocation($ship['transfer_minor'],$settled,$ship['qty_milli']);
                $id = $this->db->insert('INSERT INTO network_receipts (shipment_id,org_id,local_ref,payload_digest,location_id,
                    expected_milli,counted_milli,accepted_milli,amount_minor,posted_on,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$shipment,$org,$reference,$digest,$location,$expected,$counted,$accepted,$amount,$date,$this->scope->user]);
                if ($accepted>0) {
                    $sameOrg = $transfer['source_org']===$transfer['destination_org'];
                    $stock = (new StockLedger($this->db,$this->scope))->createLot($transfer['item_id'],$location,'TR-'.$shipment.'-'.$id,
                        $ship['base_uom'],$accepted,$sameOrg?$ship['unit_cost_minor']:$transfer['unit_price_minor'],$date,
                        'available','network_receipt',$id,null,$amount);
                    $journal = (new FinanceLedger($this->db,$this->scope))->move('network_receipt',$id,$date,'跨厂收货 '.$reference,
                        'STOCK',$sameOrg?'FACTORY_TRANSIT':'DUE_TO',$amount);
                    $this->db->run('UPDATE network_receipts SET lot_id=?,movement_id=?,journal_id=? WHERE id=?',[$stock['lot_id'],$stock['movement_id'],$journal,$id]);
                    $this->db->run('UPDATE network_shipments SET received_milli=received_milli+? WHERE id=?',[$accepted,$shipment]);
                    $this->db->run('UPDATE network_transfer_orders SET received_milli=received_milli+? WHERE id=?',[$accepted,$order]);
                }
                if ($expected!==$counted) {
                    $this->db->run('INSERT INTO network_variances (receipt_id,site_id,kind,qty_milli,explanation) VALUES (?,?,?,?,?)',
                        [$id,$transfer['destination_site'],$expected>$counted?'short':'over',abs($expected-$counted),$reason]);
                }
                if ($priorVariance!==null) {
                    $this->db->run("INSERT INTO network_variance_resolutions (variance_id,linked_receipt_id,kind,qty_milli,evidence,actor_id)
                        VALUES (?,?,'received',?,?,?)",[$priorVariance,$id,$accepted,$reference,$this->scope->user]);
                }
                $this->audit('network.received',$id,['shipment_id'=>$shipment,'expected'=>$expected,'counted'=>$counted,'accepted'=>$accepted]);
                return $this->receiptResult($id);
            });
        },'warehouse');
    }

    public function returnExcess(string $key,int $order,int $variance,int $quantity,string $evidence): array
    {
        Value::quantity($quantity);
        $evidence = Value::text($evidence,255,'退回凭据');
        return $this->mutate(function() use ($key,$order,$variance,$quantity,$evidence): array {
            $this->lockOrder($order,'d');
            return $this->replay('network.return_excess',$key,[$order,$variance,$quantity,$evidence],function() use ($order,$variance,$quantity,$evidence): array {
                $row = $this->db->row("SELECT v.qty_milli-COALESCE((SELECT SUM(x.qty_milli) FROM network_variance_resolutions x
                    WHERE x.variance_id=v.id),0) AS remaining FROM network_variances v JOIN network_receipts r ON r.id=v.receipt_id
                    JOIN network_shipments s ON s.id=r.shipment_id WHERE s.transfer_id=? AND v.kind='over' AND v.id=?",[$order,$variance]);
                Value::assert($row!==null,'此调拨单没有该溢收记录。',404);
                $previous = $this->db->row("SELECT id,qty_milli FROM network_variance_resolutions
                    WHERE variance_id=? AND kind='returned' AND evidence=?",[$variance,$evidence]);
                if ($previous!==null) {
                    Value::assert($quantity===(int)$previous['qty_milli'],'同一退回凭据的数量不符。',409);
                    return ['resolution_id'=>$previous['id'],'transfer_id'=>$order,'variance_id'=>$variance,'returned_milli'=>$quantity];
                }
                Value::assert($quantity<=$row['remaining'],'退回量超过未处理溢收。',409);
                $id = $this->db->insert("INSERT INTO network_variance_resolutions (variance_id,kind,qty_milli,evidence,actor_id)
                    VALUES (?,'returned',?,?,?)",[$variance,$quantity,$evidence,$this->scope->user]);
                $this->audit('network.excess.returned',$variance,['resolution'=>$id,'evidence'=>$evidence,'quantity'=>$quantity]);
                return ['resolution_id'=>$id,'transfer_id'=>$order,'variance_id'=>$variance,'returned_milli'=>$quantity];
            });
        },'warehouse');
    }

    public function recordLoss(string $key,int $order,int $variance,int $qty,string $ref,string $date,string $evidence): array
    {
        Value::quantity($qty);
        $ref = Value::code($ref,'盘亏单号');
        Value::day($date);
        $evidence = Value::text($evidence,255,'责任认定依据');
        return $this->mutate(function (int $org) use ($key,$order,$variance,$qty,$ref,$date,$evidence): array {
            $transfer = $this->db->row('SELECT t.id,s.org_id AS source_org FROM network_transfer_orders t
                JOIN network_sites d ON d.id=t.destination_site JOIN network_sites s ON s.id=t.source_site
                WHERE t.id=? AND d.org_id=? FOR UPDATE',[$order,$org]);
            Value::assert($transfer!==null,'当前法人没有该收货调拨单。',404);
            return $this->replay('network.loss',$key,[$order,$variance,$qty,$ref,$date,$evidence],function () use ($org,$order,$transfer,$variance,$qty,$ref,$date,$evidence): array {
                $row = $this->db->row("SELECT h.*,r.posted_on AS receipt_date,v.qty_milli-COALESCE((SELECT SUM(x.qty_milli)
                    FROM network_variance_resolutions x WHERE x.variance_id=v.id),0) outstanding
                    FROM network_variances v JOIN network_receipts r ON r.id=v.receipt_id JOIN network_shipments h ON h.id=r.shipment_id
                    WHERE v.id=? AND v.kind='short' AND h.transfer_id=?",[$variance,$order]);
                Value::assert($row!==null,'该调拨单没有此短收差异。',404);
                $existing = $this->db->row('SELECT id AS resolution_id,variance_id,qty_milli,posted_on,evidence,amount_minor,journal_id
                    FROM network_variance_resolutions WHERE org_id=? AND local_ref=?',[$org,$ref]);
                if ($existing!==null) {
                    Value::assert([$variance,$qty,$date,$evidence]===[$existing['variance_id'],$existing['qty_milli'],$existing['posted_on'],$existing['evidence']],
                        '盘亏单号已用于不同业务。',409);
                    return $existing + ['transfer_id'=>$order];
                }
                Value::assert($row['outstanding']>=$qty && $date>=$row['receipt_date'],'盘亏量超过待处理短收或日期早于收货。',409);
                $this->openPeriod($date);
                $settled = $row['lost_milli']+$row['received_milli'];
                $amount = Value::allocation($row['transfer_minor'],$settled+$qty,$row['qty_milli'])
                    - Value::allocation($row['transfer_minor'],$settled,$row['qty_milli']);
                $id = $this->db->insert("INSERT INTO network_variance_resolutions (variance_id,org_id,local_ref,posted_on,kind,qty_milli,amount_minor,evidence,actor_id)
                    VALUES (?,?,?,?,'loss',?,?,?,?)",[$variance,$org,$ref,$date,$qty,$amount,$evidence,$this->scope->user]);
                $credit = $transfer['source_org']===$org ? 'FACTORY_TRANSIT' : 'DUE_TO';
                $journal = (new FinanceLedger($this->db,$this->scope))->move('network_loss',$id,$date,'目的厂承担在途盘亏 '.$ref,'MATERIAL_LOSS',$credit,$amount);
                $this->db->run('UPDATE network_variance_resolutions SET journal_id=? WHERE id=?',[$journal,$id]);
                $this->db->run('UPDATE network_shipments SET lost_milli=lost_milli+? WHERE id=?',[$qty,$row['id']]);
                $this->db->run('UPDATE network_transfer_orders SET lost_milli=lost_milli+? WHERE id=?',[$qty,$order]);
                $this->audit('network.loss.confirmed',$id,['transfer_id'=>$order,'variance_id'=>$variance,'evidence'=>$evidence,'journal_id'=>$journal]);
                return ['resolution_id'=>$id,'transfer_id'=>$order,'variance_id'=>$variance,'qty_milli'=>$qty,'posted_on'=>$date,'evidence'=>$evidence,'amount_minor'=>$amount,'journal_id'=>$journal];
            });
        },'finance');
    }

    private function receiptResult(int $id): array
    {
        return $this->db->row('SELECT r.id AS receipt_id,s.transfer_id,r.local_ref,r.shipment_id,l.lot_code AS shipment_lot,r.lot_id AS destination_lot_id,
            r.accepted_milli,r.amount_minor,r.movement_id,r.journal_id,v.id AS variance_id FROM network_receipts r
            JOIN network_shipments s ON s.id=r.shipment_id JOIN stock_lots l ON l.id=s.lot_id
            LEFT JOIN network_variances v ON v.receipt_id=r.id WHERE r.id=?',[$id]);
    }
}
