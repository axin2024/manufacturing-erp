<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class FinanceLedger extends Service
{
    public const MAX_AMOUNT = 1_000_000_000_000_000;
    public const ACCOUNTS = [
        'BANK' => '银行存款', 'AR' => '应收账款', 'STOCK' => '存货', 'VENDOR_STOCK' => '委外在途物料',
        'WIP' => '在制品', 'DUE_FROM' => '应收集团往来', 'AP' => '应付账款', 'GRNI' => '收货暂估应付',
        'VENDOR_ACCRUAL' => '委外暂估应付', 'DUE_TO' => '应付集团往来',
        'LABOR_CLEAR' => '人工待结转', 'OVERHEAD_CLEAR' => '制造费用待结转', 'OPENING_EQUITY' => '期初余额',
        'REVENUE' => '销售收入', 'COGS' => '销售成本', 'MATERIAL_LOSS' => '物料损耗',
        'PURCHASE_VARIANCE' => '采购价差', 'VENDOR_VARIANCE' => '委外费差异', 'GENERAL_EXPENSE' => '期间费用',
    ];

    public function move(string $source, int $id, string $date, string $memo, string $debit, string $credit, int $amount): ?int
    {
        if ($amount === 0) {
            return null;
        }
        return $this->post($source, $id, $date, $memo, [
            $this->line($debit, $amount), $this->line($credit, -$amount),
        ]);
    }

    public function line(string $account, int $signed, ?string $currency = null): array
    {
        return [$account, $currency ?? $this->scope->currency, $signed];
    }

    public function post(string $source, int $id, string $date, string $memo, array $lines): int
    {
        Value::assert(count($lines) >= 2 && count($lines) <= 300, '凭证须包含 2 至 300 行分录。');
        $totals = $seenDebit = $seenCredit = [];
        foreach ($lines as [$account, $currency, $signed]) {
            Value::assert(isset(self::ACCOUNTS[$account]), '科目不存在。');
            Identity::currency($currency);
            Value::assert($signed !== 0 && $signed >= -self::MAX_AMOUNT && $signed <= self::MAX_AMOUNT, '分录金额超出范围。');
            $totals[$currency] = ($totals[$currency] ?? 0) + $signed;
            if ($signed > 0) {
                $seenDebit[$currency] = true;
            } else {
                $seenCredit[$currency] = true;
            }
        }
        foreach ($totals as $currency => $balance) {
            Value::assert($balance === 0 && isset($seenDebit[$currency], $seenCredit[$currency]), '凭证必须按每个币种分别平衡。', 409);
        }
        $org = $this->scope->org;
        $period = $this->openPeriod($date);
        $entry = $this->db->insert('INSERT INTO journals (org_id,source_kind,source_id,memo,posted_on,period_start,actor_id)
            VALUES (?,?,?,?,?,?,?)', [$org, $source, $id, $memo, $date, $period, $this->scope->user]);
        $insert = [];
        foreach ($lines as $index => [$account, $currency, $signed]) {
            $insert[] = [$org, $entry, $index + 1, $account, $currency, max(0, $signed), max(0, -$signed)];
        }
        $this->db->insertRows('INSERT INTO journal_lines (org_id,journal_id,line_no,account_code,currency,debit_minor,credit_minor)', $insert);
        return $entry;
    }

    public function openItem(string $direction, int $party, string $ref, string $source, int $id, int $amount, string $date, string $due): ?int
    {
        Value::assert(in_array($direction, ['ar', 'ap'], true) && $amount >= 0 && $amount <= self::MAX_AMOUNT
            && Value::day($due) >= $date, '应收应付单据金额或到期日期无效。');
        if ($amount === 0) {
            return null;
        }
        return $this->db->insert('INSERT INTO open_items
            (org_id,direction,party_id,document_ref,source_kind,source_id,posted_on,due_on,amount_minor,open_minor) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$this->scope->org, $direction, $party, $ref, $source, $id, $date, $due, $amount, $amount]);
    }
}
