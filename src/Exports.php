<?php

declare(strict_types=1);

namespace ManufacturingErp;

use Generator;
use RuntimeException;
use Throwable;

final class Exports extends Service
{
    /** @return array{filename: string, stream: resource} */
    public function open(string $kind, ?int $id = null, ?string $filter = null): array
    {
        [$columns, $rows] = match ($kind) {
            'work-issues' => $this->issues($id ?? 0),
            'lot-trace' => $this->trace($id ?? 0, $filter ?? 'downstream'),
            'work-variance' => $this->variance($id ?? 0),
            'receivables' => $this->balances('ar'),
            'payables' => $this->balances('ap'),
            'journals' => $this->journals($filter),
            default => throw new BusinessError('此导出类型不存在。', 404),
        };
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('CSV buffer could not be created.');
        }
        try {
            if (fwrite($stream, "\xEF\xBB\xBF") !== 3) {
                throw new RuntimeException('CSV buffer could not be written.');
            }
            $this->write($stream, $columns);
            foreach ($rows as $row) {
                $cells = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    $numeric = preg_match('/(?:^id$|^line_no$|_(?:id|minor|milli)$)/', $column)
                        && preg_match('/\A-?[0-9]+\z/', (string) $value);
                    $cells[] = !$numeric && is_string($value) && preg_match('/\A(?:[\x09-\x0D]|\s*[=+\-@])/u', $value)
                        ? "'" . $value : $value;
                }
                $this->write($stream, $cells);
            }
            rewind($stream);
        } catch (Throwable $error) {
            fclose($stream);
            throw $error;
        }
        return ['filename' => $kind . ($id === null ? '' : '-' . $id) . '.csv', 'stream' => $stream];
    }

    private function write($stream, array $cells): void
    {
        if (fputcsv($stream, $cells, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('CSV record could not be written.');
        }
    }

    private function issues(int $work): array
    {
        $this->requireRole('production', 'warehouse', 'planner', 'finance');
        (new WorkOrderLedger($this->db, $this->scope))->order($work);
        return [
            ['id','work_number','item_code','lot_code','location','issued_on','qty_milli','returned_milli','consumed_milli',
                'unit_cost_minor','actual_minor','standard_minor','replacement_approval_id','overage_approval_id','actor'],
            $this->db->stream("SELECT a.*,w.number AS work_number,i.code AS item_code,l.lot_code,s.code AS location,
                c.actual_minor,c.standard_minor,u.name AS actor
                FROM work_issues a JOIN work_orders w ON w.org_id=a.org_id AND w.id=a.work_id
                JOIN stock_lots l ON l.org_id=a.org_id AND l.id=a.lot_id JOIN items i ON i.id=l.item_id
                JOIN locations s ON s.org_id=a.org_id AND s.id=a.location_id JOIN users u ON u.id=a.actor_id
                JOIN work_cost_entries c ON c.org_id=a.org_id AND c.source_kind='issue' AND c.source_id=a.id
                AND c.element='material' WHERE a.org_id=? AND a.work_id=? ORDER BY a.id",
                [$this->scope->org, $work]),
        ];
    }

    private function variance(int $work): array
    {
        $this->requireRole('finance', 'production', 'planner');
        (new WorkOrderLedger($this->db, $this->scope))->order($work);
        return [
            ['id','work_number','element','source_kind','source_id','posted_on','currency',
                'actual_minor','standard_minor','variance_minor','actor'],
            $this->db->stream('SELECT c.*,w.number AS work_number,o.currency,c.actual_minor-c.standard_minor AS variance_minor,u.name AS actor
                FROM work_cost_entries c JOIN work_orders w ON w.org_id=c.org_id AND w.id=c.work_id
                JOIN organizations o ON o.id=c.org_id JOIN users u ON u.id=c.actor_id
                WHERE c.org_id=? AND c.work_id=? ORDER BY c.id', [$this->scope->org, $work]),
        ];
    }

    private function trace(int $lot, string $direction): array
    {
        $trace = (new Genealogy($this->db, $this->scope))->trace($lot, $direction);
        return [
            ['type','direction','lot_id','lot_code','item_code','input_lot_id','input_lot','output_lot_id','output_lot',
                'source_kind','source_id','qty_milli','posted_on','shipment_number','customer_code','customer'],
            $this->traceRows($trace),
        ];
    }

    private function traceRows(array $trace): Generator
    {
        $direction = ['direction' => $trace['direction']];
        foreach ($trace['lots'] as $lot) {
            yield $direction + ['type' => 'lot', 'lot_id' => $lot['id'], 'lot_code' => $lot['lot_code'],
                'item_code' => $lot['code'], 'source_kind' => $lot['origin_kind'], 'posted_on' => $lot['received_on']];
        }
        foreach ($trace['links'] as $link) {
            yield $direction + ['type' => 'link'] + $link;
        }
        foreach ($trace['shipments'] as $shipment) {
            yield $direction + ['type' => 'shipment', 'posted_on' => $shipment['dispatched_on'],
                'source_kind' => 'shipment', 'source_id' => $shipment['shipment_id'], 'shipment_number' => $shipment['number']] + $shipment;
        }
    }

    private function balances(string $direction): array
    {
        $this->requireRole('finance');
        return [
            ['id','direction','document_ref','party_code','party','posted_on','due_on','currency','amount_minor','open_minor','source_kind','source_id'],
            $this->db->stream('SELECT i.*,p.code AS party_code,p.name AS party,o.currency FROM open_items i
                JOIN parties p ON p.org_id=i.org_id AND p.id=i.party_id JOIN organizations o ON o.id=i.org_id
                WHERE i.org_id=? AND i.direction=? ORDER BY i.id', [$this->scope->org, $direction]),
        ];
    }

    private function journals(?string $period): array
    {
        $this->requireRole('finance');
        if ($period !== null) {
            Value::assert(str_ends_with(Value::day($period), '-01'), '期间日期须为当月第一天。');
        }
        return [
            ['journal_id','posted_on','period_start','source_kind','source_id','memo','actor','line_no','account_code','currency','debit_minor','credit_minor'],
            $this->db->stream('SELECT j.id AS journal_id,j.posted_on,j.period_start,j.source_kind,j.source_id,j.memo,u.name AS actor,l.*
                FROM journals j JOIN journal_lines l ON l.org_id=j.org_id AND l.journal_id=j.id JOIN users u ON u.id=j.actor_id
                WHERE j.org_id=? AND (? IS NULL OR j.period_start=?) ORDER BY j.id,l.line_no', [$this->scope->org, $period, $period]),
        ];
    }
}
