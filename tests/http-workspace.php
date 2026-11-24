<?php

declare(strict_types=1);

use ManufacturingErp\BatchWorker;
use ManufacturingErp\Database;
use ManufacturingErp\DemoScenario;
use ManufacturingErp\Identity;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Scheduling;
use ManufacturingErp\Value;
use ManufacturingErp\WorkOrders;

require __DIR__ . '/bootstrap.php';

final class WebTestClient
{
    private string $cookie = '';

    public function __construct(private readonly string $base)
    {
    }

    public function get(string $route, bool $json = true): array
    {
        return $this->request($route, null, '', $json);
    }

    public function post(string $action, array $data, string $csrf): array
    {
        return $this->request('/?action=' . rawurlencode($action), $data, $csrf, true);
    }

    public function submit(string $action, array $data, string $csrf): array
    {
        return $this->request('/?action=' . rawurlencode($action), $data + ['_csrf' => $csrf], '', false, true);
    }

    private function request(string $route, ?array $data, string $csrf, bool $json, bool $form = false): array
    {
        $headers = ['Accept: ' . ($json ? 'application/json' : 'text/html'), 'Connection: close'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        if ($data !== null) {
            $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        }
        if ($csrf !== '') {
            $headers[] = 'X-CSRF-Token: ' . $csrf;
        }
        $stream = stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST', 'header' => $headers,
            'content' => $data === null ? '' : ($form ? http_build_query($data) : Value::json($data)),
            'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $body = file_get_contents($this->base . $route, false, $stream);
        if ($body === false) {
            throw new RuntimeException('The ERP HTTP server did not respond.');
        }
        $raw = $http_response_header;
        $status = (int) explode(' ', array_shift($raw))[1];
        $response = [];
        foreach ($raw as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $response[strtolower($name)] = $value;
        }
        if (isset($response['set-cookie'])) {
            $this->cookie = explode(';', $response['set-cookie'], 2)[0];
        }
        return ['status' => $status, 'headers' => $response, 'body' => $json ? Value::fromJson($body) : $body];
    }
}

$base = (string) getenv('ERP_HTTP_URL');
if (!preg_match('#\Ahttp://127\.0\.0\.1:[0-9]{2,5}\z#', $base)) {
    throw new RuntimeException('Set ERP_HTTP_URL to the disposable ERP server on 127.0.0.1.');
}
$db = Database::connect();
$identity = new Identity($db);
$tag = bin2hex(random_bytes(5));
$password = 'Dedicated HTTP integration passphrase!';
$adminEmail = 'workspace-owner-' . $tag . '@example.test';
$fixture = (new DemoScenario($db))->install($adminEmail, $password);
$stock = $fixture['objects'];
$foreignEmail = 'workspace-outside-' . $tag . '@example.test';
$external = $identity->initialize('Unrelated HTTP group', 'UNRELATED', 'Unrelated factory', 'CNY', 'External administrator',
    $foreignEmail, $password);
$adminId = $identity->authenticate($adminEmail, $password);
$scope = $identity->scope($adminId, $fixture['org_id']);
$warehouseEmail = 'workspace-warehouse-' . $tag . '@example.test';
$master = new MasterData($db, $scope);
$master->addMember('<Warehouse operator>', $warehouseEmail, $password, 'warehouse');
$vendorName = 'Surface finishing plant';
$supplier = $master->addParty('SURFACE', $vendorName, 'supplier');
$raw = $master->addLocation('RAW-VENDOR', 'Vendor staging');
$input = $master->addItem('QA-RAW', 'Inspection blank', 'ea', 'purchase', 100, 0);
$tradeMaster = new MasterData($db, $identity->scope($adminId, $fixture['trade_org_id']));
$tradeMaster->addItem('QA-RAW', 'Inspection blank', 'ea', 'purchase', 100, 0);
$tradeWarehouse = $tradeMaster->addLocation('TRADE-HTTP', 'Inbound group transfers');
$output = $master->addItem('QA-COATED', 'Coated blank', 'ea', 'subcontract', 200, 0);
$day = $fixture['as_of'];
(new ProductEngineering($db, $scope))->publishBom($output, $day, [['item' => $input, 'quantity' => 1000, 'uom' => 'ea']]);
$vendorLot = (new Inventory($db, $scope))->openingBalance('http-vendor-stock', $input, $raw, 'HTTP-VENDOR-STOCK', 'ea', 3000, 100, $day)['lot_id'];
$works = new WorkOrders($db, $scope);

$client = new WebTestClient($base);
$guest = $client->get('/?page=session');
same(303, $client->get('/', false)['status'], 'Guests are sent to the login page');
same(401, $client->get('/?page=planning')['status'], 'Unauthenticated API access returns 401');
same(false, $guest['body']['authenticated'], 'Anonymous session can obtain an antiforgery token');
same(true, str_contains($guest['headers']['set-cookie'] ?? '', 'HttpOnly'), 'Session cookie is HttpOnly');
same(true, str_contains($guest['headers']['set-cookie'] ?? '', 'SameSite=Lax'), 'Session cookie has SameSite=Lax');
$login = ['email' => $adminEmail, 'password' => $password];
same(403, $client->post('login', $login, '')['status'], 'Login requires the session token');
same(401, $client->post('login', ['email' => $adminEmail, 'password' => 'wrong'], $guest['body']['csrf'])['status'], 'Bad credentials are rejected');
$authenticated = $client->post('login', $login, $guest['body']['csrf']);
same(200, $authenticated['status'], 'Valid credentials sign in');
$csrf = $authenticated['body']['csrf'];
same(true, $csrf !== $guest['body']['csrf'], 'Login rotates the antiforgery token');
same($fixture['org_id'], $authenticated['body']['organization_id'], 'Login enters the manufacturing ledger');
same(2, count($authenticated['body']['organizations']), 'UI offers both authorized organizations');
$html = $client->get('/', false);
same(200, $html['status'], 'Workspace renders an HTML page');
same(true, str_contains($html['body'], '合成数据回放'), 'Workspace identifies synthetic data');
same(true, str_contains($html['body'], '¥20.00'), 'Workspace renders the verified material variance');
same(true, str_contains($html['headers']['content-security-policy'], "frame-ancestors 'none'"), 'Authenticated pages disallow framing');
$sales = $client->get('/?page=sales&id=' . $stock['sales_order_id'], false);
same(200, $sales['status'], 'Sales details are available in the workspace');
same(true, str_contains($sales['body'], 'SO-DEVICE-100'), 'Workspace resolves the same sales instance');
$csv = $client->get('/?page=export&kind=lot-trace&id=' . $stock['substitute_lot_id'], false);
same(200, $csv['status'], 'Browser can download the lot recall CSV');
same(true, str_starts_with($csv['body'], "\xEF\xBB\xBF"), 'Binary response preserves the CSV BOM');
same(true, str_contains($csv['body'], 'SHIP-DEVICE-98'), 'Downloaded recall file contains the actual shipment');
same('attachment; filename="lot-trace-' . $stock['substitute_lot_id'] . '.csv"', $csv['headers']['content-disposition'], 'The exported filename is stable');

$vendorWorkInput = ['number' => 'WO-COATING', 'item' => $output, 'quantity' => '2', 'engineering_on' => $day,
    'starts_on' => $day, 'due_on' => $day, 'request_key' => 'http-vendor-work'];
$vendorWork = $client->post('create-work', $vendorWorkInput, $csrf)['body']['work_id'];
$client->post('approve-work', ['id' => $vendorWork], $csrf);
same(409, $client->post('release-work', ['id' => $vendorWork], $csrf)['status'], 'Outside processing requires an approved vendor contract');
same(true, str_contains($client->get('/?page=vendors', false)['body'], 'WO-COATING'), 'Vendor picker shows the unsigned outside work order');
$contractResult = $client->post('create-vendor-contract', ['work' => $vendorWork, 'number' => 'OUT-COATING', 'supplier' => $supplier,
    'unit_fee' => '1.00', 'posted_on' => $day, 'request_key' => 'http-vendor-order'], $csrf);
same(201, $contractResult['status'], 'Buyer records the chosen outside supplier and fee');
$contract = $contractResult['body']['subcontract_id'];
same('released', $client->post('release-work', ['id' => $vendorWork], $csrf)['body']['state'], 'Contract makes outside production releasable');
$material = $works->details($vendorWork)['materials'][0]['id'];
$extra = $client->post('authorize-material', ['material' => $material, 'kind' => 'overage', 'use_item' => $input, 'quantity' => '0.5',
    'reason' => 'Additional setup blanks', 'request_key' => 'http-vendor-extra'], $csrf)['body']['authorization_id'];
same(true, str_contains($client->get('/?page=vendors&id=' . $contract, false)['body'], 'HTTP-VENDOR-STOCK'), 'Outside dispatch picks a real input lot');
$dispatchInput = ['id' => $contract, 'material' => $material, 'lot' => $vendorLot, 'location' => $raw, 'quantity' => '2.5',
    'posted_on' => $day, 'request_key' => 'http-vendor-send', 'overage' => $extra];
$dispatchResponse = $client->post('send-vendor-material', $dispatchInput, $csrf);
same(201, $dispatchResponse['status'], 'Warehouse sends authorized stock to the contractor');
$dispatch = $dispatchResponse['body']['dispatch_id'];
$receiptResult = $client->post('receive-vendor-output', ['id' => $contract, 'location' => $raw, 'lot' => 'OUT-COATED', 'quantity' => '2',
    'posted_on' => $day, 'request_key' => 'http-vendor-receive', 'uses' => [['dispatch_id' => $dispatch, 'quantity' => '2']]], $csrf);
same(201, $receiptResult['status'], 'Subcontract output uses actual dispatched inputs');
same(400, $receiptResult['body']['actual_minor'], 'Input values and contract fee are both capitalized');
$pendingVendorLot = $receiptResult['body']['inspection_id'];
same('2', $client->get('/?page=quality&id=' . $pendingVendorLot)['body']['details']['待检'], 'Vendor output appears in quality');
$recoveryInput = ['dispatch' => $dispatch, 'location' => $raw, 'quantity' => '0.25', 'posted_on' => $day,
    'reason' => 'Unused material received from vendor', 'request_key' => 'http-vendor-recover'];
$recovery = $client->post('return-vendor-material', $recoveryInput, $csrf);
same(250, $recovery['body']['outside_milli'], 'Material physically returned reduces contractor custody');
$lossInput = ['dispatch' => $dispatch, 'quantity' => '0.25', 'posted_on' => $day, 'reason' => 'Supplier reported tooling loss',
    'request_key' => 'http-vendor-loss'];
$lost = $client->post('write-off-vendor-material', $lossInput, $csrf);
same(0, $lost['body']['outside_milli'], 'The separately posted loss clears remaining contractor custody');
same($lost['body']['recovery_id'], $client->get('/?page=receipt&operation=vendor.recover&request_key=http-vendor-loss')['body']['result']['recovery_id'],
    'Vendor reconciliation is recoverable by request key');
$feeResult = $client->post('match-vendor-fee', ['fee' => $receiptResult['body']['fee_id'], 'invoice' => 'VENDOR-COATING-01',
    'amount' => '1.75', 'posted_on' => $day, 'reason' => 'Approved supplier rebate', 'request_key' => 'http-vendor-fee'], $csrf);
same(-25, $feeResult['body']['variance_minor'], 'Confirmed fee difference remains signed');

$sourcing = $client->get('/?page=sourcing')['body']['tables'][0]['rows'][0];
$billId = $client->get('/?page=payables&id=' . $stock['payable_id'])['body']['links'][0]['url'];
$journals = $client->get('/?page=journals&period=' . $day)['body']['tables'][0]['rows'];
$journal = $journals[0]['id'];
$screens = [
    '/?page=purchases' => 'PO-PIN-SHORTAGE-100',
    '/?page=purchases&id=' . $stock['planned_order_id'] => 'RC-NET-SHORTAGE',
    '/?page=sourcing' => '已定标',
    '/?page=sourcing&id=' . $sourcing['id'] => 'BID-PIN',
    '/?page=inbound&id=' . $stock['planned_receipt_id'] => 'PIN-ORDERED-100',
    '/?page=inventory' => '批次库存',
    '/?page=inventory&id=' . $stock['substitute_lot_id'] => '加强定位销',
    '/?page=trace&direction=downstream&id=' . $stock['substitute_lot_id'] => 'SHIP-DEVICE-98',
    '/?page=trace&direction=upstream&id=' . $stock['finished_lot_id'] => 'PIN-R-10',
    '/?page=work' => 'WO-DEVICE-100',
    '/?page=work&id=' . $stock['work_id'] => '成本差异汇总',
    '/?page=vendors' => 'OUT-COATING',
    '/?page=vendors&id=' . $contract => 'VENDOR-COATING-01',
    '/?page=quality' => 'DEVICE-FINISHED-100',
    '/?page=quality&id=' . $stock['inspection_id'] => '判定记录',
    '/?page=shipments' => 'SHIP-DEVICE-98',
    '/?page=shipments&id=' . $stock['shipment_id'] => '6370.00',
    '/?page=finance' => '关期快照摘要',
    '/?page=finance&period=' . $day => '期末应收应付',
    '/?page=receivables&open=1' => '6370.00',
    '/?page=receivables&id=' . $stock['receivable_id'] => '关联交货单',
    '/?page=payables&id=' . $stock['payable_id'] => '1560.00',
    $billId => 'RC-NET-SHORTAGE',
    '/?page=journals&period=' . $day => '下载凭证 CSV',
    '/?page=journals&id=' . $journal => '借贷分录',
    '/?page=trial&cutoff=2037-06-30' => '6370.00',
    '/?page=intercompany' => '法人物流及对应金额',
];
foreach ($screens as $route => $expected) {
    $response = $client->get($route, false);
    same(200, $response['status'], 'Render ' . $route);
    same(true, str_contains($response['body'], $expected), 'Expected data on ' . $route);
}
$cost = $client->get('/?page=work&id=' . $stock['work_id'])['body'];
$summaries = array_values(array_filter($cost['tables'], static fn(array $table): bool => $table['title'] === '成本差异汇总'));
same(2000, (int) array_column($summaries[0]['rows'], 'variance_minor', 'element')['material'], 'Workbench reports actual material variance');
same(404, $client->get('/?page=payables&id=' . $stock['receivable_id'])['status'], 'AR cannot be read through the AP route');
$openReview = $client->get('/?page=finance&period=' . $day)['body'];
same('开放', $openReview['details']['状态'], 'Open fiscal review is distinguishable from archived snapshots');

$rfq = $client->post('create-rfq', ['number' => 'RFQ-HTTP', 'item' => $input, 'quantity' => '3.5', 'needed_on' => $day,
    'request_key' => 'http-rfq-' . $tag], $csrf);
same(201, $rfq['status'], 'Buyer can open an RFQ through HTTP');
$rfqId = $rfq['body']['request_id'];
$quote = $client->post('record-quote', ['id' => $rfqId, 'supplier' => $supplier, 'number' => 'QUOTE-HTTP',
    'unit_price' => '1.25', 'available_on' => $day, 'valid_until' => $day, 'request_key' => 'http-quote-' . $tag], $csrf);
same(201, $quote['status'], 'Quote form creates a supplier response');
$quoteId = $quote['body']['quote_id'];
same($quoteId, $client->post('award-rfq', ['id' => $rfqId, 'quote_id' => $quoteId, 'decision_on' => $day], $csrf)['body']['quote_id'],
    'Award selects the recorded quote');
$purchase = $client->post('create-purchase', ['number' => 'PO-HTTP', 'supplier' => $supplier, 'ordered_on' => $day,
    'request_key' => 'http-po-' . $tag, 'lines' => [
        ['item' => $input, 'uom' => 'ea', 'quantity' => '3.5', 'unit_price' => '1.25', 'due_on' => $day, 'quote_id' => $quoteId],
    ]], $csrf);
same(201, $purchase['status'], 'Buyer creates a purchase order against an accepted quote');
$orderId = $purchase['body']['order_id'];
$line = $purchase['body']['line_ids'][0];
same('issued', $client->post('issue-purchase', ['id' => $orderId], $csrf)['body']['state'], 'Purchase is issued from the workbench');
$purchaseScreen = $client->get('/?page=purchases&id=' . $orderId, false);
same(true, str_contains($purchaseScreen['body'], 'name="lines[0][line_id]"'), 'Purchase receipt form renders repeatable batch controls');
same(true, str_contains($purchaseScreen['body'], 'QA-RAW · 待收 3.5 ea'), 'Receipt can pick the item by name and remaining amount');
$receiptData = ['id' => $orderId, 'number' => 'RC-HTTP', 'received_on' => $day, 'request_key' => 'http-rc-' . $tag, 'lines' => [
    0 => ['line_id' => $line, 'lot' => 'HTTP-LOT-A', 'location' => $raw, 'quantity' => '1.125'],
    2 => ['line_id' => $line, 'lot' => 'HTTP-LOT-B', 'location' => $raw, 'quantity' => '2.375'],
]];
$form = $client->submit('receive-purchase', $receiptData, $csrf);
same(303, $form['status'], 'Browser form posts two batches with a deleted middle row');
$receiptReplay = [...$receiptData, 'lines' => array_values($receiptData['lines'])];
$postedReceipt = $client->post('receive-purchase', $receiptReplay, $csrf);
same(201, $postedReceipt['status'], 'Repeating the same request after final receipt returns the stored result');
$receiptId = $postedReceipt['body']['receipt_id'];
same('/?page=inbound&id=' . $receiptId, $form['headers']['location'], 'Browser lands on the original receipt');
same($receiptId, $client->get('/?page=receipt&operation=purchase.receive&request_key=' . $receiptData['request_key'])['body']['result']['receipt_id'],
    'Lost response is recoverable by receipt key');
$receivedLines = $client->get('/?page=inbound&id=' . $receiptId)['body']['tables'][0]['rows'];
same([1125, 2375], array_column($receivedLines, 'pending_milli'), 'Each confirmed batch has its own pending inspection');
$inspectId = $receivedLines[0]['inspection_id'];
$qcInput = ['id' => $inspectId, 'version' => 1, 'accepted' => '1', 'rejected' => '0', 'measured_on' => $day,
    'reason' => 'Initial incoming sample released.', 'request_key' => 'http-qc-' . $tag];
$firstQC = $client->post('decide-inspection', $qcInput, $csrf);
same(201, $firstQC['status'], 'QC can approve whole and decimal quantities over HTTP');
same(125, $firstQC['body']['pending_milli'], 'Partial release retains the exact fractional balance');
same($firstQC['body']['decision_id'], $client->post('decide-inspection', $qcInput, $csrf)['body']['decision_id'],
    'Resubmitting the decision preserves its previous version and movement IDs');
same(409, $client->post('decide-inspection', [...$qcInput, 'request_key' => 'http-stale-' . $tag], $csrf)['status'],
    'A second actor with a stale inspection version cannot overwrite the first decision');
$finalQC = $client->post('decide-inspection', [...$qcInput, 'version' => 2, 'accepted' => '0', 'rejected' => '0.125',
    'request_key' => 'http-qc-final-' . $tag], $csrf);
same('completed', $finalQC['body']['state'], 'Latest version disposes of the remaining quantity');

$apScreen = $client->get('/?page=payables', false);
same(true, str_contains($apScreen['body'], 'RC-HTTP / HTTP-LOT-A'), 'Invoice matching can select the unmatched receipt by lot');
$invoiceInput = ['supplier' => $supplier, 'number' => 'AP-HTTP', 'posted_on' => $day, 'due_on' => '2037-06-30',
    'request_key' => 'http-invoice-' . $tag, 'lines' => [
        ['receipt_line_id' => $receivedLines[0]['id'], 'amount' => '1.50'],
        ['receipt_line_id' => $receivedLines[1]['id'], 'amount' => '3.00'],
    ]];
$invoice = $client->post('post-purchase-bill', $invoiceInput, $csrf);
same(201, $invoice['status'], 'Accounts payable matches both receipt lines');
same(12, $invoice['body']['variance_minor'], 'UI posts the actual procurement price difference');
same($invoice['body']['bill_id'], $client->post('post-purchase-bill', $invoiceInput, $csrf)['body']['bill_id'], 'Invoice replay keeps its original journal');
$settleInput = ['id' => $invoice['body']['payable_id'], 'reference' => 'AP-HTTP-PAYMENT', 'amount' => '2.20',
    'posted_on' => $day, 'request_key' => 'http-settlement-' . $tag];
$settled = $client->post('settle-open-item', $settleInput, $csrf);
same(201, $settled['status'], 'Finance can partially settle an open item');
same(230, $settled['body']['open_minor'], 'Partial settlement preserves outstanding cents');
same($settled['body']['journal_id'], $client->post('settle-open-item', $settleInput, $csrf)['body']['journal_id'],
    'Settlement replay cannot deduct twice');

$adjustInput = ['number' => 'ADJUST-HTTP', 'posted_on' => $day, 'reason' => 'Month-end freight correction',
    'request_key' => 'http-adjustment-' . $tag, 'corrects_journal_id' => $journal,
    'lines' => [['account' => 'GENERAL_EXPENSE', 'amount' => '1.18'], ['account' => 'BANK', 'amount' => '-1.18']]];
$adjusted = $client->post('post-adjustment', $adjustInput, $csrf);
same(201, $adjusted['status'], 'Balanced signed decimal adjustment can be posted');
same(118, $client->get('/?page=journals&id=' . $adjusted['body']['journal_id'])['body']['tables'][0]['rows'][1]['credit_minor'],
    'Adjustment view preserves the financial credit');
same(422, $client->post('post-adjustment', [...$adjustInput, 'lines' => [
    ['account' => 'GENERAL_EXPENSE', 'amount' => '999999999999999999.99'], ['account' => 'BANK', 'amount' => '-1.18'],
]], $csrf)['status'], 'Out-of-range decimal is rejected before integer conversion');

$transferPage = $client->get('/?page=intercompany&target=' . $fixture['trade_org_id'], false);
same(true, str_contains($transferPage['body'], 'TRADE-HTTP'), 'Transfer form can select target warehouse from the authorized ledger');
same(403, $client->get('/?page=intercompany&target=' . $external['org'])['status'], 'Target picker cannot disclose a foreign ledger’s locations');
$transferInput = ['number' => 'TRANSFER-HTTP', 'destination' => $fixture['trade_org_id'], 'lot' => $vendorLot,
    'from' => $raw, 'to' => $tradeWarehouse, 'destination_code' => 'HTTP-TRADE-BATCH', 'quantity' => '0.250', 'posted_on' => $day,
    'request_key' => 'http-transfer-' . $tag];
$transfer = $client->post('transfer-between-organizations', $transferInput, $csrf);
same(201, $transfer['status'], 'Operator posts both intercompany sides');
same(25, $transfer['body']['amount_minor'], 'Transfer uses exact carrying value');
same($transfer['body']['destination_lot_id'], $client->post('transfer-between-organizations', $transferInput, $csrf)['body']['destination_lot_id'],
    'Intercompany retry reuses the destination inventory batch');

$dock = $master->addLocation('SHIP-DOCK', 'Shipping dock');
$customer = $master->addParty('BUYER-HTTP', 'Outbound HTTP customer', 'customer');
$opening = $client->post('open-stock', ['item' => $input, 'location' => $raw, 'code' => 'HTTP-OUTBOUND', 'uom' => 'ea',
    'quantity' => '5', 'unit_cost' => '1.00', 'posted_on' => $day, 'request_key' => 'open-outbound-' . $tag], $csrf);
same(201, $opening['status'], 'Warehouse records opening balance');
$outboundLot = $opening['body']['lot_id'];
$moved = $client->post('move-stock', ['id' => $outboundLot, 'from' => $raw, 'to' => $dock, 'quality' => 'available',
    'quantity' => '2', 'posted_on' => $day, 'request_key' => 'move-outbound-' . $tag], $csrf);
same(201, $moved['status'], 'Warehouse transfers a batch to the dispatch dock');
$sale = $client->post('create-sale', ['number' => 'SO-HTTP-OUTBOUND', 'customer' => $customer, 'request_key' => 'sale-outbound-' . $tag,
    'deliveries' => [
        ['line' => 1, 'item' => $input, 'unit_price' => '3.50', 'due_on' => $day, 'quantity' => '2'],
        ['line' => 1, 'item' => $input, 'unit_price' => '3.50', 'due_on' => '2037-06-25', 'quantity' => '1'],
    ]], $csrf);
same(201, $sale['status'], 'Sales can split one line into two confirmed dates');
[$firstDemand, $secondDemand] = $sale['body']['demand_ids'];
$saleOrder = $sale['body']['order_id'];
same([$firstDemand, $secondDemand], $client->post('freeze-sale', ['id' => $saleOrder], $csrf)['body']['demand_ids'], 'Both sales installments freeze together');
$demandScreen = $client->get('/?page=sales&id=' . $saleOrder . '&demand=' . $firstDemand, false);
same(true, str_contains($demandScreen['body'], 'HTTP-OUTBOUND / SHIP-DOCK · 2'), 'Worklist shows the unallocated balance by batch and bin');
$holdInput = ['demand' => $firstDemand, 'stock_bin' => $outboundLot . ':' . $dock, 'quantity' => '1', 'request_key' => 'hold-outbound-' . $tag];
$reserved = $client->post('reserve-stock', $holdInput, $csrf);
same(201, $reserved['status'], 'Stock is reserved against the chosen frozen demand');
$released = $client->post('release-reservation', ['id' => $reserved['body']['reservation_id'], 'reason' => 'Regroup physical picking',
    'request_key' => 'release-outbound-' . $tag], $csrf);
same(1000, $released['body']['released_milli'], 'Releasing a reservation returns the unshipped quantity');
$reserveAgain = $client->post('reserve-stock', [...$holdInput, 'request_key' => 'hold-again-outbound-' . $tag], $csrf);
$outboundReservation = $reserveAgain['body']['reservation_id'];
$dispatchScreen = $client->get('/?page=shipments&customer=' . $customer, false);
same(true, str_contains($dispatchScreen['body'], 'SO-HTTP-OUTBOUND / HTTP-OUTBOUND'), 'Shipment form shows customer-specific allocations');
$sentInput = ['number' => 'SHIP-HTTP-OUTBOUND', 'customer' => $customer, 'dispatched_on' => $day,
    'request_key' => 'shipped-outbound-' . $tag, 'lines' => [['reservation_id' => $outboundReservation, 'quantity' => '1']]];
$sent = $client->post('dispatch-delivery', $sentInput, $csrf);
same(201, $sent['status'], 'Delivery uses the active reservation');
same(350, $sent['body']['lines'][0]['revenue_minor'], 'Dispatch posts the sales amount from the frozen order');
same(100, $sent['body']['lines'][0]['cogs_minor'], 'Dispatch posts the exact batch carrying cost');
same($sent['body']['shipment_id'], $client->post('dispatch-delivery', $sentInput, $csrf)['body']['shipment_id'], 'Dispatch replay does not ship a second time');
$cancelDemand = $client->post('cancel-demand', ['id' => $firstDemand, 'reason' => 'Customer cancelled the remainder'], $csrf);
same(200, $cancelDemand['status'], 'Sales can cancel the remaining volume after a partial delivery');
$disposition = $cancelDemand['body']['dispositions'][0];
same('shipping', $disposition['source_kind'], 'Cancel preserves a traceable disposition for the already dispatched units');
same('resolved', $client->post('resolve-disposition', ['id' => $disposition['id'],
    'reason' => 'Keep the completed shipment and invoice'], $csrf)['body']['state'], 'A planner records the disposition');

$buildOn = '2037-06-18';
$dept = $master->createDepartment('SHOP-HTTP', 'HTTP engineering shop');
$engineering = new ProductEngineering($db, $scope);
$center = $engineering->addCenter($dept, 'SHOP-HTTP', 'Bench production');
$scheduling = new Scheduling($db, $scope);
$machine = $scheduling->addMachine($center, 'MACHINE-HTTP', 'HTTP fabrication bench');
$scheduling->addShift($center, $buildOn . ' 08:00:00', $buildOn . ' 17:00:00');
$product = $master->addItem('HTTP-PRODUCED', 'HTTP assembled part', 'ea', 'manufacture', 650, 0);
$engineering->publishBom($product, $day, [['item' => $input, 'quantity' => 1000, 'uom' => 'ea']]);
$engineering->publishRoute($product, $day, [['center' => $center, 'label' => 'HTTP Bench Assembly', 'setup_minutes' => 1, 'run_seconds_per_unit' => 5]]);
$workInput = ['number' => 'WO-HTTP-PRODUCTION', 'item' => $product, 'quantity' => '2', 'engineering_on' => $day,
    'starts_on' => $buildOn, 'due_on' => $buildOn, 'request_key' => 'new-web-work-' . $tag];
$newWork = $client->post('create-work', $workInput, $csrf);
same(201, $newWork['status'], 'Planning can create a shop order with a frozen routing by HTTP');
$workId = $newWork['body']['work_id'];
same('approved', $client->post('approve-work', ['id' => $workId], $csrf)['body']['state'], 'Shop order proceeds to capacity reservation');
same(409, $client->post('release-work', ['id' => $workId], $csrf)['status'], 'Workbench cannot release an unscheduled operation');
$bookPage = $client->get('/?page=work&id=' . $workId, false);
same(true, str_contains($bookPage['body'], 'MACHINE-HTTP'), 'Work screen lists machines in the frozen work centers');
$detail = $works->details($workId);
$operationId = $detail['operations'][0]['id'];
$lineId = $detail['materials'][0]['id'];
$booked = $client->post('book-operation', ['operation' => $operationId, 'machine' => $machine, 'starts_at' => $buildOn . 'T08:00',
    'ends_at' => $buildOn . 'T09:00', 'request_key' => 'work-book-' . $tag], $csrf);
same(201, $booked['status'], 'Planner reserves working time in the actual shift');
same('released', $client->post('release-work', ['id' => $workId], $csrf)['body']['state'], 'Reserved shop order can be released');
same('paused', $client->post('pause-work', ['id' => $workId, 'reason' => 'Machine setup check'], $csrf)['body']['state'],
    'Production can pause the released order');
same('released', $client->post('resume-work', ['id' => $workId], $csrf)['body']['state'], 'An unstarted paused order resumes as released');
$overage = $client->post('authorize-material', ['material' => $lineId, 'kind' => 'overage', 'use_item' => $input,
    'quantity' => '0.50', 'reason' => 'Extra physical blanks for setup', 'request_key' => 'work-overage-' . $tag], $csrf);
same(201, $overage['status'], 'Planner authorizes exact excess quantity');
$issueInput = ['material' => $lineId, 'lot' => $outboundLot, 'location' => $raw, 'quantity' => '2.50',
    'posted_on' => $buildOn, 'request_key' => 'work-issue-http-' . $tag];
same(409, $client->post('issue-material', $issueInput, $csrf)['status'], 'Warehouse cannot issue excess stock without the approval');
$issueResult = $client->post('issue-material', $issueInput + ['overage' => $overage['body']['authorization_id']], $csrf);
same(201, $issueResult['status'], 'Approved material issue posts the exact requested lot quantity');
$issueId = $issueResult['body']['issue_id'];
$actual = $client->post('report-operation', ['operation' => $operationId, 'starts_at' => $buildOn . 'T08:00',
    'ends_at' => $buildOn . 'T08:30', 'labor_rate' => '16', 'overhead_rate' => '4', 'request_key' => 'work-actual-http-' . $tag], $csrf);
same(1800, $actual['body']['actual_seconds'], 'Actual time remains separate from standard capacity');
$workComplete = $client->get('/?page=work&id=' . $workId, false);
same(true, str_contains($workComplete['body'], '完工并提交质检'), 'Actual completion form shows after operations are posted');
$completedWork = $client->post('complete-work', ['id' => $workId, 'location' => $dock, 'lot' => 'HTTP-MANUFACTURED',
    'quantity' => '2', 'posted_on' => $buildOn, 'request_key' => 'work-complete-http-' . $tag,
    'uses' => [['issue_id' => $issueId, 'quantity' => '2']]], $csrf);
same(201, $completedWork['status'], 'Production reports output and sends it to inspection');
same(1200, $completedWork['body']['actual_minor'], 'Completed lot values consumed material, labor and overhead');
$closeInput = ['id' => $workId, 'posted_on' => $buildOn];
$blockedWork = $client->post('close-work', $closeInput, $csrf);
same(409, $blockedWork['status'], 'Work order with pending inspection and unused material cannot close');
same(true, in_array('pending_inspection', array_column($blockedWork['body']['blockers'], 'kind'), true), 'Pending inspection is named in the close response');
$returnedStock = $client->post('return-material', ['issue' => $issueId, 'location' => $raw, 'quantity' => '0.50',
    'posted_on' => $buildOn, 'request_key' => 'work-return-http-' . $tag], $csrf);
same(201, $returnedStock['status'], 'Unused material is returned from the original issue');
same('completed', $client->post('decide-inspection', ['id' => $completedWork['body']['inspection_id'], 'version' => 1,
    'accepted' => '2', 'rejected' => '0', 'rework' => '0', 'measured_on' => $buildOn, 'reason' => 'Two units conform',
    'request_key' => 'work-inspection-http-' . $tag], $csrf)['body']['state'], 'Internal production output is released by quality');
same('closed', $client->post('close-work', $closeInput, $csrf)['body']['state'], 'Reconciled work order closes in the same ledger');

$closed = $client->post('close-period', ['period' => $day], $csrf);
same(200, $closed['status'], 'Financial period closes through the authorized HTTP action');
same($closed['body']['digest'], $client->get('/?page=finance&period=' . $day)['body']['details']['快照摘要'],
    'Financial workspace renders the persisted snapshot digest');
same($closed['body']['digest'], $client->post('close-period', ['period' => $day], $csrf)['body']['digest'], 'Repeated closing preserves the archived snapshot');
same(409, $client->post('post-adjustment', [...$adjustInput, 'number' => 'ADJUST-POST-CLOSE',
    'request_key' => 'http-adjust-closed-' . $tag], $csrf)['status'], 'New posting is refused after closure');

$posted = ['as_of' => '2037-06-01', 'horizon_on' => '2037-06-15', 'request_key' => 'http-mrp-' . $tag];
$submission = $client->post('calculate-plan', $posted, $csrf);
same(202, $submission['status'], 'Material planning submits a durable asynchronous job');
$jobId = $submission['body']['job_id'];
same($jobId, $client->post('calculate-plan', $posted, $csrf)['body']['job_id'], 'A repeated action delivers the same HTTP job receipt');
$receipt = '/?page=receipt&operation=jobs.enqueue&request_key=' . $posted['request_key'];
same($jobId, $client->get($receipt)['body']['result']['job_id'], 'Durable receipt is queryable by the original request key');
same($jobId, (new BatchWorker($db))->next()['job_id'], 'Worker takes the job published through HTTP');
$response = $client->get('/?page=jobs&id=' . $jobId, false);
same(true, str_contains($response['body'], '计划运行 #'), 'Task view links its successful result to the plan');

$change = $client->post('switch-org', ['org_id' => $fixture['trade_org_id']], $csrf);
same($fixture['trade_org_id'], $change['body']['organization_id'], 'User can select their other approved organization');
$csrf = $change['body']['csrf'];
same(404, $client->get('/?page=sales&id=' . $stock['sales_order_id'])['status'], 'Selected trading ledger cannot read a manufacturing order');
same(404, $client->get($receipt)['status'], 'Same user receives no old receipt while in another organization');
same('HTTP-TRADE-BATCH', $client->get('/?page=inventory&id=' . $transfer['body']['destination_lot_id'])['body']['title'],
    'Switching ledgers shows the separately posted inventory batch');
same(403, $client->post('switch-org', ['org_id' => $external['org']], $csrf)['status'], 'Organization selection does not allow unrelated access');

$warehouse = new WebTestClient($base);
$whStart = $warehouse->get('/?page=session')['body']['csrf'];
$whSignIn = $warehouse->post('login', ['email' => $warehouseEmail, 'password' => $password], $whStart);
same($fixture['org_id'], $whSignIn['body']['organization_id'], 'Worker sees its assigned ledger');
same(403, $warehouse->post('calculate-plan', $posted, $whSignIn['body']['csrf'])['status'], 'Warehouse role cannot enqueue an MRP calculation');
same(403, $warehouse->get('/?page=jobs&id=' . $jobId)['status'], 'Warehouse role cannot read MRP task output');
same(403, $warehouse->get('/?page=payables&id=' . $stock['payable_id'])['status'], 'Warehouse role cannot read an AP document');
same(403, $warehouse->get('/?page=export&kind=journals', false)['status'], 'Warehouse role cannot export journal entries');
same(403, $warehouse->post('decide-inspection', $qcInput, $whSignIn['body']['csrf'])['status'], 'Warehouse role cannot release an inspection');
same(404, $warehouse->get('/?page=receipt&operation=purchase.receive&request_key=' . $receiptData['request_key'])['status'],
    'Warehouse role cannot obtain another operator’s receipt by key');
same(409, $warehouse->post('receive-purchase', $receiptReplay, $whSignIn['body']['csrf'])['status'],
    'A request key already claimed by another operator cannot return their receipt or post again');
same(true, str_contains($warehouse->get('/', false)['body'], '&lt;Warehouse operator&gt;'), 'Workspace escapes operator names');

$other = new WebTestClient($base);
$otherToken = $other->get('/?page=session')['body']['csrf'];
$other->post('login', ['email' => $foreignEmail, 'password' => $password], $otherToken);
same(404, $other->get('/?page=jobs&id=' . $jobId)['status'], 'Unrelated organization cannot query a task by its ID');
same(404, $other->get($receipt)['status'], 'An unrelated organization cannot query a receipt');
same(404, $other->get('/?page=export&kind=lot-trace&id=' . $stock['substitute_lot_id'], false)['status'], 'Cross-tenant CSV request is refused');
same(404, $other->get('/?page=vendors&id=' . $contract)['status'], 'Cross-tenant vendor order lookup is refused');
same(404, $other->get('/?page=journals&id=' . $journal)['status'], 'Cross-tenant journal lookup is refused');

$newOrg = $client->post('create-organization', ['code' => 'HTTP-SETUP', 'name' => 'Engineering pilot', 'currency' => 'CNY'], $csrf);
same(201, $newOrg['status'], 'Administrator creates a new ledger through the workbench');
$pilot = $newOrg['body']['org_id'];
$switchPilot = $client->post('switch-org', ['org_id' => $pilot], $csrf);
same($pilot, $switchPilot['body']['organization_id'], 'New ledger is immediately selectable');
$csrf = $switchPilot['body']['csrf'];
$setupEmail = 'workspace-plan-' . $tag . '@example.test';
$member = $client->post('add-member', ['name' => 'Pilot planner', 'email' => $setupEmail, 'password' => $password, 'role' => 'planner'], $csrf);
same(201, $member['status'], 'Administrator registers a planner in the new ledger');
$memberId = $member['body']['user_id'];
same(true, str_contains($client->get('/?page=members', false)['body'], $setupEmail), 'Group member listing shows the new account');
same(404, $client->post('set-membership', ['user' => $external['user'], 'role' => 'admin', 'enabled' => 1], $csrf)['status'],
    'Administrators cannot grant roles to an unrelated tenant account');
$department = $client->post('add-department', ['code' => 'PILOT', 'name' => 'Pilot workshop'], $csrf)['body']['department_id'];
$planner = new WebTestClient($base);
$plannerStart = $planner->get('/?page=session')['body']['csrf'];
$plannerLogin = $planner->post('login', ['email' => $setupEmail, 'password' => $password], $plannerStart);
same($pilot, $plannerLogin['body']['organization_id'], 'Planner enters the assigned pilot ledger');
$plannerCsrf = $plannerLogin['body']['csrf'];
same(403, $planner->get('/?page=members')['status'], 'Planner cannot browse account email addresses');
same(403, $planner->post('add-location', ['code' => 'PILOT', 'name' => 'Pilot area'], $plannerCsrf)['status'],
    'Planner cannot create a warehouse location');
same(201, $client->post('add-location', ['code' => 'PILOT', 'name' => 'Pilot area'], $csrf)['status'], 'Warehouse location is posted by an administrator');
same(201, $client->post('add-party', ['code' => 'PILOT-SRC', 'name' => 'Pilot supplier', 'kind' => 'supplier'], $csrf)['status'],
    'Supplier is available from the party directory');

$itemData = ['name' => 'Fixture component', 'uom' => 'ea', 'sourcing' => 'purchase', 'standard_cost' => '1.25', 'lead_days' => '2'];
$component = $planner->post('add-item', ['code' => 'SET-PART'] + $itemData, $plannerCsrf)['body']['item_id'];
$alternative = $planner->post('add-item', ['code' => 'SET-ALT'] + $itemData, $plannerCsrf)['body']['item_id'];
$product = $planner->post('add-item', ['code' => 'SET-PRODUCT', 'name' => 'Fixture assembly', 'sourcing' => 'manufacture'] + $itemData,
    $plannerCsrf)['body']['item_id'];
$tomorrow = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
same(201, $planner->post('publish-conversion', ['item' => $component, 'uom' => 'box', 'effective_on' => $day, 'factor' => '5'], $plannerCsrf)['status'],
    'Planner publishes a packaging conversion');
same(201, $planner->post('publish-conversion', ['item' => $component, 'uom' => 'box', 'effective_on' => $tomorrow, 'factor' => '6'], $plannerCsrf)['status'],
    'Future conversion can coexist with the current one');
$center = $planner->post('add-center', ['department' => $department, 'code' => 'CELL', 'name' => 'Assembly cell'], $plannerCsrf)['body']['center_id'];
$machine = $planner->post('add-machine', ['center' => $center, 'code' => 'BENCH', 'name' => 'Bench press'], $plannerCsrf)['body']['machine_id'];
$shift = ['center' => $center, 'starts_at' => $day . 'T08:00', 'ends_at' => $day . 'T16:00'];
same(201, $planner->post('add-shift', $shift, $plannerCsrf)['status'], 'Planner publishes a UTC shift');
same(409, $planner->post('add-shift', $shift, $plannerCsrf)['status'], 'The workbench rejects overlapping capacity shifts');
$engineering = '/?page=engineering&id=' . $product;
$engineeringHtml = $planner->get($engineering . '&on=' . $day, false)['body'];
same(true, str_contains($engineeringHtml, 'name="lines[0][alternatives][]"'), 'BOM form provides a multiselect for substitution');
same(true, str_contains($engineeringHtml, 'name="id" value="' . $product . '"'), 'Changing the effective date preserves the selected item');
$bomData = ['item' => $product, 'effective_on' => $day, 'lines' => [
    2 => ['item' => $component, 'quantity' => '2', 'uom' => 'box', 'loss_percent' => '1.25', 'alternatives' => [$alternative]],
]];
same(413, $planner->submit('publish-bom', ['_workbench' => '1'] + $bomData, $plannerCsrf)['status'],
    'A workbench form missing its final field is refused rather than posting a partial document');
$bomPost = $planner->submit('publish-bom', ['_workbench' => '1'] + $bomData + ['_complete' => '1'], $plannerCsrf);
same(303, $bomPost['status'], 'HTML submits a BOM with an alternative and a noncontiguous row index');
$bomUrl = $bomPost['headers']['location'];
$firstBom = $planner->get($bomUrl)['body'];
same(10_000, $firstBom['tables'][0]['rows'][0]['qty_per_milli'], 'BOM preserves the published transaction conversion');
same(125, $firstBom['tables'][0]['rows'][0]['loss_bps'], 'Loss is stored in basis points');
same('SET-ALT', $firstBom['tables'][0]['rows'][0]['alternatives'], 'BOM detail resolves the alternative code');
$bomData['effective_on'] = $tomorrow;
$bomData['lines'] = array_values($bomData['lines']);
$nextBom = $planner->post('publish-bom', $bomData, $plannerCsrf)['body'];
same(12_000, $planner->get($nextBom['url'])['body']['tables'][0]['rows'][0]['qty_per_milli'], 'New BOM applies the new version of the conversion');
$bomAt = $planner->get($engineering . '&on=' . $tomorrow)['body'];
same($nextBom['bom_id'], $bomAt['details']['BOM版本'], 'Engineering inquiry selects the version effective on the requested date');
$route = $planner->post('publish-route', ['item' => $product, 'effective_on' => $day, 'steps' => [
    ['center' => $center, 'label' => 'Press fit', 'setup_minutes' => 2, 'run_seconds_per_unit' => 30],
]], $plannerCsrf)['body'];
same(2, $planner->get($route['url'])['body']['tables'][0]['rows'][0]['setup_minutes'], 'Route detail shows standard preparation time');
$pilotWork = $planner->post('create-work', ['item' => $product, 'quantity' => '2', 'number' => 'WO-PILOT', 'engineering_on' => $day,
    'starts_on' => $day, 'due_on' => $day, 'request_key' => 'http-pilot-work'], $plannerCsrf)['body']['work_id'];
$planner->post('approve-work', ['id' => $pilotWork], $plannerCsrf);
$workUrl = '/?page=work&id=' . $pilotWork;
$pilotRecord = $planner->get($workUrl)['body'];
same(180, $pilotRecord['tables'][0]['rows'][0]['standard_seconds'], 'Work order snapshots standard engineering time');
same(true, in_array($bomUrl, array_column($pilotRecord['links'], 'url'), true), 'Work order links to its original BOM revision');
same(true, in_array($route['url'], array_column($pilotRecord['links'], 'url'), true), 'Work order links to its original route revision');
same(201, $planner->post('book-operation', ['operation' => $pilotRecord['tables'][0]['rows'][0]['id'], 'machine' => $machine,
    'starts_at' => $day . 'T09:00', 'ends_at' => $day . 'T09:05', 'request_key' => 'http-pilot-book'], $plannerCsrf)['status'],
    'Planner books a production operation inside the published shift');
$calendar = $planner->get('/?page=capacity&from=' . $day . '&to=' . $day)['body'];
same($workUrl, $calendar['tables'][0]['rows'][0]['_links']['number'], 'Capacity calendar links to the booked work order');
same('BENCH', $calendar['tables'][0]['rows'][0]['machine'], 'Capacity calendar shows the assigned machine');
same('CELL', $calendar['tables'][1]['rows'][0]['code'], 'Capacity calendar shows the matching shift');
same(404, $other->get($bomUrl)['status'], 'Engineering version lookup is scoped to the ledger');
same(403, $warehouse->get($route['url'])['status'], 'Warehouse operators do not have engineering access');
$audit = $client->get('/?page=organizations')['body'];
same(true, in_array('bom.published', array_column($audit['tables'][2]['rows'], 'event_type'), true), 'Organization audit records the publication');
same(false, str_contains(Value::json($audit), $password), 'The audit excludes setup passwords');
same(200, $client->post('set-membership', ['user' => $memberId, 'role' => 'planner', 'enabled' => 0], $csrf)['status'],
    'An administrator can revoke a previously granted role');
same(401, $planner->get('/?page=capacity')['status'], 'Revoking the last ledger role invalidates the active session on the next request');

$logout = $client->post('logout', [], $csrf);
same(false, $logout['body']['authenticated'], 'Logout invalidates current identity');
same(401, $client->get('/?page=sales')['status'], 'After logout protected content is inaccessible');
finish('HTTP session, workbench, asynchronous receipts and CSV isolation');
