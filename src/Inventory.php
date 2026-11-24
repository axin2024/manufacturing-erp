<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Inventory extends Service
{
    public function openingBalance(string $key, int $item, int $location, string $lotCode, string $unit,
        int $sourceQty, int $unitCost, string $postedOn): array
    {
        $lotCode = Value::code($lotCode, '批号');
        $unit = Value::unit($unit);
        Value::day($postedOn);
        return $this->mutate(fn(): array => $this->replay('stock.opening', $key,
            [$item, $location, $lotCode, $unit, $sourceQty, $unitCost, $postedOn],
            function () use ($item, $location, $lotCode, $unit, $sourceQty, $unitCost, $postedOn): array {
                $result = (new StockLedger($this->db, $this->scope))->createLot($item, $location, $lotCode, $unit,
                    $sourceQty, $unitCost, $postedOn, 'available', 'opening', null);
                $this->audit('stock.opening', $result['lot_id'], $result);
                return $result;
            }), 'warehouse');
    }

    public function move(string $key, int $lot, int $from, int $to, string $quality, int $quantity, string $date): array
    {
        Value::assert($from !== $to, '请指定不同的调出和调入库位。');
        Value::assert($quality !== 'pending', '待检批次须留在报检库位，判定后可调拨。', 409);
        Value::quantity($quantity);
        Value::day($date);
        return $this->mutate(fn(): array => $this->replay('stock.transfer', $key, [$lot, $from, $to, $quality, $quantity, $date],
            function () use ($lot, $from, $to, $quality, $quantity, $date): array {
                $stock = new StockLedger($this->db, $this->scope);
                $out = $stock->post($lot, $from, $quality, -$quantity, $date, 'transfer_out', null);
                $in = $stock->post($lot, $to, $quality, $quantity, $date, 'transfer_in', $out);
                $this->audit('stock.transferred', $lot, ['out' => $out, 'in' => $in, 'qty_milli' => $quantity]);
                return ['lot_id' => $lot, 'out_movement_id' => $out, 'in_movement_id' => $in];
            }), 'warehouse');
    }

    public function lots(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('warehouse', 'planner', 'buyer', 'production', 'quality', 'finance');
        return $this->db->rows("SELECT l.id,l.lot_code,l.received_on,i.code,i.name,i.base_uom,
            COALESCE(SUM(IF(b.quality='available',b.qty_milli,0)),0) AS available_milli,
            COALESCE(SUM(IF(b.quality='pending',b.qty_milli,0)),0) AS pending_milli,
            COALESCE(SUM(IF(b.quality='rejected',b.qty_milli,0)),0) AS rejected_milli,
            COALESCE(SUM(IF(b.quality='rework',b.qty_milli,0)),0) AS rework_milli
            FROM stock_lots l JOIN items i ON i.id=l.item_id LEFT JOIN stock_balances b ON b.lot_id=l.id
            WHERE l.org_id=? AND l.id<? GROUP BY l.id,i.code,i.name,i.base_uom ORDER BY l.id DESC LIMIT 51",
            [$this->scope->org, $before]);
    }

    public function lot(int $lot, int $before = PHP_INT_MAX): array
    {
        $this->requireRole('warehouse', 'planner', 'buyer', 'production', 'quality', 'finance');
        $record = (new StockLedger($this->db, $this->scope))->lot($lot);
        $org = $this->scope->org;
        $record['balances'] = $this->db->rows('SELECT b.location_id,l.code,l.name,b.quality,b.qty_milli
            FROM stock_balances b JOIN locations l ON l.id=b.location_id
            WHERE b.org_id=? AND b.lot_id=? AND b.qty_milli>0 ORDER BY l.code,b.quality', [$org, $lot]);
        $record['movements'] = $this->db->rows('SELECT m.id,l.code AS location,m.quality,m.delta_milli,m.event_kind,m.event_id,
            m.posted_on,u.name AS actor FROM stock_movements m JOIN locations l ON l.id=m.location_id JOIN users u ON u.id=m.actor_id
            WHERE m.org_id=? AND m.lot_id=? AND m.id<? ORDER BY m.id DESC LIMIT 51', [$org, $lot, $before]);
        return $record;
    }
}
