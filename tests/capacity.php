<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\Identity;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\Planning;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Purchasing;
use ManufacturingErp\Sales;
use ManufacturingErp\Value;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$unique = bin2hex(random_bytes(5));
$login = $identity->initialize('MRP capacity ' . $unique, 'FACTORY', 'Capacity factory', 'CNY', 'Capacity tester',
    'capacity-' . $unique . '@example.test', 'Dedicated test account!');
$scope = $identity->scope($login['user'], $login['org']);
$master = new MasterData($db, $scope);
$engineering = new ProductEngineering($db, $scope);
$purchase = new Purchasing($db, $scope);
$sales = new Sales($db, $scope);
$planning = new Planning($db, $scope);
$start = '2041-09-01';
$end = '2041-09-30';
$minimumDue = new DateTimeImmutable('2041-09-12');
$site = $master->addLocation('BENCH', 'Planning warehouse');
$customer = $master->addParty('DEMO-CUSTOMER', 'Synthetic customer', 'customer');
$supplier = $master->addParty('DEMO-SUPPLIER', 'Synthetic supplier', 'supplier');
$top = $master->addItem('TOP', 'Finished product', 'ea', 'manufacture', 9000, 1);
$upper = $master->addItem('UPPER', 'Assembly', 'ea', 'manufacture', 3500, 1);
$lower = $master->addItem('LOWER', 'Subassembly', 'ea', 'manufacture', 1800, 1);
$raw = $master->addItem('RAW', 'Raw component', 'ea', 'purchase', 875, 1);
foreach ([[$top, $upper, 2000], [$upper, $lower, 3000], [$lower, $raw, 2000]] as [$parent, $child, $qty]) {
    $engineering->publishBom($parent, $start, [['item' => $child, 'uom' => 'ea', 'quantity' => $qty]]);
}
(new Inventory($db, $scope))->openingBalance('cap-stock', $raw, $site, 'BENCH-3000', 'ea', 3_000_000, 875, $start);
$incoming = $purchase->create('cap-incoming', 'CAP-ON-TIME', $supplier, $start, [
    ['item' => $raw, 'uom' => 'ea', 'quantity' => 1_200_000, 'unit_price_minor' => 875, 'due_on' => '2041-09-05'],
]);
$purchase->issue($incoming['order_id']);
for ($order = 1; $order <= 20; ++$order) {
    $lines = [];
    for ($line = 1; $line <= 50; ++$line) {
        $due = $minimumDue->modify('+' . (($order + $line) % 10) . ' days')->format('Y-m-d');
        $lines[] = ['item' => $top, 'quantity' => 1000, 'unit_price_minor' => 12000,
            'deliveries' => [['due_on' => $due, 'quantity' => 1000]]];
    }
    $code = 'BENCH-SO-' . $order;
    $sales->freeze($sales->create($code, $code, $customer, $lines)['order_id']);
}

$sample = [];
$leafAnswer = ['gross_milli' => 12_000_000, 'stock_milli' => 3_000_000, 'incoming_milli' => 1_200_000, 'shortage_milli' => 7_800_000];
for ($n = 0; $n <= 3; ++$n) {
    $clock = hrtime(true);
    $run = $planning->calculate('cap-run-' . $n, $start, $end, Planning::POLICY);
    $wall = round((hrtime(true) - $clock) / 1_000_000, 3);
    $stats = $run['stats'];
    same(1000, $stats['roots'], '1,000 distinct released demand roots');
    same(4000, $stats['requirements'], 'Three BOM levels produce four rows per sales root');
    same(350, $stats['pegs'], 'Stock and confirmed purchase quantities are each assigned once');
    same(3650, $stats['proposals'], 'Net requirements yield the expected work and purchase proposals');
    same(0, $stats['changes'], 'Repeated calculations with identical business input have no changed results');
    same(true, $wall <= 120_000, 'Full plan transaction meets the 120 second target');
    $leaf = $db->row('SELECT SUM(gross_milli) AS gross_milli,SUM(stock_milli) AS stock_milli,
        SUM(incoming_milli) AS incoming_milli,SUM(shortage_milli) AS shortage_milli
        FROM mrp_requirements WHERE org_id=? AND run_id=? AND item_id=?', [$scope->org, $run['run_id'], $raw]);
    $measured = array_map('intval', $leaf);
    same($leafAnswer, $measured, 'Leaf quantities agree with independent fixture arithmetic');
    $shortValue = Value::extendedPrice($measured['shortage_milli'], 875);
    same(6_825_000, $shortValue, 'Shortage quantity at the fixed reference cost has zero variance');
    $sample[] = [
        'phase' => $n === 0 ? 'warmup' : 'measured', 'run_id' => $run['run_id'], 'wall_ms' => $wall,
        'stats' => $stats, 'leaf_milli' => $measured, 'shortage_at_reference_cost_minor' => $shortValue,
    ];
}

echo Value::json([
    'measured_at_utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
    'mysql' => $db->row('SELECT VERSION() AS version,@@max_connections AS max_connections,
        @@innodb_buffer_pool_size AS innodb_buffer_pool_bytes,@@sort_buffer_size AS sort_buffer_bytes'),
    'php_memory_limit' => ini_get('memory_limit'), 'php_peak_memory_bytes' => memory_get_peak_usage(true),
    'workload' => ['organizations' => 1, 'workers' => 1, 'sales_documents' => 20, 'demand_roots' => 1000, 'bom_levels' => 3,
        'minimum_due_on' => $minimumDue->format('Y-m-d'), 'stock_lots' => 1, 'confirmed_incoming' => 1, 'timeout_ms' => 120_000],
    'anomalies' => 0, 'samples' => $sample,
]) . "\n";
finish('Material planning capacity');
