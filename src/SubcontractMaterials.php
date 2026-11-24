<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class SubcontractMaterials extends Service
{
    public function send(string $key, int $contract, int $material, int $lot, int $location, int $quantity, string $date,
        ?int $replacement = null, ?int $overage = null): array
    {
        Value::quantity($quantity);
        Value::day($date);
        return $this->mutate(fn(int $org): array => $this->replay('vendor.send', $key,
            [$contract, $material, $lot, $location, $quantity, $date, $replacement, $overage],
            function () use ($org, $contract, $material, $lot, $location, $quantity, $date, $replacement, $overage): array {
                $vendor = (new SubcontractLedger($this->db, $this->scope))->order($contract);
                $stock = new StockLedger($this->db, $this->scope);
                $ledger = new WorkOrderLedger($this->db, $this->scope);
                $work = $ledger->order((int) $vendor['work_id']);
                $line = $ledger->material($material);
                Value::assert((int) $line['work_id'] === (int) $work['id'] && in_array($work['state'], ['released', 'active'], true)
                    && $date >= $work['starts_on'] && $date >= $vendor['contract_on'], '委外工单状态、供料行或日期不符合发料条件。', 409);
                $batch = $stock->lot($lot);
                $net = $ledger->applyIssueApprovals($line, (int) $batch['item_id'], $quantity, $replacement, $overage);
                $id = $this->db->insert('INSERT INTO subcontract_materials (org_id,subcontract_id,material_id,lot_id,location_id,
                    replacement_approval_id,overage_approval_id,sent_milli,unit_cost_minor,sent_on,last_posted_on,actor_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$org, $contract, $material, $lot, $location, $replacement, $overage, $quantity,
                        $batch['unit_cost_minor'], $date, $date, $this->scope->user]);
                $movement = $stock->post($lot, $location, 'available', -$quantity, $date, 'vendor_material_out', $id);
                $this->db->run('UPDATE work_materials SET issued_milli=issued_milli+? WHERE org_id=? AND id=?', [$quantity, $org, $material]);
                $ledger->updateMaterialNeed($work, $line, $net);
                $ledger->markStarted($work);
                $result = ['dispatch_id' => $id, 'subcontract_id' => $contract, 'movement_id' => $movement, 'qty_milli' => $quantity];
                $this->audit('vendor.material_sent', $id, $result);
                return $result;
            }), 'warehouse', 'production');
    }

    public function returnToStock(string $key, int $dispatch, int $location, int $quantity, string $date, string $reason): array
    {
        return $this->recover($key, $dispatch, $quantity, $date, $reason, $location);
    }

    public function writeOff(string $key, int $dispatch, int $quantity, string $date, string $reason): array
    {
        return $this->recover($key, $dispatch, $quantity, $date, $reason, null);
    }

    private function recover(string $key, int $dispatch, int $quantity, string $date, string $reason, ?int $location): array
    {
        Value::quantity($quantity);
        Value::day($date);
        $reason = Value::text($reason, 255, $location === null ? '损耗原因' : '退料说明');
        $kind = $location === null ? 'loss' : 'return';
        $roles = $location === null ? ['finance'] : ['warehouse', 'production'];
        return $this->mutate(fn(int $org): array => $this->replay('vendor.recover', $key,
            [$dispatch, $quantity, $date, $reason, $location],
            function () use ($org, $dispatch, $quantity, $date, $reason, $location, $kind): array {
                $vendor = new SubcontractLedger($this->db, $this->scope);
                $line = $vendor->material($dispatch);
                $contract = $vendor->order((int) $line['subcontract_id']);
                $ledger = new WorkOrderLedger($this->db, $this->scope);
                $work = $ledger->order((int) $contract['work_id']);
                Value::assert(in_array($work['state'], ['active', 'paused', 'waiting_quality'], true)
                    && $date >= $line['last_posted_on'] && $quantity <= (int) $line['outside_milli'],
                    '过账须使用委外留存量，且不能早于该批次最近的委外记录。', 409);
                $this->openPeriod($date);
                $balance = (int) $line['sent_milli'] - (int) $line['loss_milli'] - (int) $line['returned_milli'];
                $u = (int) $line['unit_cost_minor'];
                $amount = Value::extendedPrice($balance, $u) - Value::extendedPrice($balance - $quantity, $u);
                if ($location !== null) {
                    $this->location($location);
                }
                $id = $this->db->insert('INSERT INTO subcontract_recoveries
                    (org_id,dispatch_id,kind,location_id,qty_milli,amount_minor,posted_on,reason,actor_id) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$org, $dispatch, $kind, $location, $quantity, $amount, $date, $reason, $this->scope->user]);
                $movement = null;
                if ($location === null) {
                    $this->db->run('UPDATE subcontract_materials SET loss_milli=loss_milli+?,last_posted_on=? WHERE org_id=? AND id=?',
                        [$quantity, $date, $org, $dispatch]);
                    $costs = new WorkCostLedger($this->db, $this->scope);
                    $costs->record((int) $work['id'], 'adjustment', 'vendor_loss', $id, $amount, 0, $date);
                    $costs->record((int) $work['id'], 'adjustment', 'vendor_loss_expensed', $id, -$amount, 0, $date);
                } else {
                    $movement = (new StockLedger($this->db, $this->scope))->post((int) $line['lot_id'], $location, 'available', $quantity,
                        $date, 'vendor_material_return', $id);
                    $material = $ledger->material((int) $line['material_id']);
                    $this->db->run('UPDATE subcontract_materials SET returned_milli=returned_milli+?,last_posted_on=? WHERE org_id=? AND id=?',
                        [$quantity, $date, $org, $dispatch]);
                    $this->db->run('UPDATE work_materials SET returned_milli=returned_milli+? WHERE org_id=? AND id=?',
                        [$quantity, $org, $material['id']]);
                    if ($work['state'] !== 'waiting_quality') {
                        $ledger->updateMaterialNeed($work, $material, (int) $material['issued_milli'] - (int) $material['returned_milli'] - $quantity);
                    }
                }
                $result = ['recovery_id' => $id, 'dispatch_id' => $dispatch, 'kind' => $kind, 'qty_milli' => $quantity,
                    'amount_minor' => $amount, 'movement_id' => $movement, 'outside_milli' => (int) $line['outside_milli'] - $quantity];
                $this->audit('vendor.material_reconciled', $id, $result + ['reason' => $reason]);
                return $result;
            }), ...$roles);
    }
}
