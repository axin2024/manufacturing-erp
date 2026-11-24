<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\Genealogy;
use ManufacturingErp\Identity;
use ManufacturingErp\Inspection;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Subcontracting;
use ManufacturingErp\SubcontractMaterials;
use ManufacturingErp\WorkClosing;
use ManufacturingErp\WorkCosts;
use ManufacturingErp\WorkMaterials;
use ManufacturingErp\WorkOrders;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$instance = bin2hex(random_bytes(5));
$login = $identity->initialize('Outsource Group ' . $instance, 'PLANT', 'Machine Plant', 'CNY', 'Test Administrator',
    'vendor-' . $instance . '@example.test', 'Unpublished fixture pass!');
$scope = $identity->scope($login['user'], $login['org']);
$master = new MasterData($db, $scope);
$inventory = new Inventory($db, $scope);
$engineering = new ProductEngineering($db, $scope);
$work = new WorkOrders($db, $scope);
$issues = new WorkMaterials($db, $scope);
$vendor = new Subcontracting($db, $scope);
$materials = new SubcontractMaterials($db, $scope);
$quality = new Inspection($db, $scope);
$closing = new WorkClosing($db, $scope);
$costs = new WorkCosts($db, $scope);

$day = '2033-04-01';
$next = '2033-04-02';
$last = '2033-04-03';
$warehouse = $master->addLocation('STORES', 'Stores');
$supplier = $master->addParty('TREATMENT', 'Surface treatment shop', 'supplier');
$metal = $master->addItem('METAL', 'Metal blank', 'ea', 'purchase', 1000, 0);
$part = $master->addItem('TREATED', 'Treated part', 'ea', 'subcontract', 2300, 0);
$engineering->publishBom($part, $day, [['item' => $metal, 'quantity' => 2000, 'uom' => 'ea']]);
$input = $inventory->openingBalance('blank-opening', $metal, $warehouse, 'BLANK-25', 'ea', 25_000, 1000, $day)['lot_id'];
$id = $work->create('work-vendor', 'WO-VENDOR', $part, 10_000, $day, $day, $last)['work_id'];
$work->approve($id);
rejects(409, fn() => $work->release($id), 'Contract must exist before release');
$contract = $vendor->create('new-vendor', 'SUBCONTRACT-01', $id, $supplier, $day, 300)['subcontract_id'];
$work->release($id);
$material = (int) $work->details($id)['materials'][0]['id'];
rejects(409, fn() => $materials->send('send-25', $contract, $material, $input, $warehouse, 25_000, $day),
    'AC-12 extra subcontract material is refused before approval');
$approval = $issues->authorize('check-5', $material, 'overage', $metal, 5000, 'Five extra trial pieces approved')['authorization_id'];
$issued = $materials->send('send-25', $contract, $material, $input, $warehouse, 25_000, $day, null, $approval);
$dispatch = $issued['dispatch_id'];
same($dispatch, $materials->send('send-25', $contract, $material, $input, $warehouse, 25_000, $day, null, $approval)['dispatch_id'],
    'Sending retries preserve the dispatch and approval credit');
same([], $inventory->lot($input)['balances'], 'Sent stock leaves all on-site available bins');
same(25_000, (int) $vendor->details($contract)['materials'][0]['outside_milli'], 'Vendor custody is tracked apart from available inventory');
rejects(409, fn() => $vendor->receive('rc-sub', $contract, $warehouse, 'TREATED-10', 10_000, $next,
    [['dispatch_id' => $dispatch, 'quantity' => 19_000]]), 'Receipt cannot silently omit a material used in production');
$output = $vendor->receive('rc-sub', $contract, $warehouse, 'TREATED-10', 10_000, $next,
    [['dispatch_id' => $dispatch, 'quantity' => 20_000]]);
same(23_000, $output['actual_minor'], 'Outside receipt capitalizes consumed material and expected contract fee');
same(3000, $output['expected_fee_minor'], 'Fee follows the receipt quantity');
same(0, $costs->report($id)['wip_minor'], 'Expected fee and consumed material settle into the finished lot');
same(5000, (int) $vendor->details($contract)['materials'][0]['outside_milli'], 'AC-12 five pieces remain at vendor after final output');

$quality->decide('sub-qc', $output['inspection_id'], 1, 10_000, 0, 0, $next, 'All pieces match');
$blockers = $closing->close($id, $next);
same('blocked', $blockers['state'], 'Closing enforces reconciliation even after quality release');
same(['vendor_material_gap', 'unmatched_vendor_fee'], array_column($blockers['blockers'], 'kind'),
    'AC-12 missing material and unmatched fee are reported independently');
same(5000, $blockers['blockers'][0]['qty_milli'], 'AC-12 discrepancy shows exact balance');
same($dispatch, $blockers['blockers'][0]['source_id'], 'AC-12 discrepancy identifies original dispatch');
same(3000, $blockers['blockers'][1]['amount_minor'], 'Unmatched fee has the contract amount');
$trace = (new Genealogy($db, $scope))->trace($input, 'downstream');
same($output['lot_id'], (int) $trace['links'][0]['output_lot_id'], 'Vendor used material is a genealogy input');
same('subcontract', $trace['links'][0]['source_kind'], 'Genealogy records the actual vendor source');

$returned = $materials->returnToStock('return-4', $dispatch, $warehouse, 4000, $last, 'Four unprocessed pieces returned');
same(1000, $returned['outside_milli'], 'Partial recovery preserves the remaining gap');
$loss = $materials->writeOff('loss-1', $dispatch, 1000, $last, 'One destroyed blank accepted as processing loss');
same(1000, $loss['amount_minor'], 'The loss reconciles against the original lot cost');
same(0, $loss['outside_milli'], 'Loss closes the quantity discrepancy');
same(4000, (int) $inventory->lot($input)['balances'][0]['qty_milli'], 'Only physical returns replenish available stock');
same(['unmatched_vendor_fee'], array_column($closing->review($id)['blockers'], 'kind'), 'Only fee matching remains');
$fee = $vendor->matchFee('approve-invoice', $output['fee_id'], 'VENDOR-INVOICE', 2900, $last, 'Negotiated fee reduction');
same(-100, $fee['variance_minor'], 'Confirmed fee difference remains signed');
same(-100, (int) $vendor->details($contract)['fees'][0]['variance_minor'], 'Fee variance can be read from the original invoice');
same(0, $costs->report($id)['wip_minor'], 'Fee difference is expensed and does not reopen consumed WIP');
same('closed', $closing->close($id, $last)['state'], 'Work closes after reconciliations');
same('closed', $closing->close($id, $last)['state'], 'Closing may be retried on the original date');
rejects(409, fn() => $materials->returnToStock('extra-recovery', $dispatch, $warehouse, 1000, $last, 'Duplicate physical return'),
    'No movement may follow closure or exceed the expected balance');

finish('Outside processing, incident reconciliation and work closing');
