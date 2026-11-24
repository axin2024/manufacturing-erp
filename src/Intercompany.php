<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Intercompany extends Service
{
    public function transfer(string $key, string $number, int $destination, int $lot, int $from, int $to, string $destinationCode,
        int $quantity, string $date): array
    {
        $this->requireRole('finance');
        $number = Value::code($number, '集团调拨号');
        $destinationCode = Value::code($destinationCode, '目标批号');
        Value::quantity($quantity);
        Value::day($date);
        $source = $this->scope->org;
        Value::assert($source !== $destination, '集团调拨须选择不同法人。');
        return $this->db->transaction(function () use ($key, $number, $destination, $lot, $from, $to, $destinationCode, $quantity, $date, $source): array {
            $identity = new Identity($this->db);
            $target = $identity->scope($this->scope->user, $destination);
            Value::assert($target->tenant === $this->scope->tenant && $target->can('finance'), '须具有集团内两家法人的财务权限。', 403);
            foreach ([min($source, $destination), max($source, $destination)] as $org) {
                $this->db->row('SELECT id FROM organizations WHERE id=? FOR UPDATE', [$org]);
            }
            $actor = $identity->scope($this->scope->user, $source);
            $target = $identity->scope($this->scope->user, $destination);
            Value::assert($actor->can('finance') && $target->can('finance'), '组织权限已更新。', 403);
            Value::assert($target->currency === $actor->currency, '集团实物调拨须使用相同本位币。', 409);
            return $this->replay('intercompany.transfer', $key, [$number, $destination, $lot, $from, $to, $destinationCode, $quantity, $date],
                fn(): array => $this->postPair($number, $target, $lot, $from, $to, $destinationCode, $quantity, $date));
        });
    }

    public function transfers(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('finance');
        $org = $this->scope->org;
        return $this->db->rows('SELECT t.*,a.code AS source_org,b.code AS destination_org FROM intercompany_transfers t
            JOIN organizations a ON a.id=t.source_org_id JOIN organizations b ON b.id=t.destination_org_id
            WHERE t.tenant_id=? AND (t.source_org_id=? OR t.destination_org_id=?) AND t.id<? ORDER BY t.id DESC LIMIT 51',
            [$this->scope->tenant, $org, $org, $before]);
    }

    private function postPair(string $number, Scope $target, int $lot, int $from, int $to, string $code, int $quantity, string $date): array
    {
        $source = $this->scope->org;
        $tenant = $this->scope->tenant;
        Value::assert($this->db->row('SELECT id FROM intercompany_transfers WHERE tenant_id=? AND number=?',
            [$tenant, $number]) === null, '集团调拨号已登记。', 409);
        $ownStock = new StockLedger($this->db, $this->scope);
        $sourceLot = $ownStock->lot($lot);
        $amount = (new StockValuation($this->db, $this->scope))->take($lot, $quantity);
        $id = $this->db->insert('INSERT INTO intercompany_transfers
            (tenant_id,number,source_org_id,destination_org_id,source_lot_id,qty_milli,amount_minor,currency,posted_on,actor_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)', [$tenant, $number, $source, $target->org, $lot, $quantity, $amount, $target->currency, $date, $target->user]);
        $out = $ownStock->post($lot, $from, 'available', -$quantity, $date, 'intercompany_out', $id);
        $in = (new StockLedger($this->db, $target))->createLot((int) $sourceLot['item_id'], $to, $code, $sourceLot['base_uom'],
            $quantity, (int) $sourceLot['unit_cost_minor'], $date, 'available', 'intercompany_in', $id, null, $amount);
        $this->db->run('UPDATE intercompany_transfers SET destination_lot_id=? WHERE tenant_id=? AND id=?', [$in['lot_id'], $tenant, $id]);
        $outEntry = (new FinanceLedger($this->db, $this->scope))->move('intercompany_out', $id, $date, '集团调拨发出 ' . $number,
            'DUE_FROM', 'STOCK', $amount);
        $inEntry = (new FinanceLedger($this->db, $target))->move('intercompany_in', $id, $date, '集团调拨接收 ' . $number,
            'STOCK', 'DUE_TO', $amount);
        $result = ['transfer_id' => $id, 'qty_milli' => $quantity, 'amount_minor' => $amount, 'source_movement_id' => $out,
            'source_journal_id' => $outEntry, 'destination_journal_id' => $inEntry, 'destination_movement_id' => $in['movement_id'],
            'destination_lot_id' => $in['lot_id']];
        $this->audit('intercompany.sent', $id, $result + ['destination_org_id' => $target->org, 'source_lot_id' => $lot]);
        $this->db->run('INSERT INTO audit_events (org_id,actor_id,event_type,entity_id,details) VALUES (?,?,?,?,?)',
            [$target->org, $target->user, 'intercompany.received', $id, Value::json(['source_org_id' => $source, ...$result])]);
        return $result;
    }
}
