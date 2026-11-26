<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

final class WeeklyForecast extends Service
{
    public function import(string $key, string $batch, string $csv): array
    {
        $this->requireRole('planner');
        $batch = Value::code($batch, '预测批次');
        Value::assert(strlen($csv) <= 4_194_304, '预测文件超过 4 MiB。', 413);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r\n", "\n", $csv));
        $lines = explode("\n", rtrim($csv, "\n"));
        Value::assert(array_shift($lines) === 'site,item,week_start,quantity' && $lines && count($lines) <= 20000,
            '首行须为 site,item,week_start,quantity，数据须为 1 至 20000 行。');
        $digest = hash('sha256', implode("\n", $lines));
        return $this->mutate(fn(int $org): array => $this->replay('network.forecast', $key, [$batch, $digest],
            function () use ($org, $batch, $lines, $digest): array {
                $existing = $this->db->row('SELECT id,digest FROM network_forecast_batches WHERE org_id=? AND batch_code=?', [$org, $batch]);
                if ($existing !== null) {
                    Value::assert(hash_equals($existing['digest'], $digest), '此预测批次已用于不同的数据。', 409);
                    return ['batch_id' => (int) $existing['id'], 'line_count' => count($lines)];
                }
                (new NetworkPilot($this->db,$this->scope))->admit();
                $batchId = $this->db->insert('INSERT INTO network_forecast_batches (org_id,batch_code,digest,actor_id) VALUES (?,?,?,?)',
                    [$org, $batch, $digest, $this->scope->user]);
                $mappings = [];
                foreach ($this->db->rows('SELECT s.code AS site,i.code AS item,m.site_id,m.item_id
                    FROM network_site_items m JOIN network_sites s ON s.id=m.site_id JOIN items i ON i.id=m.item_id
                    WHERE m.org_id=?', [$org]) as $mapping) {
                    $mappings[$mapping['site']][$mapping['item']] = $mapping;
                }
                $rows = [];
                foreach ($lines as $offset => $line) {
                    $fields = str_getcsv($line, ',', '"', '');
                    $position = '第 ' . ($offset + 2) . ' 行';
                    Value::assert(count($fields) === 4, $position . '需要四列。');
                    [$site, $item, $week, $quantity] = array_map('trim', $fields);
                    $mapping = $mappings[strtoupper($site)][strtoupper($item)] ?? null;
                    Value::assert($mapping !== null, $position . '没有对应的工厂物料资格。');
                    $day = new DateTimeImmutable(Value::day($week));
                    Value::assert($day->format('N') === '1', $position . '起始日期须为周一。');
                    $bucket = implode('/', [$mapping['site_id'], $mapping['item_id'], $week]);
                    Value::assert(!isset($rows[$bucket]), $position . '重复填报同一工厂、物料和预测周。');
                    $rows[$bucket] = [$batchId, $org, $mapping['site_id'], $mapping['item_id'], $week,
                        Value::scaled($quantity, 3, Value::MAX_QUANTITY)];
                }
                $this->db->insertRows('INSERT INTO network_forecast_lines (batch_id,org_id,site_id,item_id,week_start,qty_milli)', array_values($rows));
                $this->audit('network.forecast.imported', $batchId, ['batch' => $batch, 'lines' => count($rows), 'digest' => $digest]);
                return ['batch_id' => $batchId, 'line_count' => count($rows)];
            }), 'planner');
    }

    public function assignDemand(int $demand, int $site): void
    {
        $this->mutate(function (int $org) use ($demand, $site): void {
            $valid = $this->db->row('SELECT d.id FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id
                JOIN network_site_items m ON m.org_id=d.org_id AND m.item_id=l.item_id
                WHERE d.org_id=? AND d.id=? AND m.site_id=?', [$org, $demand, $site]);
            Value::assert($valid !== null, '此账套内没有对应的交付行或工厂物料资格。', 404);
            $old = $this->db->row('SELECT site_id FROM network_demand_sites WHERE org_id=? AND demand_id=?', [$org, $demand]);
            if ($old !== null) {
                Value::assert((int) $old['site_id'] === $site, '此交付行已分配到另一工厂。', 409);
                return;
            }
            (new NetworkPilot($this->db,$this->scope))->admit();
            $this->db->run('INSERT INTO network_demand_sites (org_id,demand_id,site_id) VALUES (?,?,?)', [$org, $demand, $site]);
            $this->audit('network.demand.assigned', $demand, ['site_id' => $site]);
        }, 'sales', 'planner');
    }

    public function detail(int $id): array
    {
        $this->requireRole('planner', 'sales');
        $line = $this->db->row('SELECT f.*,b.batch_code,i.code AS item_code,i.base_uom,s.code AS site_code,
            (SELECT MAX(v.id) FROM network_forecast_lines v WHERE v.site_id=f.site_id AND v.item_id=f.item_id
              AND v.week_start=f.week_start) AS current_line
            FROM network_forecast_lines f JOIN network_forecast_batches b ON b.id=f.batch_id
            JOIN network_sites s ON s.id=f.site_id JOIN items i ON i.id=f.item_id WHERE f.org_id=? AND f.id=?',
            [$this->scope->org, $id]);
        Value::assert($line !== null, '预测行不存在。', 404);
        $orders = $this->db->rows("SELECT d.id AS demand_id,l.order_id,o.number,l.line_no,d.delivery_no,d.due_on,d.state,
            IF(d.state='cancelled',d.dispatched_milli,d.qty_milli) AS booked_milli
            FROM network_demand_sites a JOIN sales_demands d ON d.id=a.demand_id
            JOIN sales_lines l ON l.id=d.line_id JOIN sales_orders o ON o.id=l.order_id
            WHERE a.org_id=? AND a.site_id=? AND l.item_id=? AND d.due_on>=? AND d.due_on<DATE_ADD(?,INTERVAL 7 DAY)
            AND (d.state IN ('released','fulfilled') OR d.dispatched_milli>0) ORDER BY d.due_on,d.id",
            [$this->scope->org, $line['site_id'], $line['item_id'], $line['week_start'], $line['week_start']]);
        $remaining = (int) $line['qty_milli'];
        foreach ($orders as &$order) {
            $order['consumed_milli'] = min($remaining, (int) $order['booked_milli']);
            $remaining -= $order['consumed_milli'];
        }
        unset($order);
        return $line + ['orders' => $orders, 'consumed_milli' => (int) $line['qty_milli'] - $remaining,
            'remaining_milli' => $remaining, 'variance_milli' => array_sum(array_column($orders, 'booked_milli')) - (int) $line['qty_milli']];
    }
}
