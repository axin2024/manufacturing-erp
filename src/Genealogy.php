<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Genealogy extends Service
{
    public function trace(int $lot, string $direction): array
    {
        $this->requireRole('quality', 'production', 'warehouse', 'planner', 'finance');
        Value::assert(in_array($direction, ['upstream', 'downstream'], true), '追溯方向无效。');
        $record = (new StockLedger($this->db, $this->scope))->lot($lot);
        $org = $this->scope->org;
        $current = [$lot];
        $seen = [$lot => true];
        $links = $lots = $shipments = [];
        $forward = $direction === 'downstream';
        $from = $forward ? 'input_lot_id' : 'output_lot_id';
        $to = $forward ? 'output_lot_id' : 'input_lot_id';
        while ($current !== []) {
            $next = [];
            foreach (array_chunk($current, 250) as $layer) {
                $list = implode(',', array_fill(0, count($layer), '?'));
                $rows = $this->db->rows("SELECT g.*,a.lot_code AS input_lot,b.lot_code AS output_lot FROM lot_genealogy g
                    JOIN stock_lots a ON a.id=g.input_lot_id JOIN stock_lots b ON b.id=g.output_lot_id
                    WHERE g.org_id=? AND g.$from IN ($list) ORDER BY g.$from,g.$to,g.source_kind,g.source_id", [$org, ...$layer]);
                foreach ($rows as $link) {
                    $id = (int) $link[$to];
                    if (!isset($seen[$id])) {
                        $next[] = $id;
                        $seen[$id] = true;
                    }
                    $links[] = $link;
                }
            }
            Value::assert(count($seen) <= 10_000 && count($links) <= 50_000, '本次追溯范围超出在线查询上限，请按成品批号拆分排查。', 409);
            $current = $next;
        }
        foreach (array_chunk(array_keys($seen), 250) as $chunk) {
            $list = implode(',', array_fill(0, count($chunk), '?'));
            array_push($lots, ...$this->db->rows("SELECT l.id,l.lot_code,l.received_on,l.origin_kind,i.code,i.name
                FROM stock_lots l JOIN items i ON i.id=l.item_id WHERE l.org_id=? AND l.id IN ($list) ORDER BY l.id", [$org, ...$chunk]));
            if ($forward) {
                array_push($shipments, ...$this->db->rows("SELECT s.id,s.lot_id,s.qty_milli,h.id AS shipment_id,h.number,h.dispatched_on,
                    p.code AS customer_code,p.name AS customer FROM shipment_lines s JOIN shipments h ON h.id=s.shipment_id
                    JOIN parties p ON p.id=h.customer_id WHERE s.org_id=? AND s.lot_id IN ($list) ORDER BY s.id", [$org, ...$chunk]));
            }
        }
        return ['lot' => $record, 'direction' => $direction, 'lots' => $lots, 'links' => $links, 'shipments' => $shipments];
    }
}
