<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

final class GroupScenarios extends Service
{
    public function freeze(string $key, array $siteIds, string $start, string $end, ?int $parent = null, bool $refresh = false): array
    {
        $this->requireRole('planner');
        $first = new DateTimeImmutable(Value::day($start));
        Value::assert(Value::day($end) >= $start && $first->diff(new DateTimeImmutable($end))->days < 366, '计划范围须在一年之内。');
        return $this->db->transaction(function () use ($key,$siteIds,$start,$end,$parent,$refresh): array {
            $reader = new NetworkSnapshot($this->db, $this->scope);
            $sites = $reader->lockSites($siteIds);
            $ids = array_column($sites,'id');
            return $this->replay('network.scenario', $key, [$ids,$start,$end,$parent,$refresh],
                function () use ($sites,$ids,$start,$end,$parent,$refresh,$reader): array {
                    (new NetworkPilot($this->db,$this->scope))->admit();
                    $revision = 1;
                    $snapshot = null;
                    if ($parent !== null) {
                        $previous = $this->record($parent);
                        $old = Value::fromJson($previous['snapshot']);
                        Value::assert($ids === array_column($old['inputs']['sites'],'id') && $previous['as_of'] === $start && $previous['horizon_end'] === $end,
                            '派生版本须沿用原情景的工厂范围和计划区间。',409);
                        $revision = (int) $previous['revision'] + 1;
                        if (!$refresh) {
                            $snapshot = $old['inputs'];
                        }
                    }
                    $snapshot ??= $reader->capture($sites,$start,$end);
                    $result = NetworkAllocator::calculate($snapshot,$start,$end);
                    $digest = $parent !== null && !$refresh ? $previous['digest'] : hash('sha256',Value::json($snapshot));
                    $scenario = $this->db->insert('INSERT INTO network_scenarios
                        (tenant_id,org_id,parent_id,revision,as_of,horizon_end,snapshot,digest,actor_id) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$this->scope->tenant,$this->scope->org,$parent,$revision,$start,$end,
                            Value::json(['inputs'=>$snapshot,'balances'=>$result['balances']]),$digest,$this->scope->user]);
                    $rows = [];
                    foreach ($result['lines'] as [$source,$dest,$item,$kind,$due,$arrival,$qty,$cost,$alternatives,$sourceItem]) {
                        $rows[] = [$scenario,$source,$dest,$item,$kind,$due,$arrival,$qty,$cost,Value::json($alternatives),$sourceItem];
                    }
                    $this->db->insertRows('INSERT INTO network_plan_lines (scenario_id,source_site,destination_site,item_id,kind,
                        needed_on,promised_on,qty_milli,unit_cost_minor,alternatives,source_item_id)', $rows);
                    $this->audit('network.scenario.frozen',$scenario,['revision'=>$revision,'digest'=>$digest,'line_count'=>count($rows)]);
                    return ['scenario_id'=>$scenario,'scenario_rev'=>$revision,'line_count'=>count($rows),'digest'=>$digest];
                });
        });
    }

    public function detail(int $id): array
    {
        $this->requireRole('planner');
        $row = $this->record($id);
        $snapshot = Value::fromJson($row['snapshot']);
        $identity = new Identity($this->db);
        foreach (array_unique(array_column($snapshot['inputs']['sites'],'org_id')) as $org) {
            Value::assert($identity->scope($this->scope->user,$org)->can('planner'), '跨厂计划权限已变更。', 403);
        }
        unset($row['snapshot']);
        $lines = $this->db->rows('SELECT * FROM network_plan_lines WHERE scenario_id=? ORDER BY id',[$id]);
        foreach ($lines as &$line) {
            $line['alternatives'] = Value::fromJson($line['alternatives']);
        }
        unset($line);
        return $row + ['sites'=>array_column($snapshot['inputs']['sites'],'id'),'balances'=>$snapshot['balances'],'lines'=>$lines];
    }

    /** Run inside the publish transaction, retaining organization locks through order creation. */
    public function verifyForPublish(int $id, int $revision, string $end): array
    {
        $row = $this->record($id);
        Value::assert($revision === (int) $row['revision'] && $end === $row['horizon_end'],'情景版本或计划截止日已经变化。',409);
        $snapshot = Value::fromJson($row['snapshot'])['inputs'];
        $reader = new NetworkSnapshot($this->db,$this->scope);
        $sites = $reader->lockSites(array_column($snapshot['sites'],'id'));
        $fresh = $reader->capture($sites,$row['as_of'],$end,$id);
        Value::assert(hash_equals($row['digest'],hash('sha256',Value::json($fresh))), '供需、工程版本或运输条件已变化，请重新试算。',409);
        return $row;
    }

    private function record(int $id): array
    {
        $row = $this->db->row('SELECT * FROM network_scenarios WHERE org_id=? AND id=?', [$this->scope->org,$id]);
        Value::assert($row !== null,'此账套没有该情景。',404);
        return $row;
    }
}
