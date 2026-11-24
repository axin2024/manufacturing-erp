<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class ProcurementActions extends Service
{
    public function execute(string $action, array $input): array
    {
        $key = WebInput::stringParameter($input, 'request_key');
        return match ($action) {
            'create-purchase' => $this->create($key, $input),
            'issue-purchase', 'stop-purchase' => $this->changeOrder($action, $input),
            'receive-purchase' => $this->receive($key, $input),
            'create-rfq', 'record-quote', 'award-rfq' => $this->source($action, $key, $input),
            'decide-inspection' => $this->decide($key, $input),
        };
    }

    private function create(string $key, array $input): array
    {
        $lines = WebInput::lines($input, 'lines', static fn(array $row): array => [
            'item' => WebInput::id($row, 'item'), 'uom' => WebInput::stringParameter($row, 'uom'),
            'quantity' => WebInput::quantity($row, 'quantity'), 'unit_price_minor' => WebInput::price($row, 'unit_price'),
            'due_on' => WebInput::stringParameter($row, 'due_on'), 'proposal_id' => WebInput::optionalId($row, 'proposal_id'),
            'quote_id' => WebInput::optionalId($row, 'quote_id'),
        ]);
        $result = (new Purchasing($this->db, $this->scope))->create($key, WebInput::stringParameter($input, 'number'),
            WebInput::id($input, 'supplier'), WebInput::stringParameter($input, 'ordered_on'), $lines);
        return ['target' => WebView::url('purchases', $result['order_id']), 'message' => '采购草稿已创建。', 'result' => $result, 'status' => 201];
    }

    private function changeOrder(string $action, array $input): array
    {
        $id = WebInput::id($input, 'id');
        $purchasing = new Purchasing($this->db, $this->scope);
        if ($action === 'issue-purchase') {
            $result = $purchasing->issue($id);
            $message = '采购单已下达，在途量已进入计划。';
        } else {
            $result = $purchasing->stop($id, WebInput::stringParameter($input, 'reason'));
            $message = '采购单已结束。';
        }
        return ['target' => WebView::url('purchases', $id), 'message' => $message, 'result' => $result];
    }

    private function receive(string $key, array $input): array
    {
        $source = WebInput::stringParameter($input, 'source');
        $externalNumber = WebInput::stringParameter($input, 'external_number');
        $notice = $source === '' && $externalNumber === '' ? null : ReceiptNotice::identity($source, $externalNumber);
        $rows = WebInput::lines($input, 'lines', static fn(array $row): array => [
            'line_id' => WebInput::id($row, 'line_id'), 'lot' => WebInput::stringParameter($row, 'lot'),
            'location' => WebInput::id($row, 'location'), 'quantity' => WebInput::quantity($row, 'quantity'),
        ]);
        $result = (new Purchasing($this->db, $this->scope))->receive($key, WebInput::id($input, 'id'),
            WebInput::stringParameter($input, 'number'), WebInput::stringParameter($input, 'received_on'), $rows, $notice);
        return ['target' => WebView::url('inbound', $result['receipt_id']), 'message' => '收货已过账，批次等待检验。',
            'result' => $result + ['request_key' => $notice['event_key'] ?? $key], 'status' => 201];
    }

    private function source(string $action, string $key, array $input): array
    {
        $service = new Sourcing($this->db, $this->scope);
        if ($action === 'create-rfq') {
            $result = $service->request($key, WebInput::stringParameter($input, 'number'), WebInput::id($input, 'item'),
                WebInput::quantity($input, 'quantity'), WebInput::stringParameter($input, 'needed_on'),
                WebInput::optionalId($input, 'proposal_id'));
            return ['target' => WebView::url('sourcing', $result['request_id']), 'message' => '询价单已创建。', 'result' => $result, 'status' => 201];
        }
        $id = WebInput::id($input, 'id');
        if ($action === 'record-quote') {
            $result = $service->quote($key, $id, WebInput::id($input, 'supplier'), WebInput::stringParameter($input, 'number'),
                WebInput::price($input, 'unit_price'), WebInput::stringParameter($input, 'available_on'),
                WebInput::stringParameter($input, 'valid_until'));
            return ['target' => WebView::url('sourcing', $id), 'message' => '供应商报价已登记。', 'result' => $result, 'status' => 201];
        }
        $result = $service->award($id, WebInput::id($input, 'quote_id'), WebInput::stringParameter($input, 'decision_on'));
        return ['target' => WebView::url('sourcing', $id), 'message' => '已选择中选报价。', 'result' => $result];
    }

    private function decide(string $key, array $input): array
    {
        $id = WebInput::id($input, 'id');
        $result = (new Inspection($this->db, $this->scope))->decide($key, $id, WebInput::id($input, 'version'),
            WebInput::quantity($input, 'accepted'), WebInput::quantity($input, 'rejected'),
            WebInput::quantity($input + ['rework' => '0'], 'rework'),
            WebInput::stringParameter($input, 'measured_on'), WebInput::stringParameter($input, 'reason'));
        return ['target' => WebView::url('quality', $id), 'message' => '检验判定已过账。', 'result' => $result, 'status' => 201];
    }
}
