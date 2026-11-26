<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\DemoScenario;
use ManufacturingErp\Identity;
use ManufacturingErp\Inventory;
use ManufacturingErp\MasterData;

function checkNetworkWorkbench(string $base, Database $db): void
{
    $identity = new Identity($db);
    $email = 'network-http-' . bin2hex(random_bytes(8)) . '@example.test';
    $password = 'Factory network HTTP fixture!';
    $fixture = (new DemoScenario($db))->install($email,$password);
    $adminId = $identity->authenticate($email,$password);
    $scope = $identity->scope($adminId,$fixture['org_id']);
    $stock = $fixture['objects'];
    $master = new MasterData($db,$scope);
    $input = $master->addItem('QA-RAW','Inspection blank','ea','purchase',100,0);
    $client = new WebTestClient($base);
    $guest = $client->get('/?page=session');
    $csrf = $client->post('login',['email'=>$email,'password'=>$password],$guest['body']['csrf'])['body']['csrf'];
    $sourceSite = $client->post('create-network-site', ['request_key'=>'plant-a','code'=>'MOLD','name'=>'Molding'], $csrf)['body']['site_id'];
    $destinationSite = $client->post('create-network-site', ['request_key'=>'plant-b','code'=>'PACK','name'=>'Packing'], $csrf)['body']['site_id'];
    same(200, $client->post('map-site-item', ['site'=>$sourceSite,'item'=>$input], $csrf)['status'], 'Assign plant item in workbench');
    $lane = $client->post('save-network-lane', ['request_key'=>'line-ab','source'=>$sourceSite,'destination'=>$destinationSite,'days'=>2,'revision'=>0], $csrf)['body']['lane_id'];
    $networkScreen = $client->get('/?page=network&lane='.$lane.'&item='.$input.'&departure=2026-11-27')['body'];
    same('2026-12-01', $networkScreen['details']['预计到货'], 'Preview destination date through workbench');
    same($destinationSite, $networkScreen['tables'][2]['rows'][0]['site_id'], 'Display missing qualification through workbench');
    same(404,$client->get('/?page=scenarios')['status'],'Pilot is closed to this tenant before approval');
    $ownershipReport = $client->get('/?page=network-ownership')['body'];
    same(true,$ownershipReport['tables'][0]['rows'][1]['unresolved']>0,'Upgrade preflight locates legacy work orders');
    $enable = ['request_key'=>'pilot-on','revision'=>0,'state'=>'active'];
    same(409,$client->post('change-network-pilot',$enable,$csrf)['status'],'Legacy ownership blocks pilot launch');
    $legacyWork = $client->post('backfill-site-ownership',['request_key'=>'http-work-backfill','kind'=>'work','limit'=>500],$csrf)['body'];
    same(true,$legacyWork['conflicts']>0,'Two possible factories require explicit ownership');
    foreach ($identity->organizations($adminId) as $organization) {
        $other = $identity->scope($adminId,$organization['id']);
        $factory = new ManufacturingErp\FactoryNetwork($db,$other);
        $site = $other->org===$scope->org ? $sourceSite : $factory->createSite('trade','TRADE','Trade') ['site_id'];
        foreach ($db->rows('SELECT item_id FROM org_items WHERE org_id=?',[$other->org]) as $material) {
            $factory->mapItem($site,$material['item_id']);
        }
        $owner = new ManufacturingErp\FactoryOwnership($db,$other);
        foreach (['location'=>'locations','work'=>'work_orders'] as $kind=>$table) {
            foreach ($db->rows("SELECT id FROM $table WHERE org_id=?",[$other->org]) as $record) {
                if ($other->org===$scope->org) {
                    same(200,$client->post('assign-site-ownership',['site'=>$site,'kind'=>$kind,'entity'=>$record['id']],$csrf)['status'],'Resolve original ownership through normal HTTP form');
                } else {
                    $owner->assign($kind,$record['id'],$site);
                }
            }
        }
    }
    same($sourceSite,$db->row('SELECT site_id FROM network_work_sites WHERE work_id=?',[$stock['work_id']])['site_id'],'Legacy work number preserved on physical plant binding');
    same('active',$client->post('change-network-pilot',$enable,$csrf)['body']['state'],'Group pilot activates after all ledgers reconcile');
    same(true, str_contains($client->get('/?page=forecasts', false)['body'], 'multipart/form-data'), 'Forecast form accepts a CSV file');
    $forecastCsv = "site,item,week_start,quantity\nMOLD,QA-RAW,2026-11-30,100";
    $imported = $client->uploadForecast($forecastCsv, 'W48', 'forecast-http', $csrf);
    same(1, $imported['body']['line_count'], 'Import weekly forecast through HTTP');
    $forecastId = $client->get('/?page=forecasts')['body']['tables'][0]['rows'][0]['id'];
    same('100', $client->get('/?page=forecasts&id='.$forecastId)['body']['details']['独立剩余需求'], 'Forecast drilldown shows unconsumed quantity');
    $networkCustomer = $master->addParty('NET-BUY', 'Forecast client', 'customer');
    $networkSales = new ManufacturingErp\Sales($db, $scope);
    $networkSale = $networkSales->create('net-sale','SO-NET',$networkCustomer,[['item'=>$input,'quantity'=>40000,'unit_price_minor'=>100,
        'deliveries'=>[['due_on'=>'2026-12-01','quantity'=>40000]]]]);
    $networkSales->freeze($networkSale['order_id']);
    same(200, $client->post('assign-demand-site',['site'=>$sourceSite,'demand'=>$networkSale['demand_ids'][0]],$csrf)['status'], 'Planner selects supplying factory');
    same($imported['body']['batch_id'], $client->uploadForecast($forecastCsv, 'W48', 'reimport-http', $csrf)['body']['batch_id'], 'File replay retains original batch');
    $forecastDetail = $client->get('/?page=forecasts&id='.$forecastId)['body'];
    same('60', $forecastDetail['details']['独立剩余需求'], 'Uploaded and replayed forecast nets against a single real order');
    same('SO-NET', $forecastDetail['tables'][0]['rows'][0]['number'], 'Order trace visible in workbench');
    $scenarioResult = $client->post('freeze-network-plan',['request_key'=>'http-scenario','sites'=>[$sourceSite,$destinationSite],
        'start'=>'2026-11-30','end'=>'2026-12-06'],$csrf)['body'];
    $scenarioPage = $client->get($scenarioResult['url'], false);
    same(200,$scenarioPage['status'],'Frozen multi-factory scenario renders');
    same(true,str_contains($scenarioPage['body'],'交期与成本比较'),'Workstation exposes sourcing constraints');
    same($scenarioResult['scenario_id'],$client->get('/?page=scenarios')['body']['tables'][0]['rows'][0]['id'],'Current planner can find version in history');
    $copyResult = $client->post('freeze-network-plan',['request_key'=>'http-scenario-copy','parent'=>$scenarioResult['scenario_id'],'refresh'=>'0'],$csrf)['body'];
    same($scenarioResult['digest'],$copyResult['digest'],'Workbench can copy a frozen simulation');
    same(2,$copyResult['scenario_rev'],'Derived scenario identifies its parent revision');
    $badSelection = $client->publishPlan($scenarioResult['scenario_id'],['revision'=>1,'selected_line_ids'=>[],
        'horizon_end'=>'2026-12-06','request_key'=>'http-bad-selection'],$csrf);
    same(422,$badSelection['status'],'Publish resource validates selected line IDs');
    same('selected_line_ids',$badSelection['body']['field'],'Invalid publish payload identifies the field to correct');
    same(405,$client->get('/network-plans/'.$scenarioResult['scenario_id'].'/publish')['status'],'GET cannot publish group plan');
    $publishItem = $master->addItem('NET-CAP','Cap','ea','purchase',250,1);
    $publishDock = $master->addLocation('NET-DK','Cap stores');
    $publishDept = $master->createDepartment('NET-DP','Supply planning');
    foreach ([$sourceSite,$destinationSite] as $siteId) {
        $client->post('map-site-item',['site'=>$siteId,'item'=>$publishItem],$csrf);
        $client->post('authorize-site-signer',['site'=>$siteId,'user'=>$adminId,'enabled'=>1],$csrf);
    }
    $client->post('assign-site-ownership',['site'=>$sourceSite,'kind'=>'location','entity'=>$publishDock],$csrf);
    $capLot = (new Inventory($db,$scope))->openingBalance('caps',$publishItem,$publishDock,'NET-CAPS','ea',3000,250,'2027-01-04')['lot_id'];
    $client->uploadForecast("site,item,week_start,quantity\nPACK,NET-CAP,2027-01-04,3",'CAPS','caps-forecast',$csrf);
    $capScenario = $client->post('freeze-network-plan',['request_key'=>'cap-scenario','sites'=>[$sourceSite,$destinationSite],
        'start'=>'2027-01-04','end'=>'2027-01-10'],$csrf)['body'];
    $capLine = $client->get($capScenario['url'])['body']['tables'][1]['rows'][0]['id'];
    $publishFields = ['scenario'=>$capScenario['scenario_id'],'revision'=>1,'selected_line_ids'=>[$capLine],
        'horizon_end'=>'2027-01-10','request_key'=>'http-cap-publish'];
    same(200,$client->post('submit-network-plan',$publishFields,$csrf)['status'],'Submit selected transfer to both plants');
    foreach ([$sourceSite,$destinationSite] as $siteId) {
        same(200,$client->post('decide-network-line',['request_key'=>'cap-sign-'.$siteId,'line'=>$capLine,'site'=>$siteId,'previous'=>0,
            'department'=>$publishDept,'accepted'=>1,'promised_on'=>'2027-01-08','reason'=>'Dock team available'],$csrf)['status'],'Sign assigned plant line');
    }
    $capRelease = $client->publishPlan($capScenario['scenario_id'],$publishFields,$csrf);
    $preview = $client->get($capScenario['url'].'&preview_line='.$capLine.'&shift_days=-5')['body']['details'];
    same('2027-01-05',$preview['试排交期'],'Moving demand five days earlier previews January 5');
    same('2027-01-08',$preview['预计可交日'],'Approved plant commitment bounds the preview');
    same('3',$preview['试排交期缺量'],'Advance exposes three units at risk');
    same(200,$capRelease['status'],'Canonical publish URL accepts confirmed plan');
    same(1,count($capRelease['body']['transfer_ids']),'Canonical publish URL returns actual transfer ID');
    same($capRelease['body']['transfer_ids'],$client->publishPlan($capScenario['scenario_id'],$publishFields,$csrf)['body']['transfer_ids'],'HTTP replay returns the committed transfer');
    $transfer = $capRelease['body']['transfer_ids'][0];
    $arrivals = $master->addLocation('RECEIVING','Receiving dock');
    $client->post('assign-site-ownership',['site'=>$destinationSite,'kind'=>'location','entity'=>$arrivals],$csrf);
    $dispatch = $client->post('dispatch-transfer',['transfer'=>$transfer,'reference'=>'OUT-CAPS','lot'=>$capLot,'location'=>$publishDock,
        'quantity'=>'3','posted_on'=>'2027-01-08','request_key'=>'caps-out'],$csrf);
    same(200,$dispatch['status'],'Warehouse dispatch through normal HTTP action');
    $common = ['transfer'=>$transfer,'shipment'=>$dispatch['body']['shipment_id'],'location'=>$arrivals,'posted_on'=>'2027-01-09'];
    $first = $client->post('receive-transfer',$common+['reference'=>'IN-ONE','request_key'=>'caps-1','expected'=>'2','counted'=>'2'],$csrf);
    same(2000,$first['body']['accepted_milli'],'First partial receipt accepts two');
    $excess = $client->post('receive-transfer',$common+['reference'=>'IN-TWO','request_key'=>'caps-2','expected'=>'1','counted'=>'2','reason'=>'Extra cap in carton'],$csrf);
    same(1000,$excess['body']['accepted_milli'],'Excess piece stays out of available inventory');
    $form = ['transfer'=>$transfer,'variance'=>$excess['body']['variance_id'],'quantity'=>'1','evidence'=>'Carrier receipt RT-1'];
    $returned = $client->post('return-transfer-excess',$form+['request_key'=>'caps-return'],$csrf);
    same(200,$returned['status'],'Over-delivery return recorded');
    same($returned['body']['resolution_id'],$client->post('return-transfer-excess',$form+['request_key'=>'caps-again'],$csrf)['body']['resolution_id'],'Carrier receipt reused without second return');
    $state = $client->get('/?page=transit&id='.$transfer)['body'];
    same(['3','3','0'],[$state['details']['已发'],$state['details']['已收'],$state['details']['在途']],'All three accounted for after two receipts');
    same(['NET-CAPS','NET-CAPS'],array_column($state['tables'][1]['rows'],'shipment_lot'),'Both sublots trace back to the shipment');
    same(0,(int)$state['tables'][2]['rows'][0]['pending_milli'],'No open overage after return');
    same(['Carrier receipt RT-1'],array_column($state['tables'][3]['rows'],'evidence'),'Visible carrier receipt and a single disposition');

    $oldPin = $master->addItem('PIN-OLD','First generation pin','ea','purchase',260,0);
    $newPin = $master->addItem('PIN-NEW','Approved replacement pin','ea','purchase',280,0);
    $powder = $master->addItem('COATING','Coating powder','kg','purchase',200,0);
    foreach ([[$sourceSite,$newPin],[$sourceSite,$powder],[$destinationSite,$oldPin]] as [$site,$item]) {
        same(200,$client->post('map-site-item',['site'=>$site,'item'=>$item],$csrf)['status'],'Set up stock and demand codes at their own plants');
    }
    $altLot = (new Inventory($db,$scope))->openingBalance('pins',$newPin,$publishDock,'PIN-BATCH-2','ea',5000,280,'2027-04-05')['lot_id'];
    $authorization = ['lane'=>$lane,'source_item'=>$newPin,'item'=>$oldPin,'enabled'=>1,'revision'=>0,'evidence'=>'Engineering certificate EC-2'];
    same(422,$client->post('qualify-transfer-item',array_replace($authorization,['source_item'=>$powder,'request_key'=>'wrong-unit']),$csrf)['status'],'Mass cannot qualify as a one-for-one replacement for pieces');
    same(200,$client->post('qualify-transfer-item',$authorization+['request_key'=>'qualify'],$csrf)['status'],'Receive-side approval of a particular source code and lane');
    $client->uploadForecast("site,item,week_start,quantity\nPACK,PIN-OLD,2027-04-05,5",'PIN-W14','pin-forecast',$csrf);
    $window = ['sites'=>[$sourceSite,$destinationSite],'start'=>'2027-04-05','end'=>'2027-04-11'];
    $plan = $client->post('freeze-network-plan',$window+['request_key'=>'qualified-plan'],$csrf)['body'];
    $suggestion = $client->get($plan['url'])['body']['tables'][1]['rows'][0];
    same([$newPin,$oldPin,5000],[$suggestion['source_item_id'],$suggestion['item_id'],$suggestion['qty_milli']],'Demand and supply keep distinct material identifiers');
    $fields = ['scenario'=>$plan['scenario_id'],'revision'=>1,'horizon_end'=>$window['end'],'selected_line_ids'=>[$suggestion['id']]];
    $client->post('submit-network-plan',$fields+['request_key'=>'pin-submit'],$csrf);
    foreach ([$sourceSite,$destinationSite] as $site) {
        $client->post('decide-network-line',['request_key'=>'pin-confirm-'.$site,'line'=>$suggestion['id'],'site'=>$site,'previous'=>0,
            'department'=>$publishDept,'accepted'=>1,'promised_on'=>'2027-04-09','reason'=>'Engineering approval checked'],$csrf);
    }
    $client->post('qualify-transfer-item',array_replace($authorization,['enabled'=>0,'revision'=>1,'request_key'=>'withdraw']),$csrf);
    same(409,$client->publishPlan($plan['scenario_id'],$fields+['request_key'=>'stale-qualification'],$csrf)['status'],'Revoking qualification invalidates unpublished suggestions');
    $disabled = $client->post('freeze-network-plan',$window+['request_key'=>'disabled'],$csrf)['body'];
    same('uncovered',$client->get($disabled['url'])['body']['tables'][1]['rows'][0]['kind'],'Withdrawn replacement cannot supply new demand');
    $client->post('qualify-transfer-item',array_replace($authorization,['revision'=>2,'request_key'=>'requalify']),$csrf);
    $plan = $client->post('freeze-network-plan',$window+['request_key'=>'refreeze'],$csrf)['body'];
    $suggestion = $client->get($plan['url'])['body']['tables'][1]['rows'][0];
    $fields = array_replace($fields,['scenario'=>$plan['scenario_id'],'selected_line_ids'=>[$suggestion['id']]]);
    $client->post('submit-network-plan',$fields+['request_key'=>'pin-submit-2'],$csrf);
    foreach ([$sourceSite,$destinationSite] as $site) {
        $client->post('decide-network-line',['request_key'=>'pin-sign-'.$site,'line'=>$suggestion['id'],'site'=>$site,'previous'=>0,
            'department'=>$publishDept,'accepted'=>1,'promised_on'=>'2027-04-09','reason'=>'New revision checked'],$csrf);
    }
    $order = $client->publishPlan($plan['scenario_id'],$fields+['request_key'=>'pin-release'],$csrf)['body']['transfer_ids'][0];
    $sent = $client->post('dispatch-transfer',['transfer'=>$order,'reference'=>'PIN-DN','lot'=>$altLot,'location'=>$publishDock,
        'quantity'=>'5','posted_on'=>'2027-04-09','request_key'=>'pins-out'],$csrf)['body'];
    $accepted = $client->post('receive-transfer',['transfer'=>$order,'shipment'=>$sent['shipment_id'],'reference'=>'PIN-GR','location'=>$arrivals,
        'expected'=>'5','counted'=>'5','posted_on'=>'2027-04-10','request_key'=>'pins-in'],$csrf)['body'];
    same($oldPin,$db->row('SELECT item_id FROM stock_lots WHERE id=?',[$accepted['destination_lot_id']])['item_id'],'Receipt booked under the destination approved equivalent');
    $trace = $client->get('/?page=transit&id='.$order)['body'];
    same(['PIN-NEW','PIN-OLD','PIN-BATCH-2'],[$trace['details']['起运料号'],$trace['details']['物料'],$trace['tables'][1]['rows'][0]['shipment_lot']],'Transfer traces the physical source label and both material codes');
    same(0,$client->post('freeze-network-plan',$window+['request_key'=>'after-pin-receipt'],$csrf)['body']['line_count'],'Received equivalent covers the original five units of demand once');
    $cleared = $client->post('clear-transfer',['transfer'=>$order,'posted_on'=>'2027-04-12','evidence'=>'April physical count','request_key'=>'pins-closed'],$csrf);
    same('已对清',$cleared['body']['status'],'Finance can sign off from the normal workbench');
    same('April physical count',$client->get('/?page=transit&id='.$order)['body']['details']['核对依据'],'Group signoff evidence visible on original transfer');
}
