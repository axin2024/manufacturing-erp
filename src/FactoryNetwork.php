<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;
use DateTimeZone;

final class FactoryNetwork extends Service
{
    public function createSite(string $key,string $code,string $name): array
    {
        $code = Value::code($code,'工厂编码');
        $name = trim($name);
        Value::assert((bool)preg_match('/^.{1,160}$/uD',$name),'工厂名称须为 1 至 160 字。');
        return $this->mutate(fn(int $org): array => $this->replay('network.site',$key,[$code,$name],
            function () use ($org,$code,$name): array {
                Value::assert(!$this->db->row('SELECT id FROM network_sites WHERE org_id=? AND code=?',[$org,$code]),'工厂编码已登记。',409);
                $site = $this->db->insert('INSERT INTO network_sites (tenant_id,org_id,code,name) VALUES (?,?,?,?)',
                    [$this->scope->tenant,$org,$code,$name]);
                $this->audit('network.site.created',$site,['code'=>$code]);
                return ['site_id'=>$site];
            }),'admin');
    }

    public function mapItem(int $site,int $item): void
    {
        $this->mutate(function (int $org) use ($site,$item): void {
            $this->ownSite($org,$site);
            $this->organizationItem($item);
            $this->db->run('INSERT INTO network_site_items (org_id,site_id,item_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE site_id=site_id',
                [$org,$site,$item]);
            $this->audit('network.item.mapped',$site,['item_id'=>$item]);
        },'admin','planner');
    }

    public function setCalendar(int $site,string $date,bool $working): void
    {
        Value::day($date);
        $this->mutate(function (int $org) use ($site,$date,$working): void {
            $this->ownSite($org,$site);
            $this->db->run('INSERT INTO network_calendar (site_id,work_date,working) VALUES (?,?,?) ON DUPLICATE KEY UPDATE working=?',
                [$site,$date,(int)$working,(int)$working]);
            $this->audit('network.calendar.changed',$site,['date'=>$date,'working'=>$working]);
        },'admin','planner');
    }

    public function saveLane(string $key,int $source,int $destination,int $days,int $revision): array
    {
        Value::assert($source!==$destination && $days>=0 && $days<=365,'请核对起讫工厂和运输工作日。');
        return $this->mutate(function (int $org) use ($key,$source,$destination,$days,$revision): array {
            $this->ownSite($org,$source);
            $target = $this->db->row('SELECT org_id FROM network_sites WHERE tenant_id=? AND id=?',[$this->scope->tenant,$destination]);
            Value::assert($target!==null,'目标工厂不可见。',404);
            $scope = (new Identity($this->db))->scope($this->scope->user,(int)$target['org_id']);
            Value::assert($scope->can('planner'),'线路维护需要目标法人的计划权限。',403);
            return $this->replay('network.lane',$key,[$source,$destination,$days,$revision],function () use ($source,$destination,$days,$revision): array {
                $row = $this->db->row('SELECT id,revision FROM network_lanes WHERE source_site=? AND destination_site=? FOR UPDATE',[$source,$destination]);
                Value::assert($revision===(int)($row['revision'] ?? 0),'线路版本已变化。',409);
                if ($row) {
                    $id = (int)$row['id'];
                    $this->db->run('UPDATE network_lanes SET lead_days=?,revision=revision+1 WHERE id=?',[$days,$id]);
                } else {
                    $id = $this->db->insert('INSERT INTO network_lanes (tenant_id,source_site,destination_site,lead_days,revision)
                        VALUES (?,?,?,?,1)',[$this->scope->tenant,$source,$destination,$days]);
                }
                $this->audit('network.lane.saved',$id,['revision'=>$revision+1,'days'=>$days]);
                return ['lane_id'=>$id,'revision'=>$revision+1];
            });
        },'admin','planner');
    }

    public function qualify(string $key,int $lane,int $sourceItem,int $item,bool $enabled,int $revision,string $evidence): array
    {
        $evidence = Value::text($evidence,255,'等效依据');
        return $this->mutate(function (int $org) use ($key,$lane,$sourceItem,$item,$enabled,$revision,$evidence): array {
            $path = $this->db->row('SELECT l.source_site,l.destination_site,s.org_id AS source_org FROM network_lanes l
                JOIN network_sites s ON s.id=l.source_site JOIN network_sites d ON d.id=l.destination_site
                WHERE l.id=? AND d.org_id=?',[$lane,$org]);
            Value::assert($path!==null,'当前法人不是该线路的收货方。',404);
            Value::assert((new Identity($this->db))->scope($this->scope->user,$path['source_org'])->can('planner'),'需要发货方计划权限。',403);
            return $this->replay('network.qualification',$key,[$lane,$sourceItem,$item,$enabled,$revision,$evidence],function () use ($path,$lane,$sourceItem,$item,$enabled,$revision,$evidence): array {
                $source = $this->db->row('SELECT i.base_uom FROM network_site_items m JOIN items i ON i.id=m.item_id WHERE m.site_id=? AND m.item_id=?',
                    [$path['source_site'],$sourceItem]);
                $destination = $this->db->row('SELECT i.base_uom FROM network_site_items m JOIN items i ON i.id=m.item_id WHERE m.site_id=? AND m.item_id=?',
                    [$path['destination_site'],$item]);
                Value::assert($sourceItem!==$item && $source!==null && $destination!==null && $source['base_uom']===$destination['base_uom'],
                    '等效资格要求两厂分别启用不同料号，且基础单位相同。');
                $prior = $this->db->row('SELECT revision FROM network_substitutions WHERE lane_id=? AND item_id=? AND source_item_id=?',
                    [$lane,$item,$sourceItem]);
                Value::assert(($prior['revision'] ?? 0)===$revision,'替代资格版本已变更。',409);
                $this->db->run('INSERT INTO network_substitutions (lane_id,source_site,source_item_id,destination_site,item_id,enabled,revision,evidence)
                    VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=?,revision=?,evidence=?',
                    [$lane,$path['source_site'],$sourceItem,$path['destination_site'],$item,(int)$enabled,$revision+1,$evidence,(int)$enabled,$revision+1,$evidence]);
                $result = ['lane_id'=>$lane,'source_item_id'=>$sourceItem,'item_id'=>$item,'enabled'=>$enabled,'revision'=>$revision+1,'evidence'=>$evidence];
                $this->audit('network.qualification.changed',$lane,$result);
                return $result;
            });
        },'planner');
    }

    public function preview(int $lane,int $item,string $departure): array
    {
        $this->requireRole('planner','warehouse');
        Value::day($departure);
        $row = $this->db->row('SELECT l.* FROM network_lanes l JOIN network_sites s ON s.id=l.source_site
            JOIN network_sites d ON d.id=l.destination_site WHERE l.tenant_id=? AND l.id=? AND (s.org_id=? OR d.org_id=?)',
            [$this->scope->tenant,$lane,$this->scope->org,$this->scope->org]);
        Value::assert($row!==null,'线路不可见。',404);
        $overrides = array_column($this->db->rows('SELECT work_date,working FROM network_calendar WHERE site_id=? AND work_date>?
            AND work_date<=DATE_ADD(?,INTERVAL 3 YEAR)',[$row['destination_site'],$departure,$departure]),'working','work_date');
        $arrival = self::arrivalDate($departure, (int)$row['lead_days'], $overrides);
        $gaps = [];
        $qualified = $this->db->rows('SELECT q.source_item_id,i.code,q.evidence,q.revision FROM network_substitutions q
            JOIN items i ON i.id=q.source_item_id WHERE q.lane_id=? AND q.item_id=? AND q.enabled=1 ORDER BY q.source_item_id',[$lane,$item]);
        foreach (['source_site','destination_site'] as $side) {
            if ($side==='source_site' && $qualified) {
                continue;
            }
            if (!$this->db->row('SELECT item_id FROM network_site_items WHERE site_id=? AND item_id=?',[$row[$side],$item])) {
                $gaps[] = ['site_id'=>(int)$row[$side],'item_id'=>$item,'reason'=>'missing_item_mapping'];
            }
        }
        return ['lane_id'=>$lane,'revision'=>(int)$row['revision'],'arrival_date'=>$arrival,'gaps'=>$gaps,'substitutes'=>$qualified];
    }

    public static function arrivalDate(string $departure, int $remaining, array $overrides): string
    {
        $date = new DateTimeImmutable($departure,new DateTimeZone('UTC'));
        for ($i=0;$remaining>0 && $i<1095;$i++) {
            $date = $date->modify('+1 day');
            $working = $overrides[$date->format('Y-m-d')] ?? ((int)$date->format('N')<=5);
            if ($working) {
                $remaining--;
            }
        }
        Value::assert($remaining===0,'三年内没有足够的目的厂工作日。',409);
        return $date->format('Y-m-d');
    }

    private function ownSite(int $org,int $site): void
    {
        Value::assert($this->db->row('SELECT id FROM network_sites WHERE org_id=? AND id=?',[$org,$site])!==null,'本厂账套中没有此工厂。',404);
    }
}
