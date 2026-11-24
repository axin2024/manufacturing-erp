<?php

declare(strict_types=1);

use ManufacturingErp\BatchJobs;
use ManufacturingErp\BatchWorker;
use ManufacturingErp\Database;
use ManufacturingErp\Exports;
use ManufacturingErp\GeneralLedger;
use ManufacturingErp\Identity;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\ProductEngineering;
use ManufacturingErp\Value;

require __DIR__ . '/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$tag = bin2hex(random_bytes(5));
$password = 'Only a batch fixture password.';
$account = $identity->initialize('Batch group ' . $tag, 'FACTORY', 'Factory', 'CNY', 'ERP administrator',
    'batch-admin-' . $tag . '@example.test', $password);
$external = $identity->initialize('External group ' . $tag, 'EXTERNAL', 'Other factory', 'CNY', 'Outside ERP',
    'batch-external-' . $tag . '@example.test', $password);
$admin = $identity->scope($account['user'], $account['org']);
$outsider = $identity->scope($external['user'], $external['org']);
$master = new MasterData($db, $admin);
$worker = new BatchWorker($db);
$jobs = new BatchJobs($db, $admin);
$location = $master->addLocation('STOCK', 'Main warehouse');
$plannerId = $master->addMember('Planner', 'planner-' . $tag . '@example.test', $password, 'planner');
$planner = $identity->scope($plannerId, $admin->org);
$plannerJobs = new BatchJobs($db, $planner);
$inventory = new Inventory($db, $admin);
$row = ['code' => 'PRESS', 'name' => 'Bench press', 'uom' => 'ea', 'sourcing' => 'manufacture', 'standard_cost_minor' => 2000, 'lead_days' => 2];
$raw = ['code' => 'PIN', 'name' => 'Pin', 'uom' => 'ea', 'sourcing' => 'purchase', 'standard_cost_minor' => 170, 'lead_days' => 2];
$bad = ['code' => 'FIXTURE', 'name' => 'Fixture'] + $raw;
$bad['lead_days'] = 'not an integer';

$submission = $plannerJobs->enqueue('items-v1', 'items', 1, [$row, $bad, $raw]);
$id = $submission['job_id'];
$replay = $plannerJobs->enqueue('items-v1', 'items', 1, [$row, $bad, $raw]);
same($submission['job_id'], $replay['job_id'], 'A lost submission response returns the same job id');
same(3, $replay['total_rows'], 'Replay preserves the number of submitted rows');
rejects(409, fn() => $plannerJobs->enqueue('items-v1', 'items', 1, [$row]), 'A submission key cannot be reused for a different payload');
rejects(422, fn() => $plannerJobs->enqueue('version', 'items', 2, [$row]), 'Unknown format version is rejected');
same([], (new BatchJobs($db, $outsider))->jobs(), 'Outside tenant sees zero jobs');
rejects(404, fn() => (new BatchJobs($db, $outsider))->details($id), 'Outside tenant cannot query the job');
rejects(404, fn() => (new BatchJobs($db, $outsider))->attempts($id, 1), 'Outside tenant cannot query attempt history');
same(['pending' => 3], $jobs->details($id)['counts'], 'Waiting rows are visible before processing');
same('succeeded', $worker->next()['state'], 'The first valid record is applied');
same('running', $jobs->details($id)['state'], 'The job stays active until waiting rows are handled');
same('rejected', $worker->next()['state'], 'An invalid row is rejected individually');
same('succeeded', $worker->next()['state'], 'A later valid row runs after an invalid one');
same(null, $worker->next(), 'The worker has no remaining waiting rows');
$progress = $jobs->details($id);
same('partial', $progress['state'], 'Mixed results show partial completion');
same('字段 lead_days 须为整数。', $progress['rows'][1]['error_text'], 'Rejected input includes its reason');
$original = Value::fromJson($progress['rows'][0]['result'])['item_id'];
rejects(409, fn() => $jobs->retry($id, [1 => $row]), 'A successful row cannot be retried or overwritten');
$corrected = $bad;
$corrected['lead_days'] = 0;
same(1, $jobs->retry($id, [2 => $corrected])['requeued_rows'], 'Correcting only the invalid row keeps prior successes intact');
$worker->next();
$complete = $jobs->details($id);
same('completed', $complete['state'], 'Corrected batch completes');
same([1,2,1], array_column($complete['rows'], 'attempts'), 'Only the failed row acquired a second attempt');
same($original, Value::fromJson($complete['rows'][0]['result'])['item_id'], 'Successful output identity survives retry');
$attempts = $jobs->attempts($id, 2);
same(['rejected','succeeded'], array_column($attempts, 'state'), 'History preserves original and corrected outcomes');
same([$plannerId,$admin->user], array_column($attempts, 'actor_id'), 'History identifies both executors');
same(false, $attempts[0]['payload_digest'] === $attempts[1]['payload_digest'], 'History keeps a digest for each input version');

$bom = $jobs->enqueue('bom', 'bom', 1, [[
    'product_code' => 'PRESS', 'effective_on' => '2036-01-01',
    'lines' => [['item_code' => 'PIN', 'quantity' => '2.250', 'uom' => 'ea', 'alternative_codes' => ['FIXTURE']]],
]]);
same('succeeded', $worker->next()['state'], 'BOM batch uses the normal engineering operation');
$bomId = Value::fromJson($jobs->details($bom['job_id'])['rows'][0]['result'])['bom_id'];
$engineering = (new ProductEngineering($db, $admin))->bomRevision($bomId);
same(2250, (int) $engineering['lines'][0]['qty_per_milli'], 'Imported BOM preserves quantity precision');
$opening = ['item_code' => 'PIN', 'location_code' => 'STOCK', 'lot' => 'OPEN-A', 'uom' => 'ea', 'quantity' => '3.125',
    'unit_cost_minor' => 160, 'posted_on' => '2036-01-02'];
rejects(403, fn() => $plannerJobs->enqueue('no-warehouse', 'opening', 1, [$opening]), 'Planner may not submit opening stock');
$openJob = $jobs->enqueue('opening', 'opening', 1, [$opening, ['lot' => 'OPEN-B'] + $opening, ['lot' => 'OPEN-C'] + $opening]);
rejects(403, fn() => $plannerJobs->details($openJob['job_id']), 'Planner is not allowed to read warehouse job details');
rejects(403, fn() => $plannerJobs->attempts($openJob['job_id'], 1), 'Planner is not allowed to read warehouse attempts');

try {
    $db->transaction(function () use ($worker): void {
        same('succeeded', $worker->next()['state'], 'Opening stock and row status can commit together');
        throw new RuntimeException('simulated interruption');
    });
    throw new LogicException('The test should have interrupted execution.');
} catch (RuntimeException $interruption) {
    same('simulated interruption', $interruption->getMessage(), 'The rollback was caused by the planned interruption');
}
same([], $inventory->lots(), 'An interrupted transaction leaves no opening lot');
same([], (new GeneralLedger($db, $admin))->journals(), 'An interrupted transaction leaves no journal');
same([], $jobs->attempts($openJob['job_id'], 1), 'An interrupted transaction leaves no durable outcome');
same('pending', $jobs->details($openJob['job_id'])['rows'][0]['state'], 'An interrupted row remains eligible for a new worker');
$worker->next();
$openingResult = Value::fromJson($jobs->details($openJob['job_id'])['rows'][0]['result']);
same(3125, (int) $inventory->lot($openingResult['lot_id'])['balances'][0]['qty_milli'], 'The retry posts the opening quantity once');
same(500, (int) (new GeneralLedger($db, $admin))->journal($openingResult['journal_id'])['lines'][0]['debit_minor'],
    'Opening stock import uses the actual accounting pipeline');
same(2, $jobs->cancel($openJob['job_id'])['cancelled_rows'], 'Cancellation affects only the two pending rows');
same(['succeeded','cancelled','cancelled'], array_column($jobs->details($openJob['job_id'])['rows'], 'state'), 'Cancellation preserves the posted outcome');
same(1, count($inventory->lots()), 'Cancelled rows create no stock');
rejects(409, fn() => $jobs->retry($openJob['job_id'], []), 'Cancelled jobs cannot be requeued');

$plan = $plannerJobs->enqueue('material-plan', 'mrp', 1, [['as_of' => '2036-01-02', 'horizon_on' => '2036-03-31']]);
$master->setMembership($plannerId, 'planner', false);
same('rejected', $worker->next()['state'], 'Worker rechecks permissions at execution time');
same('failed', $jobs->details($plan['job_id'])['state'], 'The revoked executor leaves a recorded failure');
same(1, $jobs->retry($plan['job_id'], [])['requeued_rows'], 'An authorized executor can take over failed rows');
same('succeeded', $worker->next()['state'], 'The new executor can run the material plan');
same(1, count($db->rows('SELECT id FROM mrp_runs WHERE org_id=?', [$admin->org])), 'There is only one durable MRP result');
same(null, $worker->next(), 'The cancelled and completed rows do not run again');
same($location, (int) $inventory->lot($openingResult['lot_id'])['balances'][0]['location_id'], 'Stock stays in the imported location');
same('500', csvRecords(new Exports($db, $admin), 'journals')[0]['debit_minor'], 'The journal entry is available in CSV');

finish('Batch data import, resume and cancellation');
