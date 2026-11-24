<?php

declare(strict_types=1);

use ManufacturingErp\BatchWorker;
use ManufacturingErp\Database;
use ManufacturingErp\DemoScenario;
use ManufacturingErp\Identity;
use ManufacturingErp\MasterData;
use ManufacturingErp\Value;

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
(new MasterData($db, $scope))->addMember('<Warehouse operator>', $warehouseEmail, $password, 'warehouse');

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
same(true, str_contains($warehouse->get('/', false)['body'], '&lt;Warehouse operator&gt;'), 'Workspace escapes operator names');

$other = new WebTestClient($base);
$otherToken = $other->get('/?page=session')['body']['csrf'];
$other->post('login', ['email' => $foreignEmail, 'password' => $password], $otherToken);
same(404, $other->get('/?page=jobs&id=' . $jobId)['status'], 'Unrelated organization cannot query a task by its ID');
same(404, $other->get($receipt)['status'], 'An unrelated organization cannot query a receipt');
same(404, $other->get('/?page=export&kind=lot-trace&id=' . $stock['substitute_lot_id'], false)['status'], 'Cross-tenant CSV request is refused');
$logout = $client->post('logout', [], $csrf);
same(false, $logout['body']['authenticated'], 'Logout invalidates current identity');
same(401, $client->get('/?page=sales')['status'], 'After logout protected content is inaccessible');
finish('HTTP session, workbench, asynchronous receipts and CSV isolation');
