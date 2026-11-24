<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class GeneralLedger extends Service
{
    public const CONTROLLED_ACCOUNTS = ['AR','AP','STOCK','VENDOR_STOCK','WIP','GRNI','VENDOR_ACCRUAL','DUE_FROM','DUE_TO'];

    public function adjustment(string $key, string $number, string $date, string $reason, array $rows, ?int $corrects = null): array
    {
        $number = Value::code($number, '调整凭证号');
        Value::day($date);
        $reason = Value::text($reason, 255, '调整说明');
        $lines = $this->normalizeLines($rows);
        return $this->mutate(fn(int $org): array => $this->replay('finance.adjust', $key, [$number, $date, $reason, $lines, $corrects],
            function () use ($org, $number, $date, $reason, $lines, $corrects): array {
                if ($corrects !== null) {
                    $prior = $this->entry($corrects);
                    Value::assert($date >= $prior['posted_on'], '调整日期不能早于原凭证。', 409);
                }
                Value::assert($this->db->row('SELECT id FROM adjustments WHERE org_id=? AND number=?', [$org, $number]) === null,
                    '调整凭证号已经使用。', 409);
                $adjustment = $this->db->insert('INSERT INTO adjustments (org_id,number,corrects_journal_id,reason) VALUES (?,?,?,?)',
                    [$org, $number, $corrects, $reason]);
                $journal = (new FinanceLedger($this->db, $this->scope))->post('adjustment', $adjustment, $date, '期间调整 ' . $number, $lines);
                $result = ['adjustment_id' => $adjustment, 'journal_id' => $journal, 'corrects_journal_id' => $corrects];
                $this->audit('finance.adjusted', $adjustment, $result + ['reason' => $reason]);
                return $result;
            }), 'finance');
    }

    public function journals(int $before = PHP_INT_MAX, ?string $period = null): array
    {
        $this->requireRole('finance');
        if ($period === null) {
            return $this->db->rows('SELECT j.*,u.name AS actor FROM journals j JOIN users u ON u.id=j.actor_id
                WHERE j.org_id=? AND j.id<? ORDER BY j.id DESC LIMIT 51', [$this->scope->org, $before]);
        }
        return $this->db->rows('SELECT j.*,u.name AS actor FROM journals j JOIN users u ON u.id=j.actor_id
            WHERE j.org_id=? AND j.period_start=? AND j.id<? ORDER BY j.id DESC LIMIT 51', [$this->scope->org, Value::day($period), $before]);
    }

    public function journal(int $id): array
    {
        $this->requireRole('finance');
        $entry = $this->entry($id);
        $entry['lines'] = $this->db->rows('SELECT line_no,account_code,currency,debit_minor,credit_minor FROM journal_lines
            WHERE org_id=? AND journal_id=? ORDER BY line_no', [$this->scope->org, $id]);
        return $entry;
    }

    public function trial(string $cutoff): array
    {
        $this->requireRole('finance');
        return $this->db->rows('SELECT l.account_code,l.currency,SUM(l.debit_minor) AS debit_minor,
            SUM(l.credit_minor) AS credit_minor,SUM(l.debit_minor)-SUM(l.credit_minor) AS net_minor
            FROM journal_lines l JOIN journals j ON j.org_id=l.org_id AND j.id=l.journal_id
            WHERE j.org_id=? AND j.posted_on<=? GROUP BY l.account_code,l.currency ORDER BY l.currency,l.account_code',
            [$this->scope->org, Value::day($cutoff)]);
    }

    private function entry(int $id): array
    {
        $record = $this->db->row('SELECT j.*,u.name AS actor,a.corrects_journal_id,a.reason FROM journals j JOIN users u ON u.id=j.actor_id
            LEFT JOIN adjustments a ON a.org_id=j.org_id AND j.source_kind=\'adjustment\' AND a.id=j.source_id
            WHERE j.org_id=? AND j.id=?', [$this->scope->org, $id]);
        Value::assert($record !== null, '会计凭证不存在。', 404);
        return $record;
    }

    private function normalizeLines(array $entries): array
    {
        Value::assert(array_is_list($entries) && count($entries) >= 2 && count($entries) <= 300, '调整凭证须包含 2 至 300 行。');
        $lines = [];
        foreach ($entries as $entry) {
            $account = $entry['account'] ?? null;
            $currency = $entry['currency'] ?? $this->scope->currency;
            $amount = $entry['signed_minor'] ?? null;
            Value::assert(is_string($account) && is_string($currency) && is_int($amount), '调整分录格式无效。');
            $account = Value::code($account, '会计科目');
            Value::assert(isset(FinanceLedger::ACCOUNTS[$account])
                && !in_array($account, self::CONTROLLED_ACCOUNTS, true),
                '受子账控制的科目由对应业务单据调整。', 409);
            $lines[] = [$account, Identity::currency($currency), $amount];
        }
        return $lines;
    }
}
