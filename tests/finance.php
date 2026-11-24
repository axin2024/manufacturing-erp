<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\Exports;
use ManufacturingErp\FiscalClose;
use ManufacturingErp\GeneralLedger;
use ManufacturingErp\Identity;
use ManufacturingErp\Inspection;
use ManufacturingErp\Intercompany;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\Purchasing;
use ManufacturingErp\ReceivablesPayables;
use ManufacturingErp\Sales;
use ManufacturingErp\Shipping;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$suffix = bin2hex(random_bytes(5));
$login = $identity->initialize('Accounting Fixture ' . $suffix, 'FACTORY', 'Factory ledger', 'CNY', 'Accountant',
    'accounting-' . $suffix . '@example.test', 'Unpublished finance test!');
$remote = $identity->initialize('Unrelated Group ' . $suffix, 'OUTSIDE', 'Separate tenant', 'CNY', 'Other accountant',
    'finance-outside-' . $suffix . '@example.test', 'Unpublished finance test!');
$scope = $identity->scope($login['user'], $login['org']);
$outside = $identity->scope($remote['user'], $remote['org']);
$master = new MasterData($db, $scope);
$inventory = new Inventory($db, $scope);
$close = new FiscalClose($db, $scope);
$ledger = new GeneralLedger($db, $scope);
$subledger = new ReceivablesPayables($db, $scope);
$intercompany = new Intercompany($db, $scope);
$purchase = new Purchasing($db, $scope);
$quality = new Inspection($db, $scope);
$sales = new Sales($db, $scope);
$shipping = new Shipping($db, $scope);

$day = '2034-07-01';
$next = '2034-07-02';
$due = '2034-08-10';
$second = $master->createOrganization('TRADE', 'Trading ledger', 'CNY');
$trade = $identity->scope($login['user'], $second);
$tradeMaster = new MasterData($db, $trade);
$tradeInventory = new Inventory($db, $trade);
$tradeClose = new FiscalClose($db, $trade);
$tradeLedger = new GeneralLedger($db, $trade);
$factoryWarehouse = $master->addLocation('PLANT', 'Factory stock');
$tradeWarehouse = $tradeMaster->addLocation('TRADE', 'Trade stock');
$item = $master->addItem('HOUSING', 'Machine housing', 'ea', 'purchase', 1024, 0);
same($item, $tradeMaster->addItem('HOUSING', 'Machine housing', 'ea', 'purchase', 1200, 0), 'Company inventory uses shared product, independent accounts');
$supplier = $master->addParty('SUPPLIER', 'Machining Supplier', 'supplier');
$wrongSupplier = $master->addParty('WRONG', 'Wrong Supplier', 'supplier');
$customer = $master->addParty('CUSTOMER', '+Machine Depot', 'customer');
$po = $purchase->create('po', 'PO-FIN', $supplier, $day, [
    ['item' => $item, 'uom' => 'ea', 'quantity' => 3000, 'unit_price_minor' => 1024, 'due_on' => $next],
]);
$purchase->issue($po['order_id']);
$receipt = $purchase->receive('fin-receipt', $po['order_id'], 'RECEIPT-FIN', $next,
    [['line_id' => $po['line_ids'][0], 'lot' => 'PURCHASED', 'location' => $factoryWarehouse, 'quantity' => 3000]])['lines'][0];
$lot = $receipt['lot_id'];
$quality->decide('fin-qc', $receipt['inspection_id'], 1, 3000, 0, 0, $next, 'Released');
$receiptJournal = $ledger->journal($receipt['journal_id']);
same(['STOCK', 'GRNI'], array_column($receiptJournal['lines'], 'account_code'), 'Physical receipt accrues an unbilled payable');
same(3072, (int) $receiptJournal['lines'][0]['debit_minor'], 'Receipt journal uses exact landed value');

$matches = [['receipt_line_id' => $receipt['receipt_line_id'], 'amount_minor' => 3090]];
rejects(404, fn() => $subledger->bill('bill-wrong', $wrongSupplier, 'BILL-WRONG', $next, $due, $matches), 'Cannot match a different supplier');
$invoice = $subledger->bill('bill', $supplier, 'BILL-001', $next, $due, $matches);
same(18, $invoice['variance_minor'], 'Matched bill posts only the expected purchasing price difference');
same($invoice['bill_id'], $subledger->bill('bill', $supplier, 'BILL-001', $next, $due, $matches)['bill_id'], 'Repeat invoice matches only once');
rejects(409, fn() => $subledger->bill('bill-double', $supplier, 'BILL-OTHER', $next, $due, $matches), 'Single physical receipt cannot underpin two invoices');
$ap = $invoice['payable_id'];
same(3090, (int) $subledger->item($ap)['open_minor'], 'Payable carries the actual invoice value');
$payment = $subledger->settle('paid-1', $ap, 'OUTGOING-001', 1090, $next);
same(2000, $payment['open_minor'], 'Partial payment is accounted for');
same($payment['settlement_id'], $subledger->settle('paid-1', $ap, 'OUTGOING-001', 1090, $next)['settlement_id'], 'Payment callback retry does not double post');
rejects(409, fn() => $subledger->settle('overpaid', $ap, 'OUTGOING-002', 2001, $next), 'Payment cannot clear above balance');
rejects(404, fn() => (new ReceivablesPayables($db, $outside))->item($ap), 'Outside tenant cannot read a payable');

$order = $sales->create('fin-sale', 'SO-FIN', $customer, [[
    'item' => $item, 'quantity' => 1500, 'unit_price_minor' => 2000,
    'deliveries' => [['due_on' => $next, 'quantity' => 1500]],
]]);
$sales->freeze($order['order_id']);
$reserve = $shipping->reserve('reserve', $order['demand_ids'][0], $lot, $factoryWarehouse, 1500)['reservation_id'];
$shipment = $shipping->dispatch('ship', 'SHIP-FIN', $customer, $next, [['reservation_id' => $reserve, 'quantity' => 1500]]);
$ar = $shipment['receivable_id'];
same(1536, $shipment['lines'][0]['cogs_minor'], 'Selling part of a received lot uses proportional carrying value');
same(3000, (int) $subledger->item($ar)['open_minor'], 'Shipment creates the customer receivable');
same(1800, $subledger->settle('ar-paid', $ar, 'BANK-RECEIPT-01', 1200, $next)['open_minor'], 'Partial customer receipt remains open');

rejects(403, fn() => $intercompany->transfer('foreign', 'TRANS-FOREIGN', $outside->org, $lot, $factoryWarehouse, $tradeWarehouse,
    'FOREIGN', 500, $next), 'Cross-tenant transfer is refused');
rejects(404, fn() => $intercompany->transfer('bad-location', 'TRANS-BAD', $trade->org, $lot, $factoryWarehouse, $factoryWarehouse,
    'BAD', 500, $next), 'Destination location is validated inside the paired transaction');
same(1500, (int) $inventory->lot($lot)['balances'][0]['qty_milli'], 'Failed pair rolls back source inventory');
same([], $intercompany->transfers(), 'Failed pair leaves no pending transfer header');
$moved = $intercompany->transfer('paired-key', 'TRANS-OK', $trade->org, $lot, $factoryWarehouse, $tradeWarehouse, 'TRADE-LOT', 500, $next);
same(512, $moved['amount_minor'], 'Transfer shares carrying value');
same($moved['transfer_id'], $intercompany->transfer('paired-key', 'TRANS-OK', $trade->org, $lot, $factoryWarehouse, $tradeWarehouse,
    'TRADE-LOT', 500, $next)['transfer_id'], 'Transfer idempotency covers both organizations');
same(500, (int) $tradeInventory->lot($moved['destination_lot_id'])['balances'][0]['qty_milli'], 'Trading has its own receiving lot');
same(['DUE_FROM','STOCK'], array_column($ledger->journal($moved['source_journal_id'])['lines'], 'account_code'), 'Source recognizes group receivable');
same(['STOCK','DUE_TO'], array_column($tradeLedger->journal($moved['destination_journal_id'])['lines'], 'account_code'), 'Destination recognizes group payable');

$tiny = $inventory->openingBalance('tiny', $item, $factoryWarehouse, 'TINY', 'ea', 3, 400, $next);
$one = $intercompany->transfer('tiny-first', 'TRANS-TINY-1', $trade->org, $tiny['lot_id'], $factoryWarehouse, $tradeWarehouse, 'TINY-ONE', 1, $next);
$two = $intercompany->transfer('tiny-remainder', 'TRANS-TINY-2', $trade->org, $tiny['lot_id'], $factoryWarehouse, $tradeWarehouse, 'TINY-TWO', 2, $next);
same(null, $one['source_journal_id'], 'Rounded zero amount needs no financial voucher');
same(1, $two['amount_minor'], 'One minor unit stays with the remainder and is never lost');
same([], $inventory->lot($tiny['lot_id'])['balances'], 'Remainder can be moved in full');

$amountLines = [['account' => 'GENERAL_EXPENSE', 'currency' => 'USD', 'signed_minor' => 100],
    ['account' => 'BANK', 'currency' => 'CNY', 'signed_minor' => -100]];
rejects(409, fn() => $ledger->adjustment('currencies', 'ADJ-UNBALANCED', $next, 'Invalid mixed currency', $amountLines),
    'Opposite amounts in two currencies cannot be used for balance');
$adjust = $ledger->adjustment('adjustment', 'ADJ-BOTH', $next, 'Record previously confirmed service expenses', [
    ...$amountLines, ['account' => 'BANK', 'currency' => 'USD', 'signed_minor' => -100],
    ['account' => 'GENERAL_EXPENSE', 'currency' => 'CNY', 'signed_minor' => 100],
]);
same(4, count($ledger->journal($adjust['journal_id'])['lines']), 'Each currency is balanced inside the same journal');

// Simulate a historical one-sided record in this disposable database by removing only the counterparty's pair.
$missingId = $moved['destination_journal_id'];
$dest = $trade->org;
$saved = $tradeLedger->journal($missingId)['lines'];
$db->run('DELETE FROM journal_lines WHERE org_id=? AND journal_id=?', [$dest, $missingId]);
$refused = $close->close($day);
same('open', $refused['state'], 'AC-16 missing counterparty voucher blocks period close');
$mismatch = current(array_filter($refused['blockers'], static fn(array $x): bool => $x['kind'] === 'intercompany_unmatched'));
same($moved['transfer_id'], $mismatch['transfer_id'], 'AC-16 shows the affected movement');
same(true, $mismatch['source_matched'], 'AC-16 source side has the expected movement and voucher');
same(false, $mismatch['destination_matched'], 'AC-16 destination side is missing its journal posting');
foreach ($saved as $line) {
    $db->run('INSERT INTO journal_lines (org_id,journal_id,line_no,account_code,currency,debit_minor,credit_minor) VALUES (?,?,?,?,?,?,?)',
        [$dest, $missingId, $line['line_no'], $line['account_code'], $line['currency'], $line['debit_minor'], $line['credit_minor']]);
}
$closed = $close->close($day);
same('closed', $closed['state'], 'Period can close after the source and destination agree');
same('closed', $tradeClose->close($day)['state'], 'The receiving period closes independently');
same($closed['digest'], $close->review($day)['digest'], 'Review verifies the persisted period snapshot hash');
$byDirection = array_column($closed['snapshot']['open_items'], 'balance_minor', 'direction');
same(1800, (int) $byDirection['ar'], 'Period snapshot includes outstanding receivables');
same(2000, (int) $byDirection['ap'], 'Period snapshot includes outstanding payables');
rejects(409, fn() => $subledger->settle('locked', $ar, 'BANK-LATE', 100, '2034-07-03'), 'Closing rejects new financial postings in the period');
same(1800, (int) $subledger->item($ar)['open_minor'], 'Closed-period settlement is rolled back completely');
rejects(409, fn() => $inventory->openingBalance('locked-stock', $item, $factoryWarehouse, 'LOCKED', 'ea', 1000, 1024, '2034-07-03'),
    'Closing rejects new physical postings in the period');
$correction = $ledger->adjustment('adjust-next', 'ADJ-AUGUST', '2034-08-01', 'Reverse part of the expense in the open period', [
    ['account' => 'BANK', 'currency' => 'CNY', 'signed_minor' => 25],
    ['account' => 'GENERAL_EXPENSE', 'currency' => 'CNY', 'signed_minor' => -25],
], $adjust['journal_id']);
same($adjust['journal_id'], (int) $ledger->journal($correction['journal_id'])['corrects_journal_id'], 'Open-period adjustment links to original voucher');
same($closed['digest'], $close->review($day)['digest'], 'Adjustment does not mutate the closed period snapshot');
same(0, $subledger->settle('august-pay', $ap, 'OUTGOING-003', 2000, '2034-08-01')['open_minor'], 'Remaining payable can clear in the open period');

$exports = new Exports($db, $scope);
same("'+Machine Depot", csvRecords($exports, 'receivables')[0]['party'], 'CSV exports neutralize spreadsheet formulas in names');
same('0', csvRecords($exports, 'payables')[0]['open_minor'], 'Payable CSV includes the cleared balance');
$august = csvRecords($exports, 'journals', null, '2034-08-01');
same(['2034-08-01'], array_values(array_unique(array_column($august, 'period_start'))), 'Journal CSV is limited to the requested period');
same(2025, array_sum(array_column($august, 'debit_minor')), 'Journal CSV reports all matching debit lines');
same(2025, array_sum(array_column($august, 'credit_minor')), 'Journal CSV reports all matching credit lines');
$outsideExports = new Exports($db, $outside);
same([], csvRecords($outsideExports, 'journals'), 'Outside tenant exports zero journal lines');
same([], csvRecords($outsideExports, 'receivables'), 'Outside tenant exports zero receivables');

finish('Journal, receivables, payables, intercompany and fiscal close');
