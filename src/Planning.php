<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

final class Planning extends Service
{
    public const POLICY = 'dated-netting-v1';

    public function calculate(string $key, string $asOf, string $horizon, string $policy): array
    {
        Value::day($asOf);
        Value::day($horizon);
        Value::assert($asOf <= $horizon && (new DateTimeImmutable($asOf))->diff(new DateTimeImmutable($horizon))->days <= 366,
            '需求截点不得早于计划日期或超出一年。');
        Value::assert($policy === self::POLICY, '此物料计划策略版本不可用。');
        return $this->mutate(fn(int $org): array => $this->replay('mrp.calculate', $key, [$asOf, $horizon, $policy],
            function () use ($org, $asOf, $horizon, $policy): array {
                $started = hrtime(true);
                $prior = $this->latest($org);
                $roots = $this->roots($org, $horizon);
                $items = array_column($this->db->rows('SELECT item_id,sourcing,lead_days FROM org_items WHERE org_id=?', [$org]),
                    null, 'item_id');
                [$requirements, $pegs, $proposals] = (new PlanningNetter())->calculate($items, $this->boms($org, $asOf),
                    $roots, $this->supply($org, $asOf, $horizon));
                $stats = [
                    'roots' => count($roots),
                    'requirements' => count($requirements),
                    'pegs' => count($pegs),
                    'proposals' => count($proposals),
                    'changes' => 0,
                    'elapsed_ms' => 0,
                ];
                $run = $this->db->insert('INSERT INTO mrp_runs (org_id,prior_id,as_of,horizon_on,policy,stats,created_by)
                    VALUES (?,?,?,?,?,?,?)', [$org, $prior, $asOf, $horizon, $policy, Value::json($stats), $this->scope->user]);
                $this->storeResults($org, $run, $requirements, $pegs, $proposals);
                $stats['changes'] = $this->storeDifferences($org, $run, $prior, $requirements);
                $stats['elapsed_ms'] = (int) ceil((hrtime(true) - $started) / 1_000_000);
                $this->db->run('UPDATE mrp_runs SET stats=? WHERE org_id=? AND id=?', [Value::json($stats), $org, $run]);
                $this->audit('mrp.calculated', $run, ['prior_id' => $prior, 'stats' => $stats, 'policy' => $policy]);
                return ['run_id' => $run, 'state' => 'review', 'stats' => $stats];
            }), 'planner');
    }

    public function approve(int $run): array
    {
        return $this->mutate(function (int $org) use ($run): array {
            $row = $this->runRecord($run);
            Value::assert($this->latest($org) === $run, '请审阅本账套最新的计划运行。', 409);
            if ($row['state'] !== 'approved') {
                $this->db->run("UPDATE mrp_runs SET state='superseded' WHERE org_id=? AND id<>? AND state<>'superseded'", [$org, $run]);
                $this->db->run("UPDATE mrp_runs SET state='approved',approved_by=?,approved_at=UTC_TIMESTAMP(6) WHERE org_id=? AND id=?",
                    [$this->scope->user, $org, $run]);
                $this->audit('mrp.approved', $run, ['accepted_prior_run_id' => $row['prior_id']]);
            }
            return ['run_id' => $run, 'state' => 'approved'];
        }, 'planner');
    }

    public function runs(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('planner', 'buyer', 'production');
        return $this->db->rows('SELECT id,prior_id,as_of,horizon_on,policy,state,stats,created_at
            FROM mrp_runs WHERE org_id=? AND id<? ORDER BY id DESC LIMIT 51', [$this->scope->org, $before]);
    }

    public function run(int $id, string $after = '', string $differencesAfter = ''): array
    {
        $this->requireRole('planner', 'buyer', 'production');
        Value::assert($after === '' || (bool) preg_match('/\A[a-f0-9]{64}\z/', $after), '计划分页游标无效。');
        Value::assert($differencesAfter === '' || (bool) preg_match('/\A[a-f0-9]{64}\z/', $differencesAfter), '差异分页游标无效。');
        $run = $this->runRecord($id);
        $run['stats'] = Value::fromJson($run['stats']);
        $run['requirements'] = $this->db->rows('SELECT r.*,i.code,i.name,p.id AS proposal_id,p.kind,p.starts_on,p.destination_id,p.blocked_reason
            FROM mrp_requirements r JOIN items i ON i.id=r.item_id LEFT JOIN mrp_proposals p ON p.run_id=r.run_id AND p.requirement_ref=r.ref
            WHERE r.org_id=? AND r.run_id=? AND r.ref>? ORDER BY r.ref LIMIT 51', [$this->scope->org, $id, $after]);
        $run['differences'] = $this->db->rows('SELECT d.*,i.code FROM mrp_differences d JOIN items i ON i.id=d.item_id
            WHERE d.org_id=? AND d.run_id=? AND d.requirement_ref>? ORDER BY d.requirement_ref LIMIT 51',
            [$this->scope->org, $id, $differencesAfter]);
        return $run;
    }

    public function pegging(int $run, string $ref): array
    {
        $this->requireRole('planner', 'buyer', 'production');
        $this->runRecord($run);
        Value::assert((bool) preg_match('/\A[a-f0-9]{64}\z/', $ref), '需求引用格式无效。');
        Value::assert($this->db->row('SELECT ref FROM mrp_requirements WHERE org_id=? AND run_id=? AND ref=?',
            [$this->scope->org, $run, $ref]) !== null, '计划明细不存在。', 404);
        return $this->db->rows('SELECT source_kind,source_id,available_on,qty_milli FROM mrp_pegs
            WHERE org_id=? AND run_id=? AND requirement_ref=? ORDER BY source_kind,available_on,source_id', [$this->scope->org, $run, $ref]);
    }

    private function runRecord(int $id): array
    {
        $row = $this->db->row('SELECT * FROM mrp_runs WHERE org_id=? AND id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '物料计划运行不存在。', 404);
        return $row;
    }

    private function latest(int $org): ?int
    {
        $last = $this->db->row('SELECT id FROM mrp_runs WHERE org_id=? ORDER BY id DESC LIMIT 1', [$org]);
        return $last === null ? null : (int) $last['id'];
    }

    private function roots(int $org, string $horizon): array
    {
        return $this->db->rows("SELECT 'sale' AS root_kind,d.id AS root_id,d.id AS sales_demand_id,l.item_id,
            d.due_on AS needed_on,d.qty_milli-d.allocated_milli AS gross_milli
            FROM sales_demands d JOIN sales_lines l ON l.id=d.line_id
            WHERE d.org_id=? AND d.state='released' AND d.due_on<=? AND d.allocated_milli<d.qty_milli
            UNION ALL SELECT 'work',id,sales_demand_id,item_id,needed_on,remaining_milli FROM planning_work_needs
            WHERE org_id=? AND needed_on<=? AND remaining_milli>0", [$org, $horizon, $org, $horizon]);
    }

    private function boms(int $org, string $asOf): array
    {
        $boms = [];
        $components = $this->db->rows('SELECT c.*,b.item_id AS product FROM boms b
            JOIN (SELECT item_id,MAX(effective_on) AS effective_on FROM boms WHERE org_id=? AND effective_on<=? GROUP BY item_id) active
                ON active.item_id=b.item_id AND active.effective_on=b.effective_on
            JOIN bom_components c ON c.bom_id=b.id WHERE b.org_id=? ORDER BY b.item_id,c.item_id', [$org, $asOf, $org]);
        foreach ($components as $line) {
            $boms[$line['product']]['id'] = (int) $line['bom_id'];
            $boms[$line['product']]['lines'][] = $line;
        }
        return $boms;
    }

    private function supply(int $org, string $asOf, string $horizon): array
    {
        $reservations = [];
        foreach ($this->db->rows('SELECT lot_id,SUM(remaining_milli) AS quantity FROM stock_reservations
            WHERE org_id=? AND remaining_milli>0 GROUP BY lot_id', [$org]) as $row) {
            $reservations[$row['lot_id']] = (int) $row['quantity'];
        }
        $supply = [];
        $lots = $this->db->rows("SELECT l.id,l.item_id,l.received_on,SUM(m.delta_milli) AS quantity
            FROM stock_movements m JOIN stock_lots l ON l.id=m.lot_id
            WHERE m.org_id=? AND m.quality='available' AND m.posted_on<=?
            GROUP BY l.id,l.item_id,l.received_on HAVING SUM(m.delta_milli)>0 ORDER BY l.received_on,l.id", [$org, $asOf]);
        foreach ($lots as $lot) {
            $free = (int) $lot['quantity'] - ($reservations[$lot['id']] ?? 0);
            if ($free > 0) {
                $supply[$lot['item_id']]['stock'][] = ['stock', (int) $lot['id'], $lot['received_on'], $free];
            }
        }
        $incoming = $this->db->rows('SELECT source_kind,source_id,item_id,available_on,remaining_milli FROM planning_supplies
            WHERE org_id=? AND remaining_milli>0 AND available_on<=? ORDER BY available_on,source_kind,source_id', [$org, $horizon]);
        foreach ($incoming as $row) {
            $supply[$row['item_id']]['incoming'][] = [
                $row['source_kind'], (int) $row['source_id'], $row['available_on'], (int) $row['remaining_milli'],
            ];
        }
        return $supply;
    }

    private function storeResults(int $org, int $run, array $requirements, array $pegs, array $proposals): void
    {
        $rows = [];
        foreach ($requirements as $r) {
            $rows[] = [$org, $run, $r['ref'], $r['parent_ref'], $r['root_kind'], $r['root_id'], $r['sales_demand_id'], $r['item_id'],
                $r['needed_on'], $r['bom_id'], $r['gross_milli'], $r['stock_milli'], $r['incoming_milli'], $r['shortage_milli'], $r['supply_digest']];
        }
        $this->db->insertRows('INSERT INTO mrp_requirements (org_id,run_id,ref,parent_ref,root_kind,root_id,sales_demand_id,item_id,
            needed_on,bom_id,gross_milli,stock_milli,incoming_milli,shortage_milli,supply_digest)', $rows);
        $this->db->insertRows('INSERT INTO mrp_pegs (org_id,run_id,requirement_ref,source_kind,source_id,available_on,qty_milli)',
            array_map(static fn (array $row): array => [$org, $run, ...$row], $pegs));
        $this->db->insertRows('INSERT INTO mrp_proposals (org_id,run_id,requirement_ref,kind,starts_on,qty_milli,blocked_reason)',
            array_map(static fn (array $row): array => [$org, $run, ...$row], $proposals));
    }

    private function storeDifferences(int $org, int $run, ?int $prior, array $current): int
    {
        if ($prior === null) {
            return 0;
        }
        $previous = array_column($this->db->rows('SELECT ref,root_kind,root_id,item_id,needed_on,bom_id,gross_milli,stock_milli,
            incoming_milli,shortage_milli,supply_digest FROM mrp_requirements WHERE org_id=? AND run_id=?', [$org, $prior]), null, 'ref');
        $rows = [];
        foreach ($previous + $current as $ref => $record) {
            $old = $this->differenceValues($previous[$ref] ?? null);
            $new = $this->differenceValues($current[$ref] ?? null);
            if ($old !== $new) {
                $rows[] = [$org, $run, $ref, $record['root_kind'], $record['root_id'], $record['item_id'],
                    $old === null ? null : Value::json($old), $new === null ? null : Value::json($new)];
            }
        }
        $this->db->insertRows('INSERT INTO mrp_differences
            (org_id,run_id,requirement_ref,root_kind,root_id,item_id,previous_values,current_values)', $rows);
        return count($rows);
    }

    private function differenceValues(?array $record): ?array
    {
        return $record === null ? null : [
            'needed_on' => $record['needed_on'],
            'bom_id' => $record['bom_id'] === null ? null : (int) $record['bom_id'],
            'gross_milli' => (int) $record['gross_milli'],
            'stock_milli' => (int) $record['stock_milli'],
            'incoming_milli' => (int) $record['incoming_milli'],
            'shortage_milli' => (int) $record['shortage_milli'],
            'supply_digest' => $record['supply_digest'],
        ];
    }
}
