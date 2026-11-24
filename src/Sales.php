<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Sales extends Service
{
    public function create(string $key, string $number, int $customer, array $lines): array
    {
        $number = Value::code($number, '销售单号');
        Value::assert(array_is_list($lines) && count($lines) >= 1 && count($lines) <= 100, '销售单须包含 1 至 100 行。');
        $normalized = [];
        foreach ($lines as $line) {
            $item = $line['item'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $price = $line['unit_price_minor'] ?? null;
            $deliveries = $line['deliveries'] ?? null;
            Value::assert(is_int($item) && is_int($quantity) && is_int($price) && is_array($deliveries)
                && array_is_list($deliveries) && count($deliveries) >= 1 && count($deliveries) <= 30,
                '销售行或交期拆分格式无效。');
            $parts = [];
            foreach ($deliveries as $delivery) {
                $date = $delivery['due_on'] ?? null;
                $qty = $delivery['quantity'] ?? null;
                Value::assert(is_string($date) && is_int($qty), '交期与交付数量无效。');
                $parts[] = [Value::day($date), Value::quantity($qty)];
            }
            Value::assert(array_sum(array_column($parts, 1)) === Value::quantity($quantity), '分批交货数量之和必须等于销售行数量。');
            $normalized[] = [$item, $quantity, Value::unitPrice($price), $parts];
        }
        return $this->mutate(fn(int $org): array => $this->replay('sales.create', $key, [$number, $customer, $normalized],
            function () use ($org, $number, $customer, $normalized): array {
                $this->party($customer, 'customer');
                Value::assert($this->db->row('SELECT id FROM sales_orders WHERE org_id=? AND number=?', [$org, $number]) === null,
                    '销售单号已经存在。', 409);
                $order = $this->db->insert('INSERT INTO sales_orders (org_id,number,customer_id,created_by) VALUES (?,?,?,?)',
                    [$org, $number, $customer, $this->scope->user]);
                $ids = [];
                foreach ($normalized as $lineNo => [$item, $qty, $price, $parts]) {
                    $this->organizationItem($item);
                    $line = $this->db->insert('INSERT INTO sales_lines (org_id,order_id,line_no,item_id,qty_milli,unit_price_minor)
                        VALUES (?,?,?,?,?,?)', [$org, $order, $lineNo + 1, $item, $qty, $price]);
                    foreach ($parts as $no => [$date, $quantity]) {
                        $ids[] = $this->db->insert('INSERT INTO sales_demands (org_id,line_id,delivery_no,due_on,qty_milli)
                            VALUES (?,?,?,?,?)', [$org, $line, $no + 1, $date, $quantity]);
                    }
                }
                $this->audit('sales.created', $order, ['number' => $number, 'demands' => $ids]);
                return ['order_id' => $order, 'demand_ids' => $ids];
            }), 'sales');
    }

    public function freeze(int $order): array
    {
        return $this->mutate(function (int $org) use ($order): array {
            $record = $this->order($order);
            Value::assert(in_array($record['state'], ['draft', 'frozen'], true), '当前销售单无法冻结。', 409);
            $ids = array_map('intval', array_column($record['demands'], 'id'));
            if ($record['state'] === 'draft') {
                $this->db->run("UPDATE sales_orders SET state='frozen', frozen_at=UTC_TIMESTAMP(6) WHERE org_id=? AND id=?", [$org, $order]);
                $this->db->run("UPDATE sales_demands d JOIN sales_lines l ON l.id=d.line_id
                    SET d.state='released' WHERE d.org_id=? AND l.order_id=? AND d.state='draft'", [$org, $order]);
                $this->audit('sales.frozen', $order, ['demands' => $ids]);
            }
            return $ids;
        }, 'sales');
    }

    public function cancelDemand(int $demand, string $reason): array
    {
        $reason = Value::text($reason, 255, '取消原因');
        return $this->mutate(function (int $org) use ($demand, $reason): array {
            $row = $this->db->row('SELECT d.id,d.state,d.qty_milli,d.dispatched_milli,l.order_id
                FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id WHERE d.org_id=? AND d.id=?', [$org, $demand]);
            Value::assert($row !== null, '销售交付需求不存在。', 404);
            Value::assert($row['state'] !== 'fulfilled', '已全部交付的需求不能取消。', 409);
            if ($row['state'] !== 'cancelled') {
                $this->db->run("INSERT INTO demand_dispositions (org_id,demand_id,source_kind,source_id,qty_milli,reason)
                    SELECT org_id,demand_id,source_kind,source_id,qty_milli,? FROM demand_commitments
                    WHERE org_id=? AND demand_id=? AND state IN ('committed','started','completed')", [$reason, $org, $demand]);
                $this->db->run("UPDATE sales_demands SET state='cancelled' WHERE org_id=? AND id=?", [$org, $demand]);
                $other = $this->db->row("SELECT d.id FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id
                    WHERE d.org_id=? AND l.order_id=? AND d.state IN ('draft','released') LIMIT 1", [$org, $row['order_id']]);
                if ($other === null) {
                    $hasDelivery = $this->db->row('SELECT d.id FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id
                        WHERE d.org_id=? AND l.order_id=? AND d.dispatched_milli>0 LIMIT 1', [$org, $row['order_id']]) !== null;
                    $this->db->run('UPDATE sales_orders SET state=? WHERE org_id=? AND id=?',
                        [$hasDelivery ? 'completed' : 'cancelled', $org, $row['order_id']]);
                }
                $this->audit('sales.demand_cancelled', $demand, ['reason' => $reason, 'dispatched_milli' => (int) $row['dispatched_milli']]);
            }
            return $this->db->rows('SELECT id,source_kind,source_id,qty_milli,reason,state FROM demand_dispositions
                WHERE org_id=? AND demand_id=? ORDER BY id', [$org, $demand]);
        }, 'sales');
    }

    public function resolveDisposition(int $id, string $resolution): void
    {
        $resolution = Value::text($resolution, 255, '处置结果');
        $this->mutate(function (int $org) use ($id, $resolution): void {
            $updated = $this->db->run("UPDATE demand_dispositions SET state='resolved',resolved_by=?,resolved_at=UTC_TIMESTAMP(6)
                WHERE org_id=? AND id=? AND state='open'", [$this->scope->user, $org, $id]);
            Value::assert($updated->rowCount() === 1, '处置项不存在或已完成。', 409);
            $this->audit('sales.disposition_resolved', $id, ['resolution' => $resolution]);
        }, 'planner');
    }

    public function order(int $order): array
    {
        $this->requireRole('sales', 'planner', 'warehouse', 'finance');
        $org = $this->scope->org;
        $record = $this->db->row('SELECT s.id,s.number,s.state,s.customer_id,p.name AS customer,s.created_at,s.frozen_at
            FROM sales_orders s JOIN parties p ON p.id=s.customer_id WHERE s.org_id=? AND s.id=?', [$org, $order]);
        Value::assert($record !== null, '销售单不存在。', 404);
        $record['demands'] = $this->db->rows('SELECT d.id,d.line_id,l.line_no,d.delivery_no,d.due_on,d.qty_milli,d.allocated_milli,
            d.dispatched_milli,d.version,d.state,i.code,i.name,i.base_uom,l.unit_price_minor
            FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id JOIN items i ON i.id=l.item_id
            WHERE d.org_id=? AND l.order_id=? ORDER BY l.line_no,d.delivery_no,d.version', [$org, $order]);
        return $record;
    }

    public function list(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('sales', 'planner', 'warehouse', 'finance');
        return $this->db->rows('SELECT s.id,s.number,s.state,p.name AS customer,s.created_at
            FROM sales_orders s JOIN parties p ON p.id=s.customer_id WHERE s.org_id=? AND s.id<? ORDER BY s.id DESC LIMIT 51',
            [$this->scope->org, $before]);
    }
}
