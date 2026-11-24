<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\DemoScenario;
use ManufacturingErp\Exports;
use ManufacturingErp\Genealogy;
use ManufacturingErp\Identity;
use ManufacturingErp\Inspection;
use ManufacturingErp\Inventory;
use ManufacturingErp\Planning;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Purchasing;
use ManufacturingErp\ReceivablesPayables;
use ManufacturingErp\Shipping;
use ManufacturingErp\WorkOrders;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$demo = new DemoScenario($db);
$tag = bin2hex(random_bytes(5));
$email = 'demo-fixture-' . $tag . '@example.test';
$password = 'Local integration scenario!';
$installed = $demo->install($email, $password);
$user = $identity->authenticate($email, $password);
$scope = $identity->scope($user, $installed['org_id']);
$objects = $installed['objects'];
$facts = $installed['facts'];

$expected = [
    'pin_gross_milli' => 200_000, 'pin_stock_milli' => 70_000, 'pin_incoming_milli' => 30_000, 'pin_shortage_milli' => 100_000,
    'material_variance_minor' => 2000, 'reported_cost_minor' => 306_800, 'finished_milli' => 100_000,
    'accepted_milli' => 98_000, 'rework_milli' => 2000, 'shipped_milli' => 98_000, 'shipped_cost_minor' => 300_664,
    'outstanding_milli' => 2000, 'affected_customer' => '合成客户 · 海川设备',
];
same($expected, $facts, 'Integrated scenario matches independently calculated quantity and cost answers');
same(2, count($identity->organizations($user)), 'The group has factory and trade organizations');
$trade = $identity->scope($user, $installed['trade_org_id']);
same([], (new Inventory($db, $trade))->lots(), 'The trading company starts with separate stock');
$order = new WorkOrders($db, $scope);
$work = $order->details($objects['work_id']);
same(2, count($work['operations']), 'Two workshop operations are part of the assembly history');
same(['ASSEMBLY-1','TEST-1'], array_column($work['operations'], 'machine_code'), 'Operations used the two scheduled departments');
same(2, count((new ProductEngineering($db, $scope))->bomRevision($objects['device_bom_id'])['lines']), 'The first BOM has both pins and the assembly');
same(1, count((new ProductEngineering($db, $scope))->bomRevision($objects['module_bom_id'])['lines']), 'The assembly has a second BOM level');
$pegging = (new Planning($db, $scope))->pegging($objects['planning_run_id'], $objects['pin_requirement_ref']);
same(30_000, array_sum(array_column(array_filter($pegging, static fn(array $row): bool => $row['source_kind'] === 'purchase'), 'qty_milli')),
    'Planning pegging records the original in-transit quantity');

$purchase = new Purchasing($db, $scope);
$po = $purchase->order($objects['planned_order_id']);
$procurementLine = $po['lines'][0];
same(100_000, (int) $procurementLine['received_milli'], 'One procurement line covers the net shortage');
$realReceipt = $purchase->receipt($objects['planned_receipt_id']);
$repeat = $purchase->receive('RC-NET-SHORTAGE', $objects['planned_order_id'], 'RC-NET-SHORTAGE', '2037-06-11', [[
    'line_id' => (int) $procurementLine['id'], 'lot' => 'PIN-ORDERED-100', 'location' => (int) $realReceipt['lines'][0]['location_id'],
    'quantity' => 100_000,
]]);
same($objects['planned_receipt_id'], $repeat['receipt_id'], 'Replaying the supplier event returns the original receipt');
same(100_000, (int) $purchase->order($objects['planned_order_id'])['lines'][0]['received_milli'], 'Replay does not double the received quantity');
same(1, count(array_filter((new Inventory($db, $scope))->lots(), static fn(array $lot): bool => $lot['lot_code'] === 'PIN-ORDERED-100')),
    'Replay retains one physical receipt lot');

$quality = (new Inspection($db, $scope))->details($objects['inspection_id']);
same(98_000, (int) $quality['accepted_milli'], 'Only 98 units have been accepted');
same($objects['rework_id'], (int) $quality['decisions'][0]['rework_order_id'], 'Inspection links to the two outstanding units');
same(2000, (int) $order->details($objects['rework_id'])['qty_milli'], 'The rework order tracks its own quantity');
$stock = (new Inventory($db, $scope))->lot($objects['finished_lot_id'])['balances'];
same(['rework'], array_column($stock, 'quality'), 'The remaining units are in the controlled rework bucket');
rejects(409, fn() => (new Shipping($db, $scope))->reserve('demo-stock-over', $objects['demand_id'],
    $objects['finished_lot_id'], (int) $stock[0]['location_id'], 2000), 'Unreleased stock cannot be reserved');
$downstream = (new Genealogy($db, $scope))->trace($objects['substitute_lot_id'], 'downstream');
same($objects['substitute_issue_id'], (int) $downstream['links'][0]['source_id'], 'Recall links to the original issue document');
same([$objects['shipment_id']], array_column($downstream['shipments'], 'shipment_id'), 'Recall reaches the physical customer shipment');
$subledger = new ReceivablesPayables($db, $scope);
same(637_000, (int) $subledger->item($objects['receivable_id'])['open_minor'], 'First shipment posts customer balance');
same(156_000, (int) $subledger->item($objects['payable_id'])['open_minor'], 'Both supplier receipts match the invoice');
$readback = $demo->install($email, $password);
same($objects['planning_run_id'], $readback['objects']['planning_run_id'], 'Rerunning the installer for the same account reads the original plan');
same($objects['shipment_id'], $readback['objects']['shipment_id'], 'Rerunning the installer avoids a second shipment');
same(1, count((new Shipping($db, $scope))->shipments()), 'Exactly one shipment exists');

$other = $identity->initialize('Separate group', 'EXTERNAL', 'Outside ledger', 'CNY', 'Outside operator',
    'demo-outsider-' . $tag . '@example.test', $password);
$outsider = $identity->scope($other['user'], $other['org']);
same(null, $demo->lookup($outsider), 'The demonstration lookup respects tenant scope');
rejects(403, fn() => $identity->scope($other['user'], $installed['org_id']), 'Cross-tenant organization selection is forbidden');
rejects(404, fn() => (new Exports($db, $outsider))->open('lot-trace', $objects['substitute_lot_id']), 'An unrelated tenant cannot export the trace');
finish('Integrated sales, MRP, purchase, production, quality and delivery demo');
