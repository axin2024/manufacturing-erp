<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class DeliveryActions extends Service
{
    public function execute(string $action, array $input): array
    {
        return match ($action) {
            'create-sale' => $this->createSale($input),
            'cancel-demand', 'resolve-disposition' => $this->changeDemand($action, $input),
            'open-stock', 'move-stock' => $this->stock($action, $input),
            'reserve-stock', 'release-reservation' => $this->reservation($action, $input),
            'dispatch-delivery' => $this->dispatch($input),
        };
    }

    private function createSale(array $input): array
    {
        $this->requireRole('sales');
        $rows = WebInput::lines($input, 'deliveries', static fn(array $row): array => [
            'line' => WebInput::integer($row['line'] ?? null, '销售行号', 1, 100),
            'item' => WebInput::id($row, 'item'), 'unit_price_minor' => WebInput::price($row, 'unit_price'),
            'delivery' => ['due_on' => WebInput::stringParameter($row, 'due_on'), 'quantity' => WebInput::quantity($row, 'quantity')],
        ], 3000);
        $lines = [];
        foreach ($rows as $row) {
            $index = $row['line'];
            $lines[$index] ??= ['item' => $row['item'], 'unit_price_minor' => $row['unit_price_minor'], 'quantity' => 0, 'deliveries' => []];
            Value::assert($lines[$index]['item'] === $row['item'] && $lines[$index]['unit_price_minor'] === $row['unit_price_minor'],
                '同一销售行号的物料和单价必须一致。');
            $lines[$index]['deliveries'][] = $row['delivery'];
            $lines[$index]['quantity'] += $row['delivery']['quantity'];
        }
        ksort($lines, SORT_NUMERIC);
        $result = (new Sales($this->db, $this->scope))->create(WebInput::stringParameter($input, 'request_key'),
            WebInput::stringParameter($input, 'number'), WebInput::id($input, 'customer'), array_values($lines));
        return ['target' => WebView::url('sales', $result['order_id']), 'message' => '销售订单草稿已创建。', 'result' => $result, 'status' => 201];
    }

    private function changeDemand(string $action, array $input): array
    {
        $id = WebInput::id($input, 'id');
        $sales = new Sales($this->db, $this->scope);
        if ($action === 'cancel-demand') {
            $this->requireRole('sales');
            $order = $this->orderForDemand($id);
            $result = ['demand_id' => $id, 'dispositions' => $sales->cancelDemand($id, WebInput::stringParameter($input, 'reason'))];
            $message = '需求已取消，已承诺供给可在处置清单中处理。';
        } else {
            $this->requireRole('planner');
            $row = $this->db->row('SELECT demand_id FROM demand_dispositions WHERE org_id=? AND id=?', [$this->scope->org, $id]);
            Value::assert($row !== null, '处置项不存在。', 404);
            $order = $this->orderForDemand((int) $row['demand_id']);
            $sales->resolveDisposition($id, WebInput::stringParameter($input, 'reason'));
            $result = ['disposition_id' => $id, 'state' => 'resolved'];
            $message = '处置结果已记录。';
        }
        return ['target' => WebView::url('sales', $order), 'message' => $message, 'result' => $result];
    }

    private function stock(string $action, array $input): array
    {
        $inventory = new Inventory($this->db, $this->scope);
        $key = WebInput::stringParameter($input, 'request_key');
        $date = WebInput::stringParameter($input, 'posted_on');
        $quantity = WebInput::quantity($input, 'quantity');
        if ($action === 'open-stock') {
            $result = $inventory->openingBalance($key, WebInput::id($input, 'item'), WebInput::id($input, 'location'),
                WebInput::stringParameter($input, 'code'), WebInput::stringParameter($input, 'uom'), $quantity,
                WebInput::price($input, 'unit_cost'), $date);
            $message = '期初库存已过账。';
        } else {
            $result = $inventory->move($key, WebInput::id($input, 'id'), WebInput::id($input, 'from'), WebInput::id($input, 'to'),
                WebInput::stringParameter($input, 'quality'), $quantity, $date);
            $message = '库位移动已过账。';
        }
        return ['target' => WebView::url('inventory', $result['lot_id']), 'message' => $message, 'result' => $result, 'status' => 201];
    }

    private function reservation(string $action, array $input): array
    {
        $this->requireRole('sales', 'warehouse');
        $shipping = new Shipping($this->db, $this->scope);
        $key = WebInput::stringParameter($input, 'request_key');
        if ($action === 'reserve-stock') {
            $demand = WebInput::id($input, 'demand');
            $choice = explode(':', WebInput::stringParameter($input, 'stock_bin'));
            Value::assert(count($choice) === 2, '请选择有效的批次库位。');
            $result = $shipping->reserve($key, $demand, WebInput::integer($choice[0], '批次编号'), WebInput::integer($choice[1], '库位编号'),
                WebInput::quantity($input, 'quantity'));
            $message = '已为交付计划保留库存。';
        } else {
            $id = WebInput::id($input, 'id');
            $row = $this->db->row('SELECT demand_id FROM stock_reservations WHERE org_id=? AND id=?', [$this->scope->org, $id]);
            Value::assert($row !== null, '库存分配记录不存在。', 404);
            $demand = (int) $row['demand_id'];
            $result = $shipping->release($key, $id, WebInput::stringParameter($input, 'reason'));
            $message = '剩余分配已解除。';
        }
        return ['target' => WebView::url('sales', $this->orderForDemand($demand), ['demand' => $demand]),
            'message' => $message, 'result' => $result, 'status' => $action === 'reserve-stock' ? 201 : 200];
    }

    private function dispatch(array $input): array
    {
        $rows = WebInput::lines($input, 'lines', static fn(array $row): array => [
            'reservation_id' => WebInput::id($row, 'reservation_id'), 'quantity' => WebInput::quantity($row, 'quantity'),
        ]);
        $result = (new Shipping($this->db, $this->scope))->dispatch(WebInput::stringParameter($input, 'request_key'),
            WebInput::stringParameter($input, 'number'), WebInput::id($input, 'customer'),
            WebInput::stringParameter($input, 'dispatched_on'), $rows);
        return ['target' => WebView::url('shipments', $result['shipment_id']), 'message' => '交货已过账。', 'result' => $result, 'status' => 201];
    }

    private function orderForDemand(int $demand): int
    {
        $this->requireRole('sales', 'warehouse', 'planner');
        $row = $this->db->row('SELECT l.order_id FROM sales_demands d JOIN sales_lines l ON l.org_id=d.org_id AND l.id=d.line_id
            WHERE d.org_id=? AND d.id=?', [$this->scope->org, $demand]);
        Value::assert($row !== null, '交货需求不存在。', 404);
        return (int) $row['order_id'];
    }
}
