<?php

declare(strict_types=1);

namespace ManufacturingErp;

/** A read-only operator report that also runs against an unmodified 1.1 database. */
final class NetworkPreflight
{
    public function __construct(private readonly Database $db)
    {
    }

    public function inspect(int $tenant): array
    {
        $this->db->run('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return $this->db->transaction(function () use ($tenant): array {
            $organizations = $this->db->rows('SELECT id,code,currency FROM organizations WHERE tenant_id=? ORDER BY id',[$tenant]);
            Value::assert($organizations!==[],'没有该集团的法人账套。',404);
            $installed = $this->db->row("SELECT version FROM schema_migrations WHERE version='018_factory_ownership'")!==null;
            $reports = [];
            foreach ($organizations as $org) {
                $reports[] = $org + ['ledger'=>$this->ledger($org['id']),'ownership'=>$this->ownership($org['id'],$installed)];
            }
            return ['tenant_id'=>$tenant,'ownership_schema'=>$installed,'organizations'=>$reports];
        });
    }

    private function ledger(int $org): array
    {
        $result = [];
        foreach (['work_orders'=>'id', 'stock_lots'=>'id', 'stock_balances'=>'lot_id,location_id,quality',
            'stock_lot_values'=>'lot_id', 'stock_movements'=>'id','journal_lines'=>'journal_id,line_no'] as $table=>$order) {
            $hash = hash_init('sha256');
            $count = 0;
            foreach ($this->db->stream("SELECT * FROM $table WHERE org_id=? ORDER BY $order",[$org]) as $row) {
                hash_update($hash,Value::json($row)."\n");
                ++$count;
            }
            $result[$table] = ['rows'=>$count,'sha256'=>hash_final($hash)];
        }
        $result['balances'] = $this->db->rows('SELECT i.code,i.base_uom,b.quality,SUM(b.qty_milli) AS qty_milli
            FROM stock_balances b JOIN stock_lots l ON l.org_id=b.org_id AND l.id=b.lot_id JOIN items i ON i.id=l.item_id
            WHERE b.org_id=? GROUP BY i.id,i.code,i.base_uom,b.quality ORDER BY i.id,b.quality',[$org]);
        // Old lots may predate the valuation table. Use the same rounding as StockValuation::onHand.
        $result['carrying_minor'] = $this->db->row('SELECT COALESCE(SUM(COALESCE(v.carrying_minor,
            ROUND(IFNULL(b.quantity,0)*l.unit_cost_minor/1000,0))),0) AS total FROM stock_lots l
            LEFT JOIN stock_lot_values v ON v.org_id=l.org_id AND v.lot_id=l.id
            LEFT JOIN (SELECT lot_id,SUM(qty_milli) quantity FROM stock_balances WHERE org_id=? GROUP BY lot_id) b ON b.lot_id=l.id
            WHERE l.org_id=?',[$org,$org])['total'];
        return $result;
    }

    private function ownership(int $org,bool $installed): array
    {
        $result = [];
        foreach (['location'=>['locations','network_location_sites','location_id'], 'work'=>['work_orders','network_work_sites','work_id']] as $kind=>[$table,$mapping,$column]) {
            $total = (int)$this->db->row("SELECT COUNT(*) AS n FROM $table WHERE org_id=?",[$org])['n'];
            if ($installed) {
                $pending = $this->db->rows("SELECT e.id,COALESCE(i.reason,'awaiting_assignment') reason FROM $table e
                    LEFT JOIN $mapping m ON m.org_id=e.org_id AND m.$column=e.id
                    LEFT JOIN network_ownership_issues i ON i.org_id=e.org_id AND i.kind=? AND i.entity_id=e.id AND i.resolved_at IS NULL
                    WHERE e.org_id=? AND m.site_id IS NULL ORDER BY e.id",[$kind,$org]);
            } else {
                $pending = $this->db->rows("SELECT id,'legacy_without_site' reason FROM $table WHERE org_id=? ORDER BY id",[$org]);
            }
            $result[$kind] = ['total'=>$total,'assigned'=>$total-count($pending),'pending'=>$pending];
        }
        return $result;
    }
}
