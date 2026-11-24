<?php

declare(strict_types=1);

namespace ManufacturingErp;

/**
 * Writes inventory within an existing operation that has locked the organization.
 */
final class StockLedger extends Service
{
    public const STATES = ['available', 'pending', 'rejected', 'rework'];

    public function lot(int $id): array
    {
        $row = $this->db->row('SELECT l.*,i.code AS item_code,i.name AS item_name,i.base_uom
            FROM stock_lots l JOIN items i ON i.id=l.item_id WHERE l.org_id=? AND l.id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '该批次不在本账套。', 404);
        return $row;
    }

    public function createLot(int $item, int $location, string $lotCode, string $unit, int $sourceQty, int $unitCost,
        string $postedOn, string $state, string $event, ?int $source, ?array $frozenConversion = null, ?int $exactAmount = null): array
    {
        $org = $this->scope->org;
        $this->location($location);
        $unit = Value::unit($unit);
        $lotCode = Value::code($lotCode, '批号');
        Value::day($postedOn);
        $this->organizationItem($item);
        $conversion = $frozenConversion ?? $this->conversion($item, $unit, $postedOn);
        $factor = (int) $conversion['factor_milli'];
        $quantity = Value::convert($sourceQty, $factor);
        Value::unitPrice($unitCost);
        Value::assert($this->db->row('SELECT id FROM stock_lots WHERE org_id=? AND item_id=? AND lot_code=?',
            [$org, $item, $lotCode]) === null, '该物料的批号已登记。', 409);
        $lot = $this->db->insert('INSERT INTO stock_lots
            (org_id,item_id,lot_code,origin_kind,origin_id,source_uom,source_qty_milli,factor_milli,conversion_id,unit_cost_minor,received_on)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$org, $item, $lotCode, $event, $source, $unit, $sourceQty, $factor,
            $conversion['id'], $unitCost, $postedOn]);
        $movement = $this->post($lot, $location, $state, $quantity, $postedOn, $event, $source);
        (new StockValuation($this->db, $this->scope))->acquire($lot, $quantity, $exactAmount ?? Value::extendedPrice($quantity, $unitCost));
        return ['lot_id' => $lot, 'movement_id' => $movement, 'qty_milli' => $quantity];
    }

    public function post(int $lot, int $location, string $state, int $delta, string $day, string $event, ?int $source): int
    {
        Value::assert(in_array($state, self::STATES, true), '库存状态无效。');
        Value::quantity(abs($delta));
        $org = $this->scope->org;
        $period = $this->openPeriod($day);
        $record = $this->lot($lot);
        Value::assert($day >= $record['received_on'], '过账日期不能早于批次入库日期。', 409);
        $this->location($location);
        $row = $this->db->row('SELECT qty_milli FROM stock_balances
            WHERE org_id=? AND lot_id=? AND location_id=? AND quality=? FOR UPDATE', [$org, $lot, $location, $state]);
        $quantity = (int) ($row['qty_milli'] ?? 0) + $delta;
        Value::assert($quantity >= 0 && $quantity <= Value::MAX_QUANTITY, '此批次、库位与质量状态的库存不足或超出上限。', 409);
        $latest = $this->db->row('SELECT MAX(posted_on) AS latest FROM stock_movements
            WHERE org_id=? AND lot_id=? AND location_id=? AND quality=?', [$org, $lot, $location, $state]);
        Value::assert($latest['latest'] === null || $latest['latest'] <= $day, '过账日期早于此批次在该库位的最后一笔流水。', 409);
        if ($state === 'available' && $delta < 0) {
            $reserved = $this->db->row('SELECT COALESCE(SUM(remaining_milli),0) AS quantity FROM stock_reservations
                WHERE org_id=? AND lot_id=? AND location_id=?', [$org, $lot, $location]);
            Value::assert($quantity >= (int) $reserved['quantity'], '批次库存已经为销售交付预留。', 409);
        }
        if ($row === null) {
            $this->db->run('INSERT INTO stock_balances (org_id,lot_id,location_id,quality,qty_milli) VALUES (?,?,?,?,?)',
                [$org, $lot, $location, $state, $quantity]);
        } else {
            $this->db->run('UPDATE stock_balances SET qty_milli=? WHERE org_id=? AND lot_id=? AND location_id=? AND quality=?',
                [$quantity, $org, $lot, $location, $state]);
        }
        return $this->db->insert('INSERT INTO stock_movements
            (org_id,lot_id,location_id,quality,delta_milli,event_kind,event_id,actor_id,posted_on,period_start)
            VALUES (?,?,?,?,?,?,?,?,?,?)', [$org, $lot, $location, $state, $delta, $event, $source, $this->scope->user, $day, $period]);
    }
}
