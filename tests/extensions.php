<?php

declare(strict_types=1);

use ManufacturingErp\Completion;
use ManufacturingErp\Database;
use ManufacturingErp\Identity;
use ManufacturingErp\Inspection;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\OperationExecution;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Scheduling;
use ManufacturingErp\WorkCosts;
use ManufacturingErp\WorkMaterials;
use ManufacturingErp\WorkOrders;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$group = $identity->initialize('Extensions group', 'ASMB', 'Assembly plant', 'CNY', 'Test administrator',
    'extensions-' . bin2hex(random_bytes(5)) . '@example.test', 'Dedicated fixture password');
$scope = $identity->scope($group['user'], $group['org']);
$day = '2038-06-01';
$master = new MasterData($db, $scope);
$engineering = new ProductEngineering($db, $scope);
$schedule = new Scheduling($db, $scope);
$works = new WorkOrders($db, $scope);
$materials = new WorkMaterials($db, $scope);
$completion = new Completion($db, $scope);
$inspection = new Inspection($db, $scope);
$location = $master->addLocation('MAIN', 'Main stock');
$department = $master->createDepartment('ASSEMBLY', 'Assembly');
$center = $engineering->addCenter($department, 'TOOL', 'Tooling');
$machine = $schedule->addMachine($center, 'FIXTURE', 'Tooling fixture');
$schedule->addShift($center, $day . ' 08:00:00', $day . ' 16:00:00');
$material = $master->addItem('INPUT', 'Input', 'ea', 'purchase', 100, 0);
$product = $master->addItem('PRODUCT', 'Product', 'ea', 'manufacture', 100, 0);
$engineering->publishBom($product, $day, [['item' => $material, 'quantity' => 1000, 'uom' => 'ea']]);
$engineering->publishRoute($product, $day, [['center' => $center, 'label' => 'Build', 'setup_minutes' => 0, 'run_seconds_per_unit' => 60]]);
$lot = (new Inventory($db, $scope))->openingBalance('extension-stock', $material, $location, 'RAW', 'ea', 2000, 100, $day)['lot_id'];
$example = dirname(__DIR__) . '/examples/extensions.php';
$inactive = __DIR__ . '/fixtures/extensions-inactive.php';

putenv('ERP_EXTENSIONS_FILE=' . $example);
$work = $works->create('extension-create', 'WO-EXT', $product, 2000, $day, $day, $day)['work_id'];
$works->approve($work);
$details = $works->details($work);
same('setup-weighted-v1', $details['cost_policy_version'], 'The work order freezes the selected cost policy');
$operation = (int) $details['operations'][0]['id'];
$schedule->book('extension-book', $operation, $machine, $day . ' 08:00:00', $day . ' 09:00:00');
$works->release($work);
(new OperationExecution($db, $scope))->report('extension-actual', $operation, $day . ' 08:00:00', $day . ' 08:20:00', 900, 300);
$issue = $materials->issue('extension-issue', (int) $details['materials'][0]['id'], $lot, $location, 2000, $day);
$proof = [['issue_id' => $issue['issue_id'], 'quantity' => 1000]];
$first = $completion->report('extension-first', $work, $location, 'FIRST', 1000, $day, $proof);
same(400, $first['actual_minor'], 'Material 100 and 75 percent of 400 in support costs are assigned to the first lot');
same('certificate-reference-v1', $inspection->details($first['inspection_id'])['rule_version'], 'Work output freezes the active inspection rule');
rejects(422, fn() => $inspection->decide('extension-qc', $first['inspection_id'], 1, 1000, 0, 0, $day, 'Missing certificate'),
    'A quality extension can reject a proposed release');
same(1000, (int) $inspection->details($first['inspection_id'])['pending_milli'], 'Rejected release leaves stock pending');

putenv('ERP_EXTENSIONS_FILE');
rejects(503, fn() => $completion->report('extension-second', $work, $location, 'SECOND', 1000, $day, $proof),
    'An unfinished work order refuses to switch to a different policy if its extension is unavailable');
rejects(503, fn() => $inspection->decide('extension-qc', $first['inspection_id'], 1, 1000, 0, 0, $day, 'CERT QA-100'),
    'An unfinished inspection requires its frozen extension version');
putenv('ERP_EXTENSIONS_FILE=' . $inactive);
putenv('ERP_TEST_BAD_COST=1');
rejects(503, fn() => $completion->report('extension-second', $work, $location, 'SECOND', 1000, $day, $proof),
    'A bad allocation rule cannot reverse or omit expenses');
putenv('ERP_TEST_BAD_COST');
same(1000, (int) $works->details($work)['produced_milli'], 'Missing and invalid policies do not advance completed quantity');
same(300, (int) $works->details($work)['support_applied_minor'], 'Invalid allocations do not change cumulative support costs');

$second = $completion->report('extension-second', $work, $location, 'SECOND', 1000, $day, $proof);
same(200, $second['actual_minor'], 'The existing order uses the original policy while a new inspection uses the default');
same(null, $inspection->details($second['inspection_id'])['rule_version'], 'The default inspection rule requires no custom snapshot');
same(0, (new WorkCosts($db, $scope))->report($work)['wip_minor'], 'Both completions distribute all material and support costs');
$replayed = $completion->report('extension-second', $work, $location, 'SECOND', 1000, $day, $proof);
ksort($second);
ksort($replayed);
same($second, $replayed, 'Completion replay returns its existing ledger receipt fields');
$release = $inspection->decide('extension-qc', $first['inspection_id'], 1, 1000, 0, 0, $day, 'CERT QA-100');
same(2, $release['version'], 'A restored historical extension allows the original lot to be released');
same(2, $inspection->decide('extension-default-qc', $second['inspection_id'], 1, 1000, 0, 0, $day, 'Bench check passed')['version'],
    'The built-in inspection flow still works for new cases');
putenv('ERP_EXTENSIONS_FILE');
finish('Versioned inspection rules and cumulative work cost allocation');
