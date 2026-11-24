<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

final class FiscalClose extends Service
{
    public function periods(): array
    {
        $this->requireRole('finance');
        return $this->db->rows('SELECT p.*,c.digest FROM fiscal_periods p LEFT JOIN financial_closures c
            ON c.org_id=p.org_id AND c.period_start=p.period_start WHERE p.org_id=? ORDER BY p.period_start DESC', [$this->scope->org]);
    }

    public function review(string $period): array
    {
        $this->requireRole('finance');
        $this->checkMonth($period);
        return $this->closed($period) ?? $this->prepare($period);
    }

    public function close(string $period): array
    {
        $this->checkMonth($period);
        return $this->mutate(function (int $org) use ($period): array {
            $closed = $this->closed($period);
            if ($closed !== null) {
                return $closed;
            }
            $this->openPeriod($period);
            $result = $this->prepare($period);
            if ($result['blockers'] !== []) {
                return $result + ['state' => 'open'];
            }
            $snapshot = $this->canonical($result);
            $digest = hash('sha256', Value::json($snapshot));
            $this->db->run('INSERT INTO financial_closures (org_id,period_start,snapshot,digest) VALUES (?,?,?,?)',
                [$org, $period, Value::json($snapshot), $digest]);
            $this->db->run("UPDATE fiscal_periods SET state='closed',closed_by=?,closed_at=UTC_TIMESTAMP(6) WHERE org_id=? AND period_start=?",
                [$this->scope->user, $org, $period]);
            $this->audit('finance.period_closed', $this->scope->org, ['period_start' => $period, 'digest' => $digest]);
            return ['state' => 'closed', 'period_start' => $period, 'snapshot' => $snapshot, 'digest' => $digest];
        }, 'finance');
    }

    private function prepare(string $period): array
    {
        $org = $this->scope->org;
        $cutoff = (new DateTimeImmutable($period))->format('Y-m-t');
        $trial = (new GeneralLedger($this->db, $this->scope))->trial($cutoff);
        $books = array_column(array_filter($trial, fn (array $line): bool => $line['currency'] === $this->scope->currency),
            'net_minor', 'account_code');
        $items = $this->db->rows('SELECT i.direction,
            SUM(i.amount_minor-COALESCE((SELECT SUM(s.amount_minor) FROM settlements s WHERE s.org_id=i.org_id AND s.open_item_id=i.id AND s.posted_on<=?),0)) AS balance_minor
            FROM open_items i WHERE i.org_id=? AND i.posted_on<=? GROUP BY i.direction ORDER BY i.direction', [$cutoff, $org, $cutoff]);
        $balances = array_column($items, 'balance_minor', 'direction');
        $workCost = $this->db->rows('SELECT element,SUM(actual_minor) AS actual_minor,SUM(standard_minor) AS standard_minor
            FROM work_cost_entries WHERE org_id=? AND posted_on<=? GROUP BY element ORDER BY element', [$org, $cutoff]);
        $blockers = $this->inspectPairedMovements($cutoff);
        foreach ($this->db->rows("SELECT j.id,l.currency FROM journals j LEFT JOIN journal_lines l ON l.org_id=j.org_id AND l.journal_id=j.id
            WHERE j.org_id=? AND j.posted_on<=? GROUP BY j.id,l.currency
            HAVING COUNT(l.line_no)=0 OR SUM(l.debit_minor)<>SUM(l.credit_minor) ORDER BY j.id,l.currency", [$org, $cutoff]) as $row) {
            $blockers[] = ['kind' => 'journal_unbalanced', 'journal_id' => (int) $row['id'], 'currency' => $row['currency']];
        }
        foreach ($this->db->rows("SELECT period_start FROM fiscal_periods WHERE org_id=? AND period_start<? AND state='open' ORDER BY period_start",
            [$org, $period]) as $row) {
            $blockers[] = ['kind' => 'earlier_period_open', 'period_start' => $row['period_start']];
        }
        foreach (['ar' => (int) ($books['AR'] ?? 0), 'ap' => -(int) ($books['AP'] ?? 0)] as $direction => $balance) {
            if ($balance !== (int) ($balances[$direction] ?? 0)) {
                $blockers[] = ['kind' => 'open_item_difference', 'direction' => $direction,
                    'book_minor' => $balance, 'subledger_minor' => (int) ($balances[$direction] ?? 0)];
            }
        }
        $wip = array_sum(array_column($workCost, 'actual_minor'));
        if ($wip !== (int) ($books['WIP'] ?? 0)) {
            $blockers[] = ['kind' => 'wip_difference', 'book_minor' => (int) ($books['WIP'] ?? 0), 'subledger_minor' => $wip];
        }
        return ['period_start' => $period, 'cutoff_on' => $cutoff, 'trial' => $trial, 'open_items' => $items, 'costs' => $workCost,
            'blockers' => $blockers];
    }

    private function inspectPairedMovements(string $cutoff): array
    {
        $org = $this->scope->org;
        $transfers = $this->db->rows("SELECT t.*,
            (SELECT m.delta_milli FROM stock_movements m WHERE m.org_id=t.source_org_id AND m.lot_id=t.source_lot_id
                AND m.event_kind='intercompany_out' AND m.event_id=t.id AND m.posted_on=t.posted_on LIMIT 1) AS out_qty,
            (SELECT m.delta_milli FROM stock_movements m WHERE m.org_id=t.destination_org_id AND m.lot_id=t.destination_lot_id
                AND m.event_kind='intercompany_in' AND m.event_id=t.id AND m.posted_on=t.posted_on LIMIT 1) AS in_qty
            FROM intercompany_transfers t WHERE t.tenant_id=? AND t.posted_on<=? AND (t.source_org_id=? OR t.destination_org_id=?) ORDER BY t.id",
            [$this->scope->tenant, $cutoff, $org, $org]);
        $blockers = [];
        foreach ($transfers as $row) {
            $id = (int) $row['id'];
            $quantity = (int) $row['qty_milli'];
            $amount = (int) $row['amount_minor'];
            $out = $row['out_qty'] !== null && (int) $row['out_qty'] === -$quantity
                && $this->pairedEntry($row, (int) $row['source_org_id'], 'intercompany_out', 'DUE_FROM', 'STOCK', $amount);
            $in = $row['in_qty'] !== null && (int) $row['in_qty'] === $quantity
                && $this->pairedEntry($row, (int) $row['destination_org_id'], 'intercompany_in', 'STOCK', 'DUE_TO', $amount);
            if (!$out || !$in) {
                $blockers[] = ['kind' => 'intercompany_unmatched', 'transfer_id' => $id, 'number' => $row['number'],
                    'source_matched' => $out, 'destination_matched' => $in];
            }
        }
        return $blockers;
    }

    private function pairedEntry(array $pair, int $org, string $kind, string $debit, string $credit, int $amount): bool
    {
        if ($amount === 0) {
            return true;
        }
        $lines = $this->db->rows('SELECT l.account_code,l.currency,l.debit_minor,l.credit_minor FROM journals j
            JOIN journal_lines l ON l.org_id=j.org_id AND l.journal_id=j.id
            WHERE j.org_id=? AND j.source_kind=? AND j.source_id=? AND j.posted_on=? ORDER BY l.line_no',
            [$org, $kind, $pair['id'], $pair['posted_on']]);
        return $lines === [
            ['account_code' => $debit, 'currency' => $pair['currency'], 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_code' => $credit, 'currency' => $pair['currency'], 'debit_minor' => 0, 'credit_minor' => $amount],
        ];
    }

    private function closed(string $period): ?array
    {
        $row = $this->db->row("SELECT c.snapshot,c.digest FROM financial_closures c JOIN fiscal_periods p
            ON p.org_id=c.org_id AND p.period_start=c.period_start WHERE c.org_id=? AND c.period_start=? AND p.state='closed'",
            [$this->scope->org, $period]);
        if ($row === null) {
            return null;
        }
        $snapshot = $this->canonical(Value::fromJson($row['snapshot']));
        Value::assert(hash_equals($row['digest'], hash('sha256', Value::json($snapshot))), '关期快照与存档摘要不一致。', 409);
        return ['state' => 'closed', 'period_start' => $period, 'snapshot' => $snapshot, 'digest' => $row['digest']];
    }

    private function canonical(array $record): array
    {
        if (!array_is_list($record)) {
            ksort($record, SORT_STRING);
        }
        return array_map(fn (mixed $value): mixed => is_array($value) ? $this->canonical($value) : $value, $record);
    }

    private function checkMonth(string $period): void
    {
        Value::assert(substr(Value::day($period), -2) === '01', '会计期间请填写所在月份的第一天。');
    }
}
