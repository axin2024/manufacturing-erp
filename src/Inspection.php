<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Inspection extends Service
{
    public function decide(string $key, int $id, int $version, int $accepted, int $rejected, int $rework,
        string $date, string $reason): array
    {
        Value::assert($accepted >= 0 && $accepted <= Value::MAX_QUANTITY && $rejected >= 0 && $rejected <= Value::MAX_QUANTITY
            && $rework >= 0 && $rework <= Value::MAX_QUANTITY, '合格、拒收及返工数量超出范围。');
        $total = Value::quantity($accepted + $rejected + $rework);
        Value::day($date);
        $reason = Value::text($reason, 255, '判定说明');
        return $this->mutate(fn(int $org): array => $this->replay('quality.decide', $key,
            [$id, $version, $accepted, $rejected, $rework, $date, $reason],
            function () use ($org, $id, $version, $accepted, $rejected, $rework, $total, $date, $reason): array {
                $case = $this->caseRecord($id);
                Value::assert((int) $case['version'] === $version, '检验单已经更新，请使用当前版本。', 409);
                Value::assert($rework === 0 || $case['source_kind'] !== 'purchase', '来料检验只支持放行或拒收。', 409);
                $left = (int) $case['pending_milli'] - $total;
                Value::assert($left >= 0 && $case['state'] !== 'completed', '判定数量超过检验单待检数量。', 409);
                $decision = $this->db->insert('INSERT INTO inspection_decisions
                    (org_id,case_id,version,accepted_milli,rejected_milli,rework_milli,reason,measured_on,actor_id) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$org, $id, $version, $accepted, $rejected, $rework, $reason, $date, $this->scope->user]);
                $stock = new StockLedger($this->db, $this->scope);
                $lot = (int) $case['lot_id'];
                $location = (int) $case['location_id'];
                $movements = ['pending' => $stock->post($lot, $location, 'pending', -$total, $date, 'inspection', $decision)];
                foreach (['available' => $accepted, 'rejected' => $rejected, 'rework' => $rework] as $quality => $quantity) {
                    if ($quantity > 0) {
                        $movements[$quality] = $stock->post($lot, $location, $quality, $quantity, $date, 'inspection', $decision);
                    }
                }
                $child = $rework === 0 ? null : (new ReworkLedger($this->db, $this->scope))->open($case, $decision, $rework, $date);
                $state = $left === 0 ? 'completed' : 'partial';
                $this->db->run('UPDATE inspection_cases SET accepted_milli=accepted_milli+?,rejected_milli=rejected_milli+?,
                    rework_milli=rework_milli+?,state=?,version=version+1 WHERE org_id=? AND id=?',
                    [$accepted, $rejected, $rework, $state, $org, $id]);
                $result = ['inspection_id' => $id, 'decision_id' => $decision, 'version' => $version + 1, 'state' => $state,
                    'pending_milli' => $left, 'movement_ids' => $movements, 'rework_order_id' => $child];
                $this->audit('quality.decided', $id, $result);
                return $result;
            }), 'quality');
    }

    public function details(int $id): array
    {
        $this->requireRole('quality', 'warehouse', 'production', 'buyer', 'finance');
        $result = $this->caseRecord($id);
        $result['decisions'] = $this->db->rows('SELECT d.*,u.name AS actor,w.id AS rework_order_id
            FROM inspection_decisions d JOIN users u ON u.id=d.actor_id
            LEFT JOIN work_orders w ON w.rework_decision_id=d.id AND w.org_id=d.org_id
            WHERE d.org_id=? AND d.case_id=? ORDER BY d.version', [$this->scope->org, $id]);
        return $result;
    }

    public function cases(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('quality', 'warehouse', 'production', 'buyer', 'finance');
        return $this->db->rows('SELECT c.id,l.lot_code,c.source_kind,c.source_id,c.qty_milli,c.state,c.version,c.created_at,
            c.qty_milli-c.accepted_milli-c.rejected_milli-c.rework_milli AS pending_milli
            FROM inspection_cases c JOIN stock_lots l ON l.id=c.lot_id WHERE c.org_id=? AND c.id<? ORDER BY c.id DESC LIMIT 51',
            [$this->scope->org, $before]);
    }

    private function caseRecord(int $id): array
    {
        $row = $this->db->row('SELECT c.*,l.lot_code,i.code,i.name AS item_name,w.code AS location_code,
            c.qty_milli-c.accepted_milli-c.rejected_milli-c.rework_milli AS pending_milli FROM inspection_cases c
            JOIN stock_lots l ON l.id=c.lot_id JOIN items i ON i.id=l.item_id JOIN locations w ON w.id=c.location_id
            WHERE c.org_id=? AND c.id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '检验单不存在。', 404);
        return $row;
    }
}
