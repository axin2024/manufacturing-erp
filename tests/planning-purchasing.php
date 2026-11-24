<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\Identity;
use ManufacturingErp\Inspection;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\Planning;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Purchasing;
use ManufacturingErp\Sales;
use ManufacturingErp\Sourcing;
use ManufacturingErp\Value;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$instance = bin2hex(random_bytes(5));
$group = $identity->initialize('Precision Group ' . $instance, 'FACTORY', 'Plant', 'CNY', 'Fixture Administrator',
    'plant-' . $instance . '@example.test', 'Test account seed only!');
$stranger = $identity->initialize('Unrelated Group ' . $instance, 'OTHER', 'Other Plant', 'CNY', 'Other Administrator',
    'other-' . $instance . '@example.test', 'Test account seed only!');
$scope = $identity->scope($group['user'], $group['org']);
$strangerScope = $identity->scope($stranger['user'], $stranger['org']);
$master = new MasterData($db, $scope);
$inventory = new Inventory($db, $scope);
$engineering = new ProductEngineering($db, $scope);
$sales = new Sales($db, $scope);
$planning = new Planning($db, $scope);
$purchase = new Purchasing($db, $scope);
$sourcing = new Sourcing($db, $scope);
$quality = new Inspection($db, $scope);

$base = '2030-01-10';
$warehouse = $master->addLocation('MAIN', 'Inspection and storage zone');
$customer = $master->addParty('BUYER', 'Equipment customer', 'customer');
$supplier = $master->addParty('PARTS', 'Parts supplier', 'supplier');
$otherSupplier = $master->addParty('SECONDARY', 'Alternate parts supplier', 'supplier');
$housing = $master->addItem('HOUSING', 'Housing', 'ea', 'purchase', 400, 2);
$input = $master->addItem('PIN', 'Fastening pin', 'ea', 'purchase', 1200, 2);
$product = $master->addItem('PRESS', 'Bench press', 'ea', 'manufacture', 3500, 1);

$oldConversion = $master->publishConversion($housing, 'box', '2030-01-01', 10_000);
$box = $inventory->openingBalance('open-boxes', $housing, $warehouse, 'BOX-OLD', 'box', 2_000, 400, $base);
same(20_000, $box['qty_milli'], 'AC-01 opening stock uses the rule effective on its posting date');
$master->publishConversion($housing, 'box', '2030-02-01', 12_000);
$history = $inventory->lot($box['lot_id']);
same($oldConversion, (int) $history['conversion_id'], 'AC-02 opening stock keeps the conversion revision');
same(10_000, (int) $history['factor_milli'], 'AC-02 old lot keeps its factor');
same(20_000, (int) $history['balances'][0]['qty_milli'], 'AC-02 old lot keeps its available stock');
$newBox = $inventory->openingBalance('new-boxes', $housing, $warehouse, 'BOX-NEW', 'box', 2_000, 400, '2030-02-01');
same(24_000, $newBox['qty_milli'], 'New opening stock uses the newer conversion rule');

$trading = $master->createOrganization('TRADING', 'Trading ledger', 'CNY');
$tradingScope = $identity->scope($group['user'], $trading);
$otherMaster = new MasterData($db, $tradingScope);
same($housing, $otherMaster->addItem('HOUSING', 'Housing', 'ea', 'purchase', 500, 5), 'Group shares the item identity');
same([], (new Inventory($db, $tradingScope))->lots(), 'Group ledgers keep separate stock');
rejects(403, fn() => $identity->scope($stranger['user'], $group['org']), 'Outside group cannot select the organization');
rejects(404, fn() => (new Inventory($db, $strangerScope))->lot($box['lot_id']), 'Outside group cannot read the lot by id');

$order = $sales->create('so-press', 'SO-PRESS', $customer, [[
    'item' => $product, 'quantity' => 100_000, 'unit_price_minor' => 100_000, 'deliveries' => [
        ['due_on' => '2030-01-20', 'quantity' => 60_000],
        ['due_on' => '2030-01-25', 'quantity' => 40_000],
    ],
]]);
same($order['demand_ids'], $sales->freeze($order['order_id']), 'Freeze keeps both delivery plans');
same(100_000, array_sum(array_column($sales->order($order['order_id'])['demands'], 'qty_milli')), 'AC-03 gross sales demand');
$engineering->publishBom($product, '2030-01-01', [['item' => $input, 'quantity' => 2_000, 'uom' => 'ea']]);
$bom = $engineering->bom($product, $base);
same(200_000, ProductEngineering::componentRequirement(100_000, $bom['lines'][0]), 'AC-05 BOM gross requirements');
$frame = $master->addItem('FRAME', 'Frame assembly', 'ea', 'manufacture', 1000, 1);
$joint = $master->addItem('JOINT', 'Joint assembly', 'ea', 'manufacture', 600, 1);
$engineering->publishBom($frame, '2030-01-01', [['item' => $joint, 'quantity' => 1_000, 'uom' => 'ea']]);
$engineering->publishBom($joint, '2030-01-01', [['item' => $product, 'quantity' => 1_000, 'uom' => 'ea']]);
rejects(409, fn() => $engineering->publishBom($product, '2030-01-11',
    [['item' => $frame, 'quantity' => 1_000, 'uom' => 'ea']]), 'AC-06 rejects a complete cycle', 'PRESS → FRAME → JOINT → PRESS');

$inventory->openingBalance('open-pins', $input, $warehouse, 'PIN-OPEN', 'ea', 70_000, 1200, $base);
$makePo = static function (string $number, int $quantity, string $due) use ($purchase, $supplier, $input, $base): array {
    return $purchase->create($number, $number, $supplier, $base, [[
        'item' => $input, 'uom' => 'ea', 'quantity' => $quantity, 'unit_price_minor' => 1200, 'due_on' => $due,
    ]]);
};
$onTime = $makePo('PO-ONTIME', 30_000, '2030-01-18');
$purchase->issue($onTime['order_id']);
$late = $makePo('PO-LATE', 500_000, '2030-02-01');
$purchase->issue($late['order_id']);
$makePo('PO-DRAFT', 300_000, '2030-01-15');
$plan = $planning->calculate('plan-one', $base, '2030-02-10', Planning::POLICY);
same($plan['run_id'], $planning->calculate('plan-one', $base, '2030-02-10', Planning::POLICY)['run_id'], 'Plan request retry');
$result = $planning->run($plan['run_id']);
$requirements = array_values(array_filter($result['requirements'], static fn (array $row): bool => (int) $row['item_id'] === $input));
same(200_000, array_sum(array_column($requirements, 'gross_milli')), 'AC-07 sum of gross pin requirements');
same(70_000, array_sum(array_column($requirements, 'stock_milli')), 'AC-07 stock pegging consumes each unit once');
same(30_000, array_sum(array_column($requirements, 'incoming_milli')), 'AC-07 only on-time firm supply enters');
same(100_000, array_sum(array_column($requirements, 'shortage_milli')), 'AC-07 pin shortage');
same(2, count($requirements), 'Separate component need for both deliveries');
$first = current(array_filter($requirements, static fn (array $row): bool => $row['needed_on'] === '2030-01-19'));
$pegs = $planning->pegging($plan['run_id'], $first['ref']);
same([$onTime['line_ids'][0]], array_column(array_filter($pegs, static fn (array $p): bool => $p['source_kind'] === 'purchase'),
    'source_id'), 'AC-08 no late or draft supply pegged');
same(20_000, (int) $first['shortage_milli'], 'The first due date is served first');
rejects(404, fn() => (new Planning($db, $strangerScope))->run($plan['run_id']), 'Outside group cannot view the plan');

$proposal = (int) $first['proposal_id'];
rejects(409, fn() => $sourcing->request('rfq-pins', 'RFQ-PINS', $input, 20_000, '2030-01-19', $proposal), 'Unapproved plan');
$planning->approve($plan['run_id']);
$rfq = $sourcing->request('rfq-pins', 'RFQ-PINS', $input, 20_000, '2030-01-19', $proposal)['request_id'];
$expiredBid = $sourcing->quote('supplier-expired', $rfq, $otherSupplier, 'BID-OLD', 900, '2030-01-17', '2030-01-11')['quote_id'];
$bid = $sourcing->quote('supplier-current', $rfq, $supplier, 'BID-CURRENT', 1200, '2030-01-17', '2030-01-20')['quote_id'];
rejects(409, fn() => $sourcing->award($rfq, $expiredBid, '2030-01-12'), 'Expired quote is refused');
$sourcing->award($rfq, $bid, '2030-01-12');
$linked = $purchase->create('from-plan', 'PO-LINKED', $supplier, '2030-01-12', [[
    'item' => $input, 'uom' => 'ea', 'quantity' => 20_000, 'unit_price_minor' => 1200, 'due_on' => '2030-01-19',
    'proposal_id' => $proposal, 'quote_id' => $bid,
]]);
$purchase->issue($linked['order_id']);
same($linked['line_ids'][0], (int) $purchase->order($linked['order_id'])['lines'][0]['id'], 'Firm line has a stable identity');
$replan = $planning->calculate('plan-two', $base, '2030-02-10', Planning::POLICY);
$recalculated = $planning->run($replan['run_id']);
same($plan['run_id'], (int) $recalculated['prior_id'], 'Recalculation links to the previous run');
same(1, $replan['stats']['changes'], 'Only the pegged component requirement changes');
$change = $recalculated['differences'][0];
same(20_000, Value::fromJson($change['previous_values'])['shortage_milli'], 'Previous shortage is retained');
same(0, Value::fromJson($change['current_values'])['shortage_milli'], 'Approved purchase removes the first shortage only');
$planning->approve($replan['run_id']);
same('superseded', $planning->run($plan['run_id'])['state'], 'Superseded plan retains history');
same('issued', $purchase->order($linked['order_id'])['state'], 'Replan preserves firm purchase order');
$dispositions = $sales->cancelDemand($order['demand_ids'][0], 'Customer requests cancellation of first delivery');
same($linked['line_ids'][0], (int) $dispositions[0]['source_id'], 'Cancelled demand retains its downstream purchasing commitment');
same('issued', $purchase->order($linked['order_id'])['state'], 'Cancelling demand preserves source order');

$qualityOrder = $purchase->create('box-po', 'PO-BOX', $supplier, '2030-01-12', [[
    'item' => $housing, 'uom' => 'box', 'quantity' => 10_000, 'unit_price_minor' => 400, 'due_on' => '2030-02-12',
]]);
$qualityLine = $qualityOrder['line_ids'][0];
$purchase->issue($qualityOrder['order_id']);
$batchOne = [['line_id' => $qualityLine, 'lot' => 'PO-BOX-A', 'location' => $warehouse, 'quantity' => 6_000]];
$receiptA = $purchase->receive('recv-a', $qualityOrder['order_id'], 'RC-A', '2030-02-08', $batchOne);
same('issued', $receiptA['order_state'], 'A partial receipt preserves the open order');
$batchTwo = [['line_id' => $qualityLine, 'lot' => 'PO-BOX-B', 'location' => $warehouse, 'quantity' => 4_000]];
$receiptB = $purchase->receive('recv-b', $qualityOrder['order_id'], 'RC-B', '2030-02-10', $batchTwo);
same('received', $receiptB['order_state'], 'Two batches close the open quantity');
same(100_000, (int) $purchase->order($qualityOrder['order_id'])['lines'][0]['received_milli'], 'AC-09 100 received with frozen PO factor');
same(60_000, $receiptA['lines'][0]['qty_milli'], 'Six boxes are converted with the original factor');

$lineA = $receiptA['lines'][0];
$lineB = $receiptB['lines'][0];
$qualityUser = $master->addMember('Quality Inspector', 'quality-' . $instance . '@example.test', 'Test account seed only!', 'quality');
$inspector = new Inspection($db, $identity->scope($qualityUser, $group['org']));
rejects(403, fn() => (new Purchasing($db, $identity->scope($qualityUser, $group['org'])))
    ->receive('recv-denied', $qualityOrder['order_id'], 'RC-C', '2030-02-12', $batchTwo), 'Quality cannot receive another shipment');
$inspector->decide('inspect-a', $lineA['inspection_id'], 1, 60_000, 0, 0, '2030-02-09', 'Conforming');
$decision = $inspector->decide('inspect-b', $lineB['inspection_id'], 1, 35_000, 0, 0, '2030-02-11', 'Remainder awaited');
same($decision['decision_id'], $inspector->decide('inspect-b', $lineB['inspection_id'], 1, 35_000, 0, 0,
    '2030-02-11', 'Remainder awaited')['decision_id'], 'Inspection remains idempotent after its version advances');
same(5000, $decision['pending_milli'], 'AC-09 5 pending');
same(95_000, array_sum(array_column([$quality->details($lineA['inspection_id']), $quality->details($lineB['inspection_id'])],
    'accepted_milli')), 'AC-09 95 released');
same($receiptB['receipt_id'], $purchase->receive('recv-b', $qualityOrder['order_id'], 'RC-B', '2030-02-10', $batchTwo)['receipt_id'],
    'AC-10 original receipt survives retry after order and inspection changes');
rejects(409, fn() => $purchase->receive('recv-b', $qualityOrder['order_id'], 'RC-B', '2030-02-10',
    [['line_id' => $qualityLine, 'lot' => 'PO-BOX-C', 'location' => $warehouse, 'quantity' => 4_000]]), 'AC-10 same key different payload');
same(2, count($purchase->order($qualityOrder['order_id'])['receipts']), 'AC-10 no new receipt row');
same(35_000, (int) $inventory->lot($lineB['lot_id'])['balances'][0]['qty_milli'], 'AC-10 available balance stays unchanged');
rejects(409, fn() => $inspector->decide('stale-v1', $lineB['inspection_id'], 1, 5000, 0, 0, '2030-02-12', 'Old version'),
    'Stale inspection cannot affect inventory');
$inspector->decide('inspect-b-rejected', $lineB['inspection_id'], 2, 0, 5000, 0, '2030-02-12', 'Out of tolerance');
$bins = array_column($inventory->lot($lineB['lot_id'])['balances'], 'qty_milli', 'quality');
same(35_000, (int) $bins['available'], 'Rejected stock did not inflate availability');
same(5000, (int) $bins['rejected'], 'Rejected stock is visible in a separate bucket');
rejects(404, fn() => (new Purchasing($db, $strangerScope))->receipt($receiptB['receipt_id']), 'Outside group cannot read receipt');

finish('Master data, sales, BOM, MRP, purchase and inbound quality');
