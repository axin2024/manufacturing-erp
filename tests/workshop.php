<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\Identity;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\Planning;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Sales;
use ManufacturingErp\Scheduling;
use ManufacturingErp\WorkMaterials;
use ManufacturingErp\WorkOrders;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$instance = bin2hex(random_bytes(5));
$login = $identity->initialize('Workshop Group ' . $instance, 'PLANT', 'Assembly Plant', 'CNY', 'Test Administrator',
    'work-' . $instance . '@example.test', 'Unpublished fixture pass!');
$scope = $identity->scope($login['user'], $login['org']);
$master = new MasterData($db, $scope);
$engineering = new ProductEngineering($db, $scope);
$scheduling = new Scheduling($db, $scope);
$inventory = new Inventory($db, $scope);
$sales = new Sales($db, $scope);
$planning = new Planning($db, $scope);
$work = new WorkOrders($db, $scope);
$materials = new WorkMaterials($db, $scope);
$day = '2031-04-01';
$starts = '2031-04-02';
$due = '2031-04-04';

$department = $master->createDepartment('ASSEMBLY', 'Assembly department');
$center = $engineering->addCenter($department, 'WC-ASSEMBLY', 'Bench assembly');
$machine = $scheduling->addMachine($center, 'BENCH-01', 'First assembly bench');
$scheduling->addShift($center, $starts . ' 08:00:00', $starts . ' 16:00:00');
rejects(409, fn() => $scheduling->addShift($center, $starts . ' 15:00:00', $starts . ' 17:00:00'), 'Overlapping shift rejected');
$stockroom = $master->addLocation('STORES', 'Stores');
$workFloor = $master->addLocation('FLOOR', 'Return rack');
$customer = $master->addParty('DISTRIBUTOR', 'Equipment distributor', 'customer');
$product = $master->addItem('PRESS', 'Bench press', 'ea', 'manufacture', 3500, 2);
$pins = $master->addItem('PINS', 'Fastening pins', 'ea', 'purchase', 1200, 0);
$alternative = $master->addItem('PINS-PLUS', 'Reinforced fastening pins', 'ea', 'purchase', 1400, 0);
$wrongItem = $master->addItem('BOLTS', 'Bolts', 'ea', 'purchase', 1400, 0);
$originalBom = $engineering->publishBom($product, $day,
    [['item' => $pins, 'quantity' => 2000, 'uom' => 'ea', 'alternatives' => [$alternative]]]);
$originalRoute = $engineering->publishRoute($product, $day,
    [['center' => $center, 'label' => 'Assembly', 'setup_minutes' => 10, 'run_seconds_per_unit' => 6]]);
$primaryLot = $inventory->openingBalance('pins', $pins, $stockroom, 'ORIGINAL', 'ea', 200_000, 1200, $day)['lot_id'];
$altLot = $inventory->openingBalance('alt', $alternative, $stockroom, 'REINFORCED', 'ea', 130_000, 1400, $day)['lot_id'];

$so = $sales->create('sales-work', 'SO-PRESS', $customer, [['item' => $product, 'quantity' => 100_000, 'unit_price_minor' => 5600,
    'deliveries' => [['due_on' => $due, 'quantity' => 100_000]]]]);
$sales->freeze($so['order_id']);
$run = $planning->calculate('plan-work', $day, $due, Planning::POLICY)['run_id'];
$plan = $planning->run($run);
$proposal = (int) current(array_filter($plan['requirements'], static fn (array $r): bool => $r['kind'] === 'manufacture'))['proposal_id'];
$planning->approve($run);
$workId = $work->create('press-one', 'WO-PRESS-A', $product, 100_000, $day, $starts, $due, $proposal)['work_id'];
rejects(409, fn() => $work->release($workId), 'Work cannot be released before approval');
$work->approve($workId);
rejects(409, fn() => $work->release($workId), 'Work cannot be released without reserved capacity');
$snapshot = $work->details($workId);
$op = (int) $snapshot['operations'][0]['id'];
$material = (int) $snapshot['materials'][0]['id'];
same(1200, (int) $snapshot['operations'][0]['standard_seconds'], 'Frozen standard seconds are setup plus run for 100 units');
$engineering->publishBom($product, $starts, [['item' => $pins, 'quantity' => 1000, 'uom' => 'ea', 'alternatives' => [$alternative]]]);
$engineering->publishRoute($product, $starts,
    [['center' => $center, 'label' => 'Updated assembly', 'setup_minutes' => 20, 'run_seconds_per_unit' => 8]]);
same($originalBom, (int) $work->details($workId)['bom_id'], 'Future BOM preserves the work snapshot');
same($originalRoute, (int) $work->details($workId)['route_id'], 'Future routing preserves the work snapshot');
same(200_000, (int) $work->details($workId)['materials'][0]['plan_milli'], 'Future engineering does not change the frozen materials');

$second = $work->create('press-two', 'WO-PRESS-B', $product, 50_000, $day, $starts, $due)['work_id'];
$work->approve($second);
$op2 = (int) $work->details($second)['operations'][0]['id'];
rejects(409, fn() => $scheduling->book('outside', $op, $machine, $starts . ' 05:00:00', $starts . ' 06:00:00'), 'Work must fit a shift');
rejects(409, fn() => $scheduling->book('short', $op, $machine, $starts . ' 08:00:00', $starts . ' 08:10:00'), 'Work must fit its standard time');
$slot = $scheduling->book('first-slot', $op, $machine, $starts . ' 08:00:00', $starts . ' 09:00:00');
same($slot['slot_id'], $scheduling->book('first-slot', $op, $machine, $starts . ' 08:00:00', $starts . ' 09:00:00')['slot_id'],
    'Booking retry does not add a duplicate slot');
rejects(409, fn() => $scheduling->book('conflict', $op2, $machine, $starts . ' 08:30:00', $starts . ' 09:30:00'),
    'AC-11 same machine and overlapping slot are refused', 'WO-PRESS-A');
$scheduling->book('second-slot', $op2, $machine, $starts . ' 09:00:00', $starts . ' 10:00:00');
same(2, count($scheduling->calendar($starts . ' 08:00:00', $starts . ' 12:00:00')), 'A back-to-back slot is available');
$work->release($workId);
$work->release($second);

rejects(409, fn() => $materials->issue('no-alt-approval', $material, $altLot, $stockroom, 10_000, $starts), 'Alternative requires formal approval');
rejects(409, fn() => $materials->authorize('wrong-item', $material, 'substitute', $wrongItem, 10_000, 'Wrong item'),
    'Work uses the approved BOM alternatives only');
$replacement = $materials->authorize('alt-ok', $material, 'substitute', $alternative, 10_000, 'Customer drawing permits reinforced pins')['authorization_id'];
$mainIssue = $materials->issue('main-issue', $material, $primaryLot, $stockroom, 190_000, $starts);
$subIssue = $materials->issue('alt-issue', $material, $altLot, $stockroom, 10_000, $starts, $replacement);
same(2000, $subIssue['variance_minor'], 'Integrated case measures CNY 20 substitute variance on the issue');
same($subIssue['cost_id'], $materials->issue('alt-issue', $material, $altLot, $stockroom, 10_000, $starts, $replacement)['cost_id'],
    'Retry preserves the cost entry and authorization usage');
rejects(409, fn() => $materials->issue('reused-approval', $material, $altLot, $stockroom, 1000, $starts, $replacement),
    'Replacement approval is bounded by approved quantity');
rejects(409, fn() => $materials->issue('extra-pins', $material, $primaryLot, $stockroom, 1000, $starts), 'AC-12 extra issue is refused without supplemental approval');
$extra = $materials->authorize('extra-ok', $material, 'overage', $pins, 1000, 'Trial piece approved')['authorization_id'];
$additional = $materials->issue('extra-pins', $material, $primaryLot, $stockroom, 1000, $starts, null, $extra);
same(1000, $additional['qty_milli'], 'Supplemental approval permits exactly the requested excess');
rejects(409, fn() => $materials->issue('one-more', $material, $primaryLot, $stockroom, 1000, $starts, null, $extra),
    'Supplemental approval cannot be spent twice');
$materials->returnToStock('return-unused', $additional['issue_id'], $workFloor, 1000, $starts);
$frozenMaterial = $work->details($workId)['materials'][0];
same(200_000, (int) $frozenMaterial['issued_milli'] - (int) $frozenMaterial['returned_milli'], 'Net issued quantity accounts for the return');
same(1200, $mainIssue['actual_minor'] / 190, 'Primary issue uses the lot cost');
$parts = $materials->flows($workId);
same(2000, array_sum(array_column($parts['issues'], 'variance_minor')), 'Variance can be read with both the lot and the issue');

$material2 = (int) $work->details($second)['materials'][0]['id'];
$replaceAll = $materials->authorize('alt-all', $material2, 'substitute', $alternative, 100_000, 'Qualified substitute for the whole run')['authorization_id'];
$standalone = $materials->issue('standalone', $material2, $altLot, $stockroom, 100_000, $starts, $replaceAll);
same(20_000, $standalone['variance_minor'], 'AC-15 standalone substitute variance is CNY 200 and traces to an issue');

$work->pause($workId, 'Awaiting revised customer instruction');
rejects(409, fn() => $materials->issue('paused', $material, $primaryLot, $stockroom, 1000, $starts), 'Paused work cannot consume fresh stock');
same('active', $work->resume($workId)['state'], 'Resuming a started order restores its active state');
$dispositions = $sales->cancelDemand($so['demand_ids'][0], 'Stop the customer delivery');
same('work', $dispositions[0]['source_kind'], 'AC-04 started work requires a disposition');
same($workId, (int) $dispositions[0]['source_id'], 'AC-04 disposition points to the real work order');
same('active', $work->details($workId)['state'], 'AC-04 cancellation preserves manufacturing facts');
same(3, count($materials->flows($workId)['issues']), 'AC-04 material issues remain auditable');

finish('Finite capacity, shop order and material issues');
