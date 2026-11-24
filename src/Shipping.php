<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Shipping extends Service
{
    public function reserve(string $key, int $demand, int $lot, int $location, int $quantity): array
    {
        Value::quantity($quantity);
        return $this->mutate(fn(int $org): array => $this->replay('shipping.reserve', $key, [$demand, $lot, $location, $quantity],
            function () use ($org, $demand, $lot, $location, $quantity): array {
                $requirement = $this->demand($demand);
                Value::assert($requirement['state'] === 'released'
                    && (int) $requirement['allocated_milli'] + $quantity <= (int) $requirement['qty_milli'],
                    '分配须在已冻结交货需求的未分配数量内。', 409);
                $record = (new StockLedger($this->db, $this->scope))->lot($lot);
                Value::assert((int) $record['item_id'] === (int) $requirement['item_id'], '批次物料和销售交货需求不一致。', 409);
                $this->location($location);
                $stock = $this->db->row("SELECT qty_milli FROM stock_balances WHERE org_id=? AND lot_id=? AND location_id=? AND quality='available'",
                    [$org, $lot, $location]);
                $held = $this->db->row('SELECT COALESCE(SUM(remaining_milli),0) AS total FROM stock_reservations
                    WHERE org_id=? AND lot_id=? AND location_id=?', [$org, $lot, $location]);
                Value::assert((int) ($stock['qty_milli'] ?? 0) - (int) $held['total'] >= $quantity,
                    '库位中已放行且未分配的库存不足。', 409);
                $id = $this->db->insert('INSERT INTO stock_reservations (org_id,demand_id,lot_id,location_id,remaining_milli) VALUES (?,?,?,?,?)',
                    [$org, $demand, $lot, $location, $quantity]);
                $this->db->run('UPDATE sales_demands SET allocated_milli=allocated_milli+? WHERE org_id=? AND id=?', [$quantity, $org, $demand]);
                $result = ['reservation_id' => $id, 'demand_id' => $demand, 'lot_id' => $lot, 'location_id' => $location, 'qty_milli' => $quantity];
                $this->audit('shipping.reserved', $id, $result);
                return $result;
            }), 'warehouse', 'sales');
    }

    public function release(string $key, int $id, string $reason): array
    {
        $reason = Value::text($reason, 255, '解除分配原因');
        return $this->mutate(fn(int $org): array => $this->replay('shipping.release', $key, [$id, $reason],
            function () use ($org, $id, $reason): array {
                $holding = $this->reservation($id);
                $left = (int) $holding['remaining_milli'];
                Value::assert($left > 0, '该分配没有可解除的数量。', 409);
                $this->db->run('UPDATE stock_reservations SET remaining_milli=0 WHERE org_id=? AND id=?', [$org, $id]);
                $this->db->run('UPDATE sales_demands SET allocated_milli=allocated_milli-? WHERE org_id=? AND id=?',
                    [$left, $org, $holding['demand_id']]);
                $this->audit('shipping.released', $id, ['reason' => $reason, 'qty_milli' => $left]);
                return ['reservation_id' => $id, 'released_milli' => $left];
            }), 'warehouse', 'sales');
    }

    public function dispatch(string $key, string $number, int $customer, string $date, array $entries): array
    {
        $number = Value::code($number, '交货单号');
        Value::day($date);
        $entries = $this->normalize($entries);
        return $this->mutate(fn(int $org): array => $this->replay('shipping.dispatch', $key, [$number, $customer, $date, $entries],
            function () use ($org, $number, $customer, $date, $entries): array {
                $this->party($customer, 'customer');
                Value::assert($this->db->row('SELECT id FROM shipments WHERE org_id=? AND number=?', [$org, $number]) === null,
                    '交货单号已经存在。', 409);
                $shipment = $this->db->insert('INSERT INTO shipments (org_id,number,customer_id,dispatched_on,actor_id) VALUES (?,?,?,?,?)',
                    [$org, $number, $customer, $date, $this->scope->user]);
                $lines = [];
                foreach ($entries as $reservation => $quantity) {
                    $lines[] = $this->dispatchLine($shipment, $customer, $date, $reservation, $quantity);
                }
                $result = ['shipment_id' => $shipment, 'customer_id' => $customer, 'lines' => $lines];
                $this->audit('shipping.dispatched', $shipment, $result);
                return $result;
            }), 'warehouse');
    }

    public function shipments(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('sales', 'warehouse', 'finance', 'quality');
        return $this->db->rows('SELECT s.id,s.number,s.dispatched_on,p.code AS customer_code,p.name AS customer
            FROM shipments s JOIN parties p ON p.id=s.customer_id WHERE s.org_id=? AND s.id<? ORDER BY s.id DESC LIMIT 51',
            [$this->scope->org, $before]);
    }

    public function shipment(int $id): array
    {
        $this->requireRole('sales', 'warehouse', 'finance', 'quality');
        $org = $this->scope->org;
        $row = $this->db->row('SELECT s.*,p.code AS customer_code,p.name AS customer FROM shipments s JOIN parties p ON p.id=s.customer_id
            WHERE s.org_id=? AND s.id=?', [$org, $id]);
        Value::assert($row !== null, '交货单不存在。', 404);
        $row['lines'] = $this->db->rows('SELECT s.*,l.lot_code,i.code AS item_code,o.number AS order_number
            FROM shipment_lines s JOIN stock_lots l ON l.id=s.lot_id JOIN items i ON i.id=l.item_id
            JOIN sales_demands d ON d.id=s.demand_id JOIN sales_lines a ON a.id=d.line_id JOIN sales_orders o ON o.id=a.order_id
            WHERE s.org_id=? AND s.shipment_id=? ORDER BY s.id', [$org, $id]);
        return $row;
    }

    public function reservations(int $demand): array
    {
        $this->requireRole('warehouse', 'planner', 'sales', 'finance');
        $this->demand($demand);
        return $this->db->rows('SELECT r.*,l.lot_code,w.code AS location_code FROM stock_reservations r JOIN stock_lots l ON l.id=r.lot_id
            JOIN locations w ON w.id=r.location_id WHERE r.org_id=? AND r.demand_id=? ORDER BY r.id', [$this->scope->org, $demand]);
    }

    private function dispatchLine(int $shipment, int $customer, string $date, int $reservation, int $quantity): array
    {
        $org = $this->scope->org;
        $hold = $this->reservation($reservation);
        $demand = $this->demand((int) $hold['demand_id']);
        Value::assert((int) $demand['customer_id'] === $customer && $demand['state'] === 'released'
            && $quantity <= (int) $hold['remaining_milli'], '交货须属于本客户，且数量在已分配的未发余额内。', 409);
        $before = (int) $demand['dispatched_milli'];
        $after = $before + $quantity;
        Value::assert($after <= (int) $demand['allocated_milli'] && $date >= $demand['latest_delivery'], '交货数量或过账日期超出交货计划。', 409);
        $lot = (int) $hold['lot_id'];
        $location = (int) $hold['location_id'];
        $price = (int) $demand['unit_price_minor'];
        $revenue = Value::extendedPrice($after, $price) - Value::extendedPrice($before, $price);
        $stock = new StockLedger($this->db, $this->scope);
        $value = Value::extendedPrice($quantity, (int) $stock->lot($lot)['unit_cost_minor']);
        $id = $this->db->insert('INSERT INTO shipment_lines
            (org_id,shipment_id,reservation_id,lot_id,demand_id,location_id,qty_milli,revenue_minor,cogs_minor)
            VALUES (?,?,?,?,?,?,?,?,?)', [$org, $shipment, $reservation, $lot, $demand['id'], $location, $quantity, $revenue, $value]);
        $this->db->run('UPDATE stock_reservations SET remaining_milli=remaining_milli-? WHERE org_id=? AND id=?', [$quantity, $org, $reservation]);
        $movement = $stock->post($lot, $location, 'available', -$quantity, $date, 'shipment', $id);
        $state = $after === (int) $demand['qty_milli'] ? 'fulfilled' : 'released';
        $this->db->run('UPDATE sales_demands SET dispatched_milli=?,state=? WHERE org_id=? AND id=?', [$after, $state, $org, $demand['id']]);
        $this->db->run("INSERT INTO demand_commitments (org_id,demand_id,source_kind,source_id,qty_milli,state)
            VALUES (?,?,'shipping',?,?,'completed')", [$org, $demand['id'], $id, $quantity]);
        $outstanding = $this->db->row("SELECT d.id FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id
            WHERE d.org_id=? AND l.order_id=? AND d.state IN ('draft','released') LIMIT 1", [$org, $demand['order_id']]);
        if ($outstanding === null) {
            $this->db->run("UPDATE sales_orders SET state='completed' WHERE org_id=? AND id=?", [$org, $demand['order_id']]);
        }
        return ['shipment_line_id' => $id, 'demand_id' => (int) $demand['id'], 'lot_id' => $lot, 'qty_milli' => $quantity,
            'revenue_minor' => $revenue, 'cogs_minor' => $value, 'movement_id' => $movement];
    }

    private function demand(int $id): array
    {
        $record = $this->db->row("SELECT d.*,o.id AS order_id,o.customer_id,l.item_id,l.unit_price_minor,
            COALESCE((SELECT MAX(h.dispatched_on) FROM shipment_lines s JOIN shipments h ON h.id=s.shipment_id
                WHERE s.org_id=d.org_id AND s.demand_id=d.id),'1000-01-01') AS latest_delivery
            FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id JOIN sales_orders o ON o.id=l.order_id
            WHERE d.org_id=? AND d.id=?", [$this->scope->org, $id]);
        Value::assert($record !== null, '交货需求不存在。', 404);
        return $record;
    }

    private function reservation(int $id): array
    {
        $record = $this->db->row('SELECT * FROM stock_reservations WHERE org_id=? AND id=?', [$this->scope->org, $id]);
        Value::assert($record !== null, '库存分配记录不存在。', 404);
        return $record;
    }

    private function normalize(array $entries): array
    {
        Value::assert(array_is_list($entries) && count($entries) >= 1 && count($entries) <= 100, '交货单须包含 1 至 100 条分配记录。');
        $lines = [];
        foreach ($entries as $entry) {
            $hold = $entry['reservation_id'] ?? null;
            $quantity = $entry['quantity'] ?? null;
            Value::assert(is_int($hold) && is_int($quantity) && !isset($lines[$hold]), '交货分配记录重复或格式无效。');
            $lines[$hold] = Value::quantity($quantity);
        }
        ksort($lines, SORT_NUMERIC);
        return $lines;
    }
}
