<?php

declare(strict_types=1);

use ManufacturingErp\BatchWorker;
use ManufacturingErp\Database;
use ManufacturingErp\DemoScenario;
use ManufacturingErp\FiscalClose;
use ManufacturingErp\Identity;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Subcontracting;
use ManufacturingErp\SubcontractMaterials;
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

    private function request(string $route, ?array $data, string $csrf, bool $json): array
    {
        $headers = ['Accept: ' . ($json ? 'application/json' : 'text/html'), 'Connection: close'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($csrf !== '') {
            $headers[] = 'X-CSRF-Token: ' . $csrf;
        }
        $stream = stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST', 'header' => $headers, 'content' => $data === null ? '' : Value::json($data),
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
$output = $master->addItem('QA-COATED', 'Coated blank', 'ea', 'subcontract', 200, 0);
$day = $fixture['as_of'];
(new ProductEngineering($db, $scope))->publishBom($output, $day, [['item' => $input, 'quantity' => 1000, 'uom' => 'ea']]);
$vendorLot = (new Inventory($db, $scope))->openingBalance('http-vendor-stock', $input, $raw, 'HTTP-VENDOR-STOCK', 'ea', 3000, 100, $day)['lot_id'];
$works = new WorkOrders($db, $scope);
$vendorWork = $works->create('http-vendor-work', 'WO-COATING', $output, 2000, $day, $day, $day)['work_id'];
$works->approve($vendorWork);
$vendor = new Subcontracting($db, $scope);
$contract = $vendor->create('http-vendor-order', 'OUT-COATING', $vendorWork, $supplier, $day, 100)['subcontract_id'];
$works->release($vendorWork);
$material = $works->details($vendorWork)['materials'][0]['id'];
$dispatch = (new SubcontractMaterials($db, $scope))->send('http-vendor-send', $contract, $material, $vendorLot, $raw, 2000, $day)['dispatch_id'];
$returned = $vendor->receive('http-vendor-return', $contract, $raw, 'OUT-COATED', 2000, $day, [['dispatch_id' => $dispatch, 'quantity' => 2000]]);
$vendor->matchFee('http-vendor-fee', $returned['fee_id'], 'VENDOR-COATING-01', 175, $day, 'Quoted processing rebate settled.');

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
$closed = (new FiscalClose($db, $scope))->close($day);
same($closed['digest'], $client->get('/?page=finance&period=' . $day)['body']['details']['快照摘要'], 'Financial workspace renders the persisted snapshot digest');

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
same(403, $client->post('switch-org', ['org_id' => $external['org']], $csrf)['status'], 'Organization selection does not allow unrelated access');

$warehouse = new WebTestClient($base);
$whStart = $warehouse->get('/?page=session')['body']['csrf'];
$whSignIn = $warehouse->post('login', ['email' => $warehouseEmail, 'password' => $password], $whStart);
same($fixture['org_id'], $whSignIn['body']['organization_id'], 'Worker sees its assigned ledger');
same(403, $warehouse->post('calculate-plan', $posted, $whSignIn['body']['csrf'])['status'], 'Warehouse role cannot enqueue an MRP calculation');
same(403, $warehouse->get('/?page=jobs&id=' . $jobId)['status'], 'Warehouse role cannot read MRP task output');
same(403, $warehouse->get('/?page=payables&id=' . $stock['payable_id'])['status'], 'Warehouse role cannot read an AP document');
same(403, $warehouse->get('/?page=export&kind=journals', false)['status'], 'Warehouse role cannot export journal entries');
same(true, str_contains($warehouse->get('/', false)['body'], '&lt;Warehouse operator&gt;'), 'Workspace escapes operator names');

$other = new WebTestClient($base);
$otherToken = $other->get('/?page=session')['body']['csrf'];
$other->post('login', ['email' => $foreignEmail, 'password' => $password], $otherToken);
same(404, $other->get('/?page=jobs&id=' . $jobId)['status'], 'Unrelated organization cannot query a task by its ID');
same(404, $other->get($receipt)['status'], 'An unrelated organization cannot query a receipt');
same(404, $other->get('/?page=export&kind=lot-trace&id=' . $stock['substitute_lot_id'], false)['status'], 'Cross-tenant CSV request is refused');
same(404, $other->get('/?page=vendors&id=' . $contract)['status'], 'Cross-tenant vendor order lookup is refused');
same(404, $other->get('/?page=journals&id=' . $journal)['status'], 'Cross-tenant journal lookup is refused');
$logout = $client->post('logout', [], $csrf);
same(false, $logout['body']['authenticated'], 'Logout invalidates current identity');
same(401, $client->get('/?page=sales')['status'], 'After logout protected content is inaccessible');
finish('HTTP session, workbench, asynchronous receipts and CSV isolation');
