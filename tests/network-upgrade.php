<?php

declare(strict_types=1);

use ManufacturingErp\{Database,FactoryNetwork,FactoryOwnership,Identity,NetworkPreflight,Value};

require __DIR__.'/bootstrap.php';

$prior = Value::fromJson(file_get_contents($argv[1]));
same(false,$prior['ownership_schema'],'Snapshot taken before installing any factory ownership tables');
$db = Database::connect();
$identity = new Identity($db);
$operator = $db->row('SELECT id FROM users WHERE email=?',['upgrade@example.test'])['id'];
$audit = new NetworkPreflight($db);
$postDdl = $audit->inspect($prior['tenant_id']);
same(true,$postDdl['ownership_schema'],'New schema installed');
same(array_column($prior['organizations'],'ledger'),array_column($postDdl['organizations'],'ledger'),'DDL preserves all old business entries');
$events = [];
foreach ($prior['organizations'] as $org) {
    $scope = $identity->scope($operator,$org['id']);
    $network = new FactoryNetwork($db,$scope);
    $owners = new FactoryOwnership($db,$scope);
    $plant = $network->createSite('original','ORIGINAL','Original production site')['site_id'];
    $network->createSite('second','SECOND','Expansion site');
    foreach ($db->rows('SELECT item_id FROM org_items WHERE org_id=?',[$scope->org]) as $item) {
        $network->mapItem($plant,$item['item_id']);
    }
    foreach ($org['ownership'] as $kind=>$baseline) {
        $cursor = 0;
        $unassigned = [];
        do {
            $key = 'page-'.$kind.'-'.$cursor;
            $page = $owners->backfill($key,$kind,1);
            $repeat = $owners->backfill($key,$kind,1);
            same($page['last_id'],$repeat['last_id'],'Lost batch acknowledgement returns the same cursor');
            if ($page['scanned']) {
                same(1,$page['conflicts'],'Ambiguous original owner requires a decision');
                $cursor = $page['last_id'];
                $unassigned[] = $cursor;
            }
        } while ($page['scanned']);
        same(array_column($baseline['pending'],'id'),$unassigned,'Every original record appears once in the conflict list');
        foreach ($unassigned as $id) {
            $owners->assign($kind,$id,$plant);
        }
        $events[] = ['org_id'=>$scope->org,'kind'=>$kind,'assigned_ids'=>$unassigned,'last_id'=>$cursor];
    }
    same([],$owners->report()['issues'],'All ownership exceptions resolved');
}
$final = $audit->inspect($prior['tenant_id']);
same(array_column($prior['organizations'],'ledger'),array_column($final['organizations'],'ledger'),'Original business data identical after all assignments');
foreach ($final['organizations'] as $org) {
    foreach ($org['ownership'] as $owner) {
        same($owner['total'],$owner['assigned'],'No legacy records left behind');
    }
}
$ledger = $final['organizations'][0]['ledger'];
foreach (['work_orders','stock_balances','stock_lots','stock_movements','journal_lines'] as $table) {
    same(true,$ledger[$table]['rows']>0,'Historical fixture contains '.$table);
}
same(true,(float)$ledger['carrying_minor']>0,'Historical lot valuation is populated');
echo json_encode(['from_release'=>'v1.1.0','before'=>$prior,'after'=>$final,'backfill'=>$events,
    'verified_assertions'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
