<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

final class NetworkSnapshot extends Service
{
    /** Invoke inside the caller's transaction. All ordinary ERP writers lock their organization before mutation. */
    public function lockSites(array $siteIds, string $role = 'planner'): array
    {
        Value::assert(array_is_list($siteIds) && count($siteIds) >= 1 && count($siteIds) <= 100, '请选定 1 至 100 家工厂。');
        $ids = array_map(static fn($id): int => WebInput::integer($id, '工厂编号'), $siteIds);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $params = implode(',', array_fill(0, count($ids), '?'));
        $sites = $this->db->rows("SELECT s.id,s.org_id,s.code,o.currency FROM network_sites s JOIN organizations o ON o.id=s.org_id
            WHERE s.tenant_id=? AND s.id IN ($params) ORDER BY s.id", [$this->scope->tenant, ...$ids]);
        Value::assert(count($sites) === count($ids), '范围内含不可见工厂。', 404);
        $orgs = array_values(array_unique([$this->scope->org, ...array_column($sites, 'org_id')]));
        sort($orgs, SORT_NUMERIC);
        $identity = new Identity($this->db);
        foreach ($orgs as $org) {
            $this->db->row('SELECT id FROM organizations WHERE id=? FOR UPDATE', [$org]);
            $actor = $identity->scope($this->scope->user, $org);
            Value::assert($actor->can($role), '缺少所选法人的计划权限。', 403);
            Value::assert($actor->currency === $this->scope->currency, '选定法人须使用同一本位币。', 409);
        }
        return $sites;
    }

    public function capture(array $sites, string $start, string $end, int $excludeScenario = 0): array
    {
        // IDs originate from lockSites; all inputs are bound even when the set contains a single plant.
        $ids = array_column($sites,'id');
        $in = implode(',', array_fill(0,count($ids),'?'));
        $weekStart = (new DateTimeImmutable($start))->modify('monday this week')->format('Y-m-d');
        $weekEnd = (new DateTimeImmutable($end))->modify('sunday this week')->format('Y-m-d');
        $items = $this->db->rows("SELECT m.site_id,m.item_id,p.sourcing,p.standard_cost_minor,p.lead_days,
            (SELECT b.id FROM boms b WHERE b.org_id=m.org_id AND b.item_id=m.item_id AND b.effective_on<=? ORDER BY b.effective_on DESC LIMIT 1) AS bom_id,
            (SELECT r.id FROM routes r WHERE r.org_id=m.org_id AND r.item_id=m.item_id AND r.effective_on<=? ORDER BY r.effective_on DESC LIMIT 1) AS route_id
            FROM network_site_items m JOIN org_items p ON p.org_id=m.org_id AND p.item_id=m.item_id
            WHERE m.site_id IN ($in) ORDER BY m.site_id,m.item_id", [$start,$start,...$ids]);
        $stock = $this->db->rows("SELECT m.site_id,l.item_id,b.lot_id,b.location_id,b.qty_milli,
            (SELECT COALESCE(SUM(r.remaining_milli),0) FROM stock_reservations r WHERE r.org_id=b.org_id
                AND r.lot_id=b.lot_id AND r.location_id=b.location_id) AS committed_milli
            FROM stock_balances b JOIN network_location_sites m ON m.org_id=b.org_id AND m.location_id=b.location_id
            JOIN stock_lots l ON l.id=b.lot_id WHERE b.quality='available' AND b.qty_milli>0 AND m.site_id IN ($in)
            ORDER BY m.site_id,l.item_id,b.lot_id,b.location_id", $ids);
        $sales = $this->db->rows("SELECT a.site_id,l.item_id,d.id,d.version,d.due_on,d.state,d.qty_milli,d.dispatched_milli,
            (SELECT COALESCE(SUM(r.remaining_milli),0) FROM stock_reservations r WHERE r.org_id=d.org_id AND r.demand_id=d.id) AS reserved_milli
            FROM network_demand_sites a JOIN sales_demands d ON d.id=a.demand_id JOIN sales_lines l ON l.id=d.line_id
            WHERE d.due_on BETWEEN ? AND ? AND a.site_id IN ($in) AND (d.state IN ('released','fulfilled') OR d.dispatched_milli>0)
            ORDER BY d.due_on,d.id", [$weekStart,$weekEnd,...$ids]);
        $forecasts = $this->db->rows("SELECT f.id,f.site_id,f.item_id,f.week_start,f.qty_milli FROM network_forecast_lines f
            WHERE f.week_start BETWEEN ? AND ? AND f.site_id IN ($in) AND NOT EXISTS (SELECT 1 FROM network_forecast_lines n
                WHERE n.site_id=f.site_id AND n.item_id=f.item_id AND n.week_start=f.week_start AND n.id>f.id)
            ORDER BY f.week_start,f.site_id,f.item_id", [$weekStart,$end,...$ids]);
        $work = $this->db->rows("SELECT m.site_id,p.item_id,p.source_id,p.available_on,p.remaining_milli FROM planning_supplies p
            JOIN network_work_sites m ON m.org_id=p.org_id AND m.work_id=p.source_id
            WHERE p.source_kind='work' AND p.remaining_milli>0 AND p.available_on<=? AND m.site_id IN ($in)
            ORDER BY p.available_on,m.site_id,p.source_id", [$end,...$ids]);
        $lanes = $this->db->rows("SELECT id,source_site,destination_site,lead_days,revision FROM network_lanes
            WHERE source_site IN ($in) AND destination_site IN ($in) ORDER BY source_site,destination_site", [...$ids,...$ids]);
        $calendar = $this->db->rows("SELECT site_id,work_date,working FROM network_calendar
            WHERE site_id IN ($in) AND work_date>=? AND work_date<=DATE_ADD(?,INTERVAL 3 YEAR) ORDER BY site_id,work_date",
            [...$ids,$start,$end]);
        $substitutes = $this->db->rows("SELECT lane_id,item_id,source_item_id,revision,evidence FROM network_substitutions
            WHERE enabled=1 AND source_site IN ($in) AND destination_site IN ($in) ORDER BY lane_id,item_id,source_item_id",[...$ids,...$ids]);
        $transfers = $this->db->rows("SELECT t.id,t.source_site,t.destination_site,t.item_id,t.source_item_id,t.promised_on,t.ordered_milli,t.shipped_milli,t.received_milli,t.lost_milli
            FROM network_transfer_orders t JOIN network_plan_lines l ON l.id=t.line_id
            WHERE (t.source_site IN ($in) OR t.destination_site IN ($in)) AND t.received_milli+t.lost_milli<t.ordered_milli
            AND l.scenario_id<>? ORDER BY t.promised_on,t.id", [...$ids,...$ids,$excludeScenario]);
        $plannedWork = $this->db->rows("SELECT m.site_id,w.item_id,w.id,w.due_on,w.qty_milli FROM network_plan_executions e
            JOIN network_plan_lines l ON l.id=e.line_id JOIN work_orders w ON w.id=e.work_id JOIN network_work_sites m ON m.work_id=w.id
            WHERE m.site_id IN ($in) AND w.state IN ('draft','approved') AND w.due_on<=? AND l.scenario_id<>? ORDER BY w.due_on,w.id",
            [...$ids,$end,$excludeScenario]);
        return compact('sites','items','stock','sales','forecasts','work','transfers','plannedWork','lanes','calendar','substitutes');
    }
}
