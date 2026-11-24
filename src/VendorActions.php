<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class VendorActions extends Service
{
    public function execute(string $action, array $input): array
    {
        $key = WebInput::stringParameter($input, 'request_key');
        $date = WebInput::stringParameter($input, 'posted_on');
        return match ($action) {
            'create-vendor-contract' => $this->create($key, $date, $input),
            'send-vendor-material', 'return-vendor-material', 'write-off-vendor-material' => $this->materials($action, $key, $date, $input),
            'receive-vendor-output' => $this->receive($key, $date, $input),
            'match-vendor-fee' => $this->matchFee($key, $date, $input),
        };
    }

    private function create(string $key, string $date, array $input): array
    {
        $result = (new Subcontracting($this->db, $this->scope))->create($key, WebInput::stringParameter($input, 'number'),
            WebInput::id($input, 'work'), WebInput::id($input, 'supplier'), $date, WebInput::price($input, 'unit_fee'));
        return $this->outcome($result['subcontract_id'], '加工方和合同单价已登记。', $result);
    }

    private function materials(string $action, string $key, string $date, array $input): array
    {
        $service = new SubcontractMaterials($this->db, $this->scope);
        $quantity = WebInput::quantity($input, 'quantity');
        if ($action === 'send-vendor-material') {
            $contract = WebInput::id($input, 'id');
            $result = $service->send($key, $contract, WebInput::id($input, 'material'), WebInput::id($input, 'lot'),
                WebInput::id($input, 'location'), $quantity, $date, WebInput::optionalId($input, 'replacement'), WebInput::optionalId($input, 'overage'));
            return $this->outcome($contract, '投入批次已移入加工方留存。', $result);
        }
        $dispatch = WebInput::id($input, 'dispatch');
        $reason = WebInput::stringParameter($input, 'reason');
        if ($action === 'return-vendor-material') {
            $this->requireRole('warehouse', 'production');
            $result = $service->returnToStock($key, $dispatch, WebInput::id($input, 'location'), $quantity, $date, $reason);
            $message = '余料已回到本法人库存。';
        } else {
            $this->requireRole('finance');
            $result = $service->writeOff($key, $dispatch, $quantity, $date, $reason);
            $message = '确认损耗已过账。';
        }
        return $this->outcome($this->contract('subcontract_materials', $dispatch), $message, $result);
    }

    private function receive(string $key, string $date, array $input): array
    {
        $contract = WebInput::id($input, 'id');
        $usage = ($input['uses'] ?? []) === [] ? [] : WebInput::lines($input, 'uses', static fn(array $row): array => [
            'dispatch_id' => WebInput::id($row, 'dispatch_id'), 'quantity' => WebInput::quantity($row, 'quantity'),
        ], 300);
        $result = (new Subcontracting($this->db, $this->scope))->receive($key, $contract, WebInput::id($input, 'location'),
            WebInput::stringParameter($input, 'lot'), WebInput::quantity($input, 'quantity'), $date, $usage);
        return $this->outcome($contract, '委外产出已入待检库位。', $result);
    }

    private function matchFee(string $key, string $date, array $input): array
    {
        $this->requireRole('finance');
        $fee = WebInput::id($input, 'fee');
        $result = (new Subcontracting($this->db, $this->scope))->matchFee($key, $fee, WebInput::stringParameter($input, 'invoice'),
            WebInput::decimal($input['amount'] ?? null, 2, FinanceLedger::MAX_AMOUNT), $date, WebInput::stringParameter($input, 'reason'));
        return $this->outcome($this->contract('subcontract_fees', $fee), '加工费应付及确认差异已入账。', $result);
    }

    private function contract(string $table, int $id): int
    {
        $row = $this->db->row("SELECT subcontract_id FROM {$table} WHERE org_id=? AND id=?", [$this->scope->org, $id]);
        Value::assert($row !== null, '委外订单明细不存在。', 404);
        return (int) $row['subcontract_id'];
    }

    private function outcome(int $id, string $message, array $result): array
    {
        return ['target' => WebView::url('vendors', $id), 'message' => $message, 'result' => $result, 'status' => 201];
    }
}
