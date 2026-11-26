<?php

declare(strict_types=1);

use ManufacturingErp\{Database, FactoryNetwork, FactoryOwnership, GroupPublication, GroupScenarios, Identity,
    MasterData, PlantConfirmations, ProductEngineering, Scheduling, Value, WeeklyForecast, WorkOrders};

require __DIR__.'/bootstrap.php';

$db = Database::connect();
$identity = new Identity($db);
$suffix = bin2hex(random_bytes(5));
$login = $identity->initialize('Regional network '.$suffix,'GROUP','Regional operations','CNY','Capacity operator',
    'bench-'.$suffix.'@example.test','Capacity run fixture!');
$scope = $identity->scope($login['user'],$login['org']);
(new ManufacturingErp\NetworkPilot($db,$scope))->change('enable',0,'active');
$master = new MasterData($db,$scope);
$network = new FactoryNetwork($db,$scope);
$ownership = new FactoryOwnership($db,$scope);
$approvals = new PlantConfirmations($db,$scope);
$dept = $master->createDepartment('OPS','Operations');
$start = '2027-01-04';
$end = (new DateTimeImmutable($start))->modify('+89 days')->format('Y-m-d');
$items = $sites = [];
for ($n=0; $n<100; ++$n) {
    $code = sprintf('SKU%03d',$n);
    $items[$code] = $master->addItem($code,$code,'ea',$n===0?'manufacture':'purchase',100,0);
}
$csv = ['site,item,week_start,quantity'];
for ($n=0; $n<10; ++$n) {
    $plant = sprintf('P%02d',$n);
    $site = $network->createSite('site-'.$plant,$plant,$plant)['site_id'];
    $sites[] = $site;
    $location = $master->addLocation($plant,$plant.' dock');
    $ownership->assign('location',$location,$site);
    $approvals->authorize($site,$scope->user,true);
    $mapping = $lots = [];
    foreach ($items as $code=>$item) {
        $mapping[] = [$scope->org,$site,$item];
        foreach ([0,1,2,4,5,6,8,9,10,11] as $week) {
            $day = (new DateTimeImmutable($start))->modify("+$week weeks")->format('Y-m-d');
            $csv[] = "$plant,$code,$day,".($n===1?2:1);
            // One released production order replaces the last lot at P09.
            if ($n===9 && $code==='SKU000' && $week===11) {
                continue;
            }
            $lots[] = [$scope->org,$item,"$plant-$code-$week",'opening','ea',$n===0?2000:1000,1000,100,$start];
        }
    }
    $db->transaction(function () use ($db,$mapping,$lots,$scope,$plant,$location): void {
        $db->insertRows('INSERT INTO network_site_items (org_id,site_id,item_id)',$mapping);
        $db->insertRows('INSERT INTO stock_lots (org_id,item_id,lot_code,origin_kind,source_uom,source_qty_milli,factor_milli,unit_cost_minor,received_on)',$lots);
        $db->run("INSERT INTO stock_balances (org_id,lot_id,location_id,quality,qty_milli)
            SELECT org_id,id,?,'available',source_qty_milli FROM stock_lots WHERE org_id=? AND lot_code LIKE ?",[$location,$scope->org,$plant.'-%']);
    });
}
$network->saveLane('regional-route',$sites[0],$sites[1],2,0);
(new WeeklyForecast($db,$scope))->import('import','QUARTER',implode("\n",$csv));
unset($csv,$lots,$mapping);

$eng = new ProductEngineering($db,$scope);
$scheduling = new Scheduling($db,$scope);
$work = new WorkOrders($db,$scope);
$center = $eng->addCenter($dept,'ASSEMBLY','Assembly');
$machine = $scheduling->addMachine($center,'BENCH','Assembly bench');
$scheduling->addShift($center,$start.' 08:00:00',$start.' 12:00:00');
$eng->publishBom($items['SKU000'],$start,[['item'=>$items['SKU001'],'quantity'=>1000,'uom'=>'ea']]);
$eng->publishRoute($items['SKU000'],$start,[['center'=>$center,'label'=>'Assemble','setup_minutes'=>1,'run_seconds_per_unit'=>10]]);
$workId = $work->create('work','CAPACITY-WO',$items['SKU000'],1000,$start,$start,'2027-01-08')['work_id'];
$ownership->assign('work',$workId,$sites[9]);
$work->approve($workId);
$operation = $work->details($workId)['operations'][0]['id'];
$scheduling->book('slot',$operation,$machine,$start.' 08:00:00',$start.' 09:00:00');
$work->release($workId);
$liveWork = fn(): array => $db->rows("SELECT w.id,w.state,p.remaining_milli FROM work_orders w JOIN planning_supplies p
    ON p.org_id=w.org_id AND p.source_kind='work' AND p.source_id=w.id WHERE w.org_id=? AND p.remaining_milli>0 ORDER BY w.id",[$scope->org]);
$before = $liveWork();
same([$workId],array_column($before,'id'),'One actual work order supplying the network');
$plans = new GroupScenarios($db,$scope);
$samples = [];
for ($i=0; $i<4; ++$i) {
    $clock = hrtime(true);
    $run = $plans->freeze('quarter-'.$i,$sites,$start,$end);
    $ms = (hrtime(true)-$clock)/1000000;
    $counts = $db->row("SELECT JSON_LENGTH(snapshot,'$.inputs.forecasts') forecast_rows, JSON_LENGTH(snapshot,'$.inputs.stock') lots,
        JSON_LENGTH(snapshot,'$.inputs.work') released_orders, JSON_LENGTH(snapshot,'$.inputs.sites') sites,
        JSON_LENGTH(snapshot,'$.balances') balances FROM network_scenarios WHERE id=?",[$run['scenario_id']]);
    same([10000,9999,1,10,10000],array_map('intval',array_values($counts)),'Actual full snapshot composition');
    same(500,$run['line_count'],'Five weeks of shortages for each of one hundred materials');
    same(true,$ms<=120000,'Full scenario calculation fits 120 seconds');
    $samples[] = ['phase'=>$i===0?'warmup':'measured','elapsed_ms'=>round($ms,3),'scenario_id'=>$run['scenario_id'],'suggestions'=>$run['line_count']];
}
$scenario = $run['scenario_id'];
$line = $db->row("SELECT id,needed_on FROM network_plan_lines WHERE scenario_id=? AND kind='transfer' ORDER BY id LIMIT 1",[$scenario]);
$approvals->submit('submit',$scenario,1,[$line['id']]);
foreach ([$sites[0],$sites[1]] as $site) {
    $approvals->decide('approve-'.$site,$line['id'],$site,0,$dept,true,$line['needed_on'],'Capacity reserved');
}
$release = (new GroupPublication($db,$scope))->publish('release',$scenario,1,[$line['id']],$end);
same(1,count($release['transfer_ids']),'Publish the selected transfer');
same([],$release['blocked_lines'],'Selected plan accepted');
$after = $liveWork();
same($before,$after,'Publishing preserves all released order IDs, states and remaining quantities');
$summary = ['measured_at_utc'=>gmdate(DATE_ATOM),'os'=>PHP_OS_FAMILY,'architecture'=>php_uname('m'),
    'cpu'=>getenv('PROCESSOR_IDENTIFIER') ?: php_uname('m'),'php'=>PHP_VERSION,'php_peak_bytes'=>memory_get_peak_usage(true),
    'mysql'=>$db->row('SELECT VERSION() version,@@innodb_buffer_pool_size buffer_pool_bytes'),'from'=>$start,'through'=>$end,
    'snapshot'=>$counts,'samples'=>$samples,'successes'=>count($samples)-1,'rejections'=>count($release['blocked_lines']),
    'timeouts'=>count(array_filter(array_slice($samples,1),fn(array $s): bool=>$s['elapsed_ms']>120000)),
    'publish_request'=>['scenario_id'=>$scenario,'revision'=>1,'selected_line_ids'=>[$line['id']],'horizon_end'=>$end],
    'publish_response'=>$release,'published_transfers'=>$release['transfer_ids'],'released_work_before'=>$before,'released_work_after'=>$after];
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
