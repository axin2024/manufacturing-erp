<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\FactoryNetwork;
use ManufacturingErp\FactoryOwnership;
use ManufacturingErp\GroupPublication;
use ManufacturingErp\GroupScenarios;
use ManufacturingErp\Identity;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;
use ManufacturingErp\PlantConfirmations;
use ManufacturingErp\WeeklyForecast;
use ManufacturingErp\Value;

require __DIR__.'/bootstrap.php';
require __DIR__.'/WebTestClient.php';

$phase = $argv[1] ?? '';
$path = $argv[2] ?? '';
$base = getenv('ERP_HTTP_URL') ?: '';
if (!in_array($phase,['dispatch','receive','replay'],true) || $path==='' || !preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}$~D',$base)) {
    throw new RuntimeException('Expected dispatch|receive|replay, a scratch manifest path and a loopback ERP_HTTP_URL.');
}
$db = Database::connect();
$identity = new Identity($db);
$password = 'Network warehouse recovery fixture!';
if ($phase==='dispatch') {
    if (file_exists($path)) {
        throw new RuntimeException('Choose a new manifest for the recovery run.');
    }
    $email = 'restore-'.bin2hex(random_bytes(6)).'@example.test';
    $root = $identity->initialize('Recovery group','SOURCE','Source ledger','CNY','Recovery operator',$email,$password);
    $a = $identity->scope($root['user'],$root['org']);
    $ma = new MasterData($db,$a);
    $b = $identity->scope($a->user,$ma->createOrganization('DEST','Destination ledger','CNY'));
    (new ManufacturingErp\NetworkPilot($db,$a))->change('enable',0,'active');
    $mb = new MasterData($db,$b);
    $sites = $locations = $departments = [];
    foreach ([[$a,$ma,'SOURCE'],[$b,$mb,'DEST']] as [$scope,$master,$code]) {
        $factory = new FactoryNetwork($db,$scope);
        $site = $factory->createSite('factory',$code,$code)['site_id'];
        $item = $master->addItem('VALVE','Valve','ea','purchase',1000,0);
        $factory->mapItem($site,$item);
        $location = $master->addLocation('DOCK','Warehouse');
        (new FactoryOwnership($db,$scope))->assign('location',$location,$site);
        (new PlantConfirmations($db,$scope))->authorize($site,$scope->user,true);
        $sites[] = $site;
        $locations[] = $location;
        $departments[] = $master->createDepartment('OPS','Operations');
    }
    (new FactoryNetwork($db,$a))->saveLane('lane',$sites[0],$sites[1],1,0);
    $lot = (new Inventory($db,$a))->openingBalance('opening',$item,$locations[0],'RECOVERY-VALVES','ea',80000,950,'2027-01-04')['lot_id'];
    (new WeeklyForecast($db,$b))->import('forecast','W01',"site,item,week_start,quantity\nDEST,VALVE,2027-01-04,80");
    $plans = new GroupScenarios($db,$a);
    $scenario = $plans->freeze('freeze',$sites,'2027-01-04','2027-01-10')['scenario_id'];
    $lines = array_column($plans->detail($scenario)['lines'],'id');
    same(1,count($lines),'One available transfer path');
    (new PlantConfirmations($db,$a))->submit('submit',$scenario,1,$lines);
    foreach ([$a,$b] as $i=>$scope) {
        (new PlantConfirmations($db,$scope))->decide('approve',$lines[0],$sites[$i],0,$departments[$i],true,'2027-01-06','Capacity checked');
    }
    $published = (new GroupPublication($db,$a))->publish('publish',$scenario,1,$lines,'2027-01-10');
    $manifest = ['email'=>$email,'source_org'=>$a->org,'destination_org'=>$b->org,'locations'=>$locations,
        'lot'=>$lot,'transfer'=>$published['transfer_ids'][0]];
} else {
    $manifest = Value::fromJson(file_get_contents($path));
}
$client = new WebTestClient($base);
$guest = $client->get('/?page=session')['body'];
$auth = $client->post('login',['email'=>$manifest['email'],'password'=>$password],$guest['csrf'])['body'];
$csrf = $auth['csrf'];
$org = $phase==='dispatch' ? $manifest['source_org'] : $manifest['destination_org'];
if ($auth['organization_id']!==$org) {
    $csrf = $client->post('switch-org',['org_id'=>$org],$csrf)['body']['csrf'];
}
$order = $manifest['transfer'];
if ($phase==='dispatch') {
    $sent = $client->post('dispatch-transfer',['transfer'=>$order,'reference'=>'DN-080','lot'=>$manifest['lot'],
        'location'=>$manifest['locations'][0],'quantity'=>'80','posted_on'=>'2027-01-05','request_key'=>'outbound'],$csrf);
    same(200,$sent['status'],'Committed dispatch before restoring database');
    $manifest['shipment'] = $sent['body']['shipment_id'];
    file_put_contents($path,Value::json($manifest),LOCK_EX);
    echo Value::json(['phase'=>$phase,'transfer'=>$order,'shipment'=>$manifest['shipment'],'lot'=>'RECOVERY-VALVES','shipped_milli'=>80000]).PHP_EOL;
    exit;
}
$payload = ['transfer'=>$order,'shipment'=>$manifest['shipment'],'reference'=>'GR-078','location'=>$manifest['locations'][1],
    'expected'=>'80','counted'=>'78','posted_on'=>'2027-01-06','reason'=>'Two missing at unloading','request_key'=>'dock-receipt'];
if ($phase==='receive') {
    $before = $client->get('/?page=transit&id='.$order)['body']['details'];
    same(['80','0','80'],[$before['已发'],$before['已收'],$before['在途']],'Restored primary preserves outbound stock');
    $client->submitAndDropResponse('receive-transfer',$payload,$csrf,fn(): bool => $db->row(
        'SELECT id FROM network_receipts WHERE org_id=? AND local_ref=?',[$org,'GR-078'])!==null);
    $manifest['receipt'] = $db->row('SELECT id FROM network_receipts WHERE org_id=? AND local_ref=?',[$org,'GR-078'])['id'];
    file_put_contents($path,Value::json($manifest),LOCK_EX);
    echo Value::json(['phase'=>$phase,'receipt'=>$manifest['receipt'],'request_key'=>'dock-receipt','client_response_read'=>false]).PHP_EOL;
    exit;
}
$read = $client->post('receive-transfer',$payload,$csrf);
same(200,$read['status'],'Repeated request resolves after interruption');
same($manifest['receipt'],$read['body']['receipt_id'],'Original receipt after restart');
$view = $client->get('/?page=transit&id='.$order)['body'];
$totals = $view['details'];
same(['80','78','2'],[$totals['已发'],$totals['已收'],$totals['在途']],'Workbench recovery quantities');
$difference = $view['tables'][2]['rows'][0];
same(2000,(int)$difference['pending_milli'],'Shortage retained for group review');
$pair = $client->get('/?page=eliminations&id='.$manifest['shipment'].'&since=2027-01-01&through=2027-01-31')['body']['tables'];
same(2000,$pair[0]['rows'][0]['remaining_minor'],'Financial workbench retains single-sided 20');
same(4,count($pair),'Financial drilldown contains pair, receipt and both journals');
$physical = $db->row("SELECT COUNT(*) AS receipts,SUM(b.qty_milli) AS stock,SUM(m.delta_milli) AS movement FROM network_receipts r
    JOIN stock_movements m ON m.id=r.movement_id JOIN stock_balances b ON b.lot_id=r.lot_id AND b.location_id=r.location_id AND b.quality='available'
    WHERE r.shipment_id=?",[$manifest['shipment']]);
same([1,78000,78000],array_map('intval',array_values($physical)),'Only one inbound lot and movement on primary');
echo Value::json(['phase'=>$phase,'input'=>$payload,'receipt'=>$read['body'],'warehouse_totals'=>$totals,'variance'=>$difference,
    'finance'=>$pair[0]['rows'][0],'physical'=>$physical]).PHP_EOL;
