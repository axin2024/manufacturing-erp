<?php

declare(strict_types=1);

namespace ManufacturingErp;

/**
 * Conserves whole minor units when a lot is partly issued, shipped or transferred.
 * Call within the same organization transaction as the physical stock movement.
 */
final class StockValuation extends Service
{
    public function acquire(int $lot, int $quantity, int $amount): void
    {
        Value::assert($amount >= 0 && $amount <= FinanceLedger::MAX_AMOUNT, '库存金额超出范围。');
        $this->db->run('INSERT INTO stock_lot_values (org_id,lot_id,qty_milli,carrying_minor) VALUES (?,?,?,?)',
            [$this->scope->org, $lot, $quantity, $amount]);
    }

    public function take(int $lot, int $quantity): int
    {
        $prior = $this->onHand($lot);
        Value::assert($quantity <= (int) $prior['qty_milli'], '批次估价没有足够的可转出数量。', 409);
        $amount = Value::allocation((int) $prior['carrying_minor'], $quantity, (int) $prior['qty_milli']);
        $this->modify($lot, (int) $prior['qty_milli'] - $quantity, (int) $prior['carrying_minor'] - $amount);
        return $amount;
    }

    public function restore(int $lot, int $quantity, int $amount): void
    {
        $prior = $this->onHand($lot);
        $this->modify($lot, (int) $prior['qty_milli'] + $quantity, (int) $prior['carrying_minor'] + $amount);
    }

    private function onHand(int $lot): array
    {
        $org = $this->scope->org;
        $row = $this->db->row('SELECT qty_milli,carrying_minor FROM stock_lot_values WHERE org_id=? AND lot_id=? FOR UPDATE', [$org, $lot]);
        if ($row !== null) {
            return $row;
        }
        $position = $this->db->row('SELECT l.unit_cost_minor,COALESCE(SUM(b.qty_milli),0) AS qty_milli
            FROM stock_lots l LEFT JOIN stock_balances b ON b.org_id=l.org_id AND b.lot_id=l.id
            WHERE l.org_id=? AND l.id=? GROUP BY l.id,l.unit_cost_minor', [$org, $lot]);
        Value::assert($position !== null, '该批次不在本账套。', 404);
        $quantity = (int) $position['qty_milli'];
        $value = Value::extendedPrice($quantity, (int) $position['unit_cost_minor']);
        $this->acquire($lot, $quantity, $value);
        return ['qty_milli' => $quantity, 'carrying_minor' => $value];
    }

    private function modify(int $lot, int $quantity, int $amount): void
    {
        Value::assert($quantity >= 0 && $quantity <= Value::MAX_QUANTITY && $amount >= 0 && $amount <= FinanceLedger::MAX_AMOUNT,
            '库存估价变动超出范围。');
        $this->db->run('UPDATE stock_lot_values SET qty_milli=?,carrying_minor=? WHERE org_id=? AND lot_id=?',
            [$quantity, $amount, $this->scope->org, $lot]);
    }
}
