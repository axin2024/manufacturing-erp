<?php

declare(strict_types=1);

use ManufacturingErp\Completion;
use ManufacturingErp\Database;
use ManufacturingErp\Exports;
use ManufacturingErp\FiscalClose;
use ManufacturingErp\GeneralLedger;
use ManufacturingErp\Genealogy;
use ManufacturingErp\Identity;
use ManufacturingErp\Inspection;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\OperationExecution;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Sales;
use ManufacturingErp\Scheduling;
use ManufacturingErp\Shipping;
use ManufacturingErp\WorkCosts;
use ManufacturingErp\WorkClosing;
use ManufacturingErp\WorkMaterials;
use ManufacturingErp\WorkOrders;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$instance = bin2hex(random_bytes(5));
$login = $identity->initialize('Delivery Group ' . $instance, 'PLANT', 'Assembly Plant', 'CNY', 'Test Administrator',
    'delivery-' . $instance . '@example.test', 'Unpublished fixture pass!');
$scope = $identity->scope($login['user'], $login['org']);
$master = new MasterData($db, $scope);
$engineering = new ProductEngineering($db, $scope);
$scheduling = new Scheduling($db, $scope);
$inventory = new Inventory($db, $scope);
$sales = new Sales($db, $scope);
$work = new WorkOrders($db, $scope);
$issues = new WorkMaterials($db, $scope);
$completion = new Completion($db, $scope);
$execution = new OperationExecution($db, $scope);
$quality = new Inspection($db, $scope);
$shipping = new Shipping($db, $scope);
$genealogy = new Genealogy($db, $scope);
$costs = new WorkCosts($db, $scope);
$closing = new WorkClosing($db, $scope);

$day = '2032-04-01';
$next = '2032-04-02';
$later = '2032-04-03';
$customer = $master->addParty('CUSTOMER', 'Fleet Tools', 'customer');
$other = $master->addParty('OTHER-CUST', 'Other Customer', 'customer');
$materials = $master->addLocation('RAW', 'Raw items');
$fg = $master->addLocation('FG', 'Finished stock and testing');
$department = $master->createDepartment('ASSEMBLY', 'Assembly department');
$center = $engineering->addCenter($department, 'ASSEMBLY-CENTER', 'Bench assembly');
$machine = $scheduling->addMachine($center, 'MACHINE', 'Assembly bench');
$scheduling->addShift($center, $day . ' 08:00:00', $day . ' 18:00:00');
$scheduling->addShift($center, $next . ' 08:00:00', $next . ' 18:00:00');
$product = $master->addItem('DEVICE', 'Device', 'ea', 'manufacture', 2400, 0);
$item = $master->addItem('PIN', 'Pin', 'ea', 'purchase', 1200, 0);
$replacement = $master->addItem('PIN-ALT', 'Reinforced pin', 'ea', 'purchase', 1400, 0);
$engineering->publishBom($product, $day, [['item' => $item, 'quantity' => 2000, 'uom' => 'ea', 'alternatives' => [$replacement]]]);
$engineering->publishRoute($product, $day, [['center' => $center, 'label' => 'Assembly', 'setup_minutes' => 10, 'run_seconds_per_unit' => 6]]);
$lot1 = $inventory->openingBalance('open-main', $item, $materials, 'MAIN-INPUT', 'ea', 200_000, 1200, $day)['lot_id'];
$lot2 = $inventory->openingBalance('open-repl', $replacement, $materials, 'REPLACEMENT-INPUT', 'ea', 10_000, 1400, $day)['lot_id'];
$sale = $sales->create('sale-100', 'SO-DEVICE', $customer, [['item' => $product, 'quantity' => 100_000,
    'unit_price_minor' => 7500, 'deliveries' => [
        ['quantity' => 98_000, 'due_on' => $next],
        ['quantity' => 2000, 'due_on' => $later],
    ],
]]);
$sales->freeze($sale['order_id']);
$demand = $sale['demand_ids'][0];
$secondDemand = $sale['demand_ids'][1];

$order = $work->create('order-100', 'WORK-100', $product, 100_000, $day, $day, $later)['work_id'];
$work->approve($order);
$orderDetails = $work->details($order);
$mat = (int) $orderDetails['materials'][0]['id'];
$step = (int) $orderDetails['operations'][0]['id'];
$scheduling->book('slot-work', $step, $machine, $day . ' 08:00:00', $day . ' 09:00:00');
$work->release($order);
$sub = $issues->authorize('sub-10', $mat, 'substitute', $replacement, 10_000, 'Qualified substitute for ten pieces')['authorization_id'];
$a = $issues->issue('issue-190', $mat, $lot1, $materials, 190_000, $day);
$b = $issues->issue('issue-10', $mat, $lot2, $materials, 10_000, $day, $sub);
$overage = $issues->authorize('extra', $mat, 'overage', $item, 1000, 'Spare input')['authorization_id'];
$spare = $issues->issue('issue-spare', $mat, $lot1, $materials, 1000, $day, null, $overage);
$inputProof = [['issue_id' => $a['issue_id'], 'quantity' => 190_000], ['issue_id' => $b['issue_id'], 'quantity' => 10_000]];
rejects(409, fn() => $completion->report('fin-100', $order, $fg, 'DEVICE-100', 100_000, $day, $inputProof),
    'Completion awaits confirmed actual operations');
$real = $execution->report('actual', $step, $day . ' 08:00:00', $day . ' 08:25:00', 3600, 2400);
same(1200, $real['standard_seconds'], 'Standard time stays frozen');
same(1500, $real['actual_seconds'], 'Actual time is recorded independently');
rejects(409, fn() => $completion->report('fin-100', $order, $fg, 'DEVICE-100', 100_000, $day, [$inputProof[0]]),
    'Incomplete material allocations are refused');
$output = $completion->report('fin-100', $order, $fg, 'DEVICE-100', 100_000, $day, $inputProof);
same('waiting_quality', $output['work_state'], 'All completed units move to inspection');
same(244_500, $output['actual_minor'], 'Finished cost includes both lots, labor and overhead');
same(240_000, $output['standard_minor'], 'Manufacturing standard stays frozen');
same($output['output_id'], $completion->report('fin-100', $order, $fg, 'DEVICE-100', 100_000, $day, array_reverse($inputProof))['output_id'],
    'Identical completion input confirmations replay deterministically');
same(1200, $costs->report($order)['wip_minor'], 'Unassigned input cost stays in WIP');
$blocked = $closing->close($order, $day);
same(['pending_inspection', 'unreturned_material', 'open_wip'], array_column($blocked['blockers'], 'kind'),
    'Work close gives separate actionable reasons for pending inspection, unused material and WIP');
$issues->returnToStock('return-spare', $spare['issue_id'], $materials, 1000, $day);
same(0, $costs->report($order)['wip_minor'], 'Returning the unused input clears WIP');
$byCategory = array_column($costs->report($order)['summary'], 'variance_minor', 'element');
same(2000, (int) $byCategory['material'], 'CNY 20 in material differences can be reconciled independently');
$entries = $costs->report($order)['entries'];
$source = current(array_filter($entries, static fn(array $entry): bool => $entry['id'] === $b['cost_id']));
same('REPLACEMENT-INPUT', $source['lot_code'], 'Cost report identifies the replacement lot');
same($b['issue_id'], (int) $source['issue_id'], 'Cost report identifies the original issue');

$finishedLot = $output['lot_id'];
rejects(409, fn() => $shipping->reserve('pending', $demand, $finishedLot, $fg, 1000),
    'Pending stock cannot be reserved to a customer');
$pass = $quality->decide('qc-98', $output['inspection_id'], 1, 98_000, 0, 2000, $next, 'Pass 98, reseat pins on two devices');
same('completed', $pass['state'], 'The initial inspection has a result for all 100 units');
$child = $pass['rework_order_id'];
rejects(409, fn() => $closing->close($order, $day), 'Cannot close before the inspection date');
same('closed', $closing->close($order, $next)['state'], 'The parent can close after QC and return, while the child tracks its own remaining work');
same($order, (int) $work->details($child)['parent_work_id'], 'Rework is an independent child of the production order');
same('rework', $work->details($child)['kind'], 'Two units have their own rework route');
same($child, $quality->decide('qc-98', $output['inspection_id'], 1, 98_000, 0, 2000, $next, 'Pass 98, reseat pins on two devices')['rework_order_id'],
    'Repeating a quality decision does not create a second rework order');
rejects(409, fn() => $shipping->reserve('reserve-hold', $secondDemand, $finishedLot, $fg, 2000 + 1), 'Sales split cannot be over-allocated');
rejects(409, fn() => $inventory->move('detach-rework', $finishedLot, $fg, $materials, 'rework', 1000, $next),
    'Rework lot cannot leave its controlled bin outside the rework order');
$held = $shipping->reserve('reserve-98', $demand, $finishedLot, $fg, 98_000)['reservation_id'];
rejects(409, fn() => $shipping->reserve('try-rework', $secondDemand, $finishedLot, $fg, 1000), 'AC-13 rework units are not deliverable');
rejects(409, fn() => $inventory->move('steal-held', $finishedLot, $fg, $materials, 'available', 1000, $next),
    'Inventory cannot move stock committed to the shipment');
$dispatch = [['reservation_id' => $held, 'quantity' => 98_000]];
rejects(409, fn() => $shipping->dispatch('ship-wrong', 'SHP-WRONG', $other, $next, $dispatch), 'A different customer cannot take the reservation');
$shipment = $shipping->dispatch('ship-98', 'SHP-98', $customer, $next, $dispatch);
same($shipment['shipment_id'], $shipping->dispatch('ship-98', 'SHP-98', $customer, $next, $dispatch)['shipment_id'],
    'Shipment retry preserves a single stock movement');
same(98_000, $shipment['lines'][0]['qty_milli'], 'AC-13 first shipment is limited to the 98 released units');
same(0, (int) $sales->order($sale['order_id'])['demands'][1]['dispatched_milli'], 'AC-13 two units remain undelivered');
$recall = $genealogy->trace($lot2, 'downstream');
same([$lot2, $finishedLot], array_column($recall['lots'], 'id'), 'AC-14 contaminated raw lot identifies the precise finished lot');
same('Fleet Tools', $recall['shipments'][0]['customer'], 'AC-14 recall identifies the affected customer');
same($shipment['shipment_id'], (int) $recall['shipments'][0]['shipment_id'], 'AC-14 recall identifies the physical delivery');
$upstream = $genealogy->trace($finishedLot, 'upstream');
same([$lot1, $lot2], array_column($upstream['links'], 'input_lot_id'), 'Shipment can be traced backwards to both actual inputs');

$work->approve($child);
$reworkOp = (int) $work->details($child)['operations'][0]['id'];
$scheduling->book('slot-rework', $reworkOp, $machine, $next . ' 09:00:00', $next . ' 10:00:00');
$work->release($child);
$execution->report('actual-rework', $reworkOp, $next . ' 09:00:00', $next . ' 09:15:00', 0, 0);
$madeAgain = $completion->report('fin-rework', $child, $fg, 'DEVICE-REWORK-2', 2000, $next, []);
same(4890, $madeAgain['actual_minor'], 'Rework carries forward the two units of original lot cost');
$quality->decide('qc-rw', $madeAgain['inspection_id'], 1, 2000, 0, 0, $later, 'Rework accepted');
same('closed', $closing->close($child, $later)['state'], 'The rework order settles its own costs and inspection');
$remaining = $shipping->reserve('reserve-2', $secondDemand, $madeAgain['lot_id'], $fg, 2000)['reservation_id'];
$shipping->dispatch('ship-2', 'SHP-2', $customer, $later, [['reservation_id' => $remaining, 'quantity' => 2000]]);
same('completed', $sales->order($sale['order_id'])['state'], 'Both sales splits are eventually shipped');
$allImpacted = $genealogy->trace($lot2, 'downstream');
same([$lot2, $finishedLot, $madeAgain['lot_id']], array_column($allImpacted['lots'], 'id'), 'Recall follows the rework branch');
same(['SHP-98', 'SHP-2'], array_column($allImpacted['shipments'], 'number'), 'Recall follows both customer shipments');
$trial = array_column((new GeneralLedger($db, $scope))->trial($later), 'net_minor', 'account_code');
same(0, (int) $trial['WIP'], 'Finished stock and rework both clear their original work costs in the journal');
same(244_500, (int) $trial['COGS'], 'Shipping both deliveries recognizes the entire finished lot cost exactly once');
same(12_000, (int) $trial['STOCK'], 'Only the unused part of the original input remains capitalized');
same('closed', (new FiscalClose($db, $scope))->close($day)['state'], 'Manufacturing subledger reconciles during period closure');

$intruder = $identity->initialize('Unrelated Group ' . $instance, 'OTHER', 'Other Plant', 'CNY', 'Other Administrator',
    'other-delivery-' . $instance . '@example.test', 'Test account seed only!');
$foreign = $identity->scope($intruder['user'], $intruder['org']);
rejects(404, fn() => (new Genealogy($db, $foreign))->trace($finishedLot, 'upstream'), 'Tenant isolation applies to genealogy');
rejects(404, fn() => (new Shipping($db, $foreign))->shipment($shipment['shipment_id']), 'Tenant isolation applies to shipment readbacks');

$exports = new Exports($db, $scope);
$outsideExports = new Exports($db, $foreign);
$issuesCsv = csvRecords($exports, 'work-issues', $order);
same('REPLACEMENT-INPUT', array_column($issuesCsv, 'lot_code', 'id')[$b['issue_id']], 'Issue export identifies the alternative batch');
$traceCsv = csvRecords($exports, 'lot-trace', $lot2, 'downstream');
$shippedCsv = array_filter($traceCsv, static fn(array $row): bool => $row['type'] === 'shipment');
same(['SHP-98', 'SHP-2'], array_column($shippedCsv, 'shipment_number'), 'Trace export contains both shipments');
$costCsv = csvRecords($exports, 'work-variance', $order);
$byCost = array_column($costCsv, 'actual_minor', 'source_kind');
same('-1200', $byCost['return'], 'CSV keeps return cost as a signed number');
rejects(404, fn() => $outsideExports->open('work-issues', $order), 'An outside tenant cannot export work issues');
rejects(404, fn() => $outsideExports->open('work-variance', $order), 'An outside tenant cannot export work costs');
rejects(404, fn() => $outsideExports->open('lot-trace', $lot2), 'An outside tenant cannot export genealogy');

finish('Production, operation costs, rework, shipping and lot recall');
