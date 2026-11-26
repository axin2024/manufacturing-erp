<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class PlantConfirmations extends Service
{
    public function authorize(int $site, int $user, bool $enabled): void
    {
        $this->mutate(function (int $org) use ($site,$user,$enabled): void {
            Value::assert($this->db->row('SELECT id FROM network_sites WHERE org_id=? AND id=?',[$org,$site]) !== null,'工厂不存在。',404);
            if ($enabled) {
                $member = (new Identity($this->db))->scope($user,$org);
                Value::assert($member->can('planner','production'),'签认人需要本法人的计划或车间岗位。',409);
            }
            $this->db->run('INSERT INTO network_site_signers (org_id,site_id,user_id,enabled) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE enabled=?',[$org,$site,$user,(int)$enabled,(int)$enabled]);
            $this->audit('network.signer.changed',$site,['user'=>$user,'enabled'=>$enabled]);
        },'admin');
    }

    public static function selected(array $values): array
    {
        Value::assert(array_is_list($values) && count($values)>=1 && count($values)<=1000,'selected_line_ids 须选择 1 至 1000 行。',422,'selected_line_ids');
        $ids = [];
        foreach ($values as $index=>$value) {
            $ids[] = WebInput::integer($value,'selected_line_ids.'.$index);
        }
        Value::assert(count(array_unique($ids))===count($ids),'selected_line_ids 中含重复行。',422,'selected_line_ids');
        sort($ids,SORT_NUMERIC);
        return $ids;
    }

    public function submit(string $key, int $scenario, int $revision, array $values): array
    {
        $ids = self::selected($values);
        return $this->mutate(function () use ($key,$scenario,$revision,$ids): array {
            $plan = (new GroupScenarios($this->db,$this->scope))->detail($scenario);
            Value::assert((int)$plan['revision']===$revision,'待提交的情景版本已经变化。',409);
            return $this->replay('network.submit',$key,[$scenario,$revision,$ids],function () use ($plan,$scenario,$ids): array {
                $lines = array_column($plan['lines'],null,'id');
                foreach ($ids as $id) {
                    Value::assert(isset($lines[$id]),'selected_line_ids 含不属于此情景的行。',422);
                    Value::assert($lines[$id]['kind']!=='uncovered','未找到可执行供应路径。',409);
                    if (!$this->db->row('SELECT line_id FROM network_plan_submissions WHERE line_id=?',[$id])) {
                        (new NetworkPilot($this->db,$this->scope))->admit();
                    }
                    $this->db->run('INSERT INTO network_plan_submissions (scenario_id,line_id,actor_id) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE line_id=network_plan_submissions.line_id',[$scenario,$id,$this->scope->user]);
                }
                $this->audit('network.plan.submitted',$scenario,['selected_line_ids'=>$ids]);
                return ['scenario_id'=>$scenario,'selected_line_ids'=>$ids];
            });
        },'planner');
    }

    public function decide(string $key,int $line,int $site,int $previous,int $department,bool $accept,string $promise,string $reason): array
    {
        $promise = Value::day($promise);
        $reason = Value::text($reason,255,'签认说明');
        return $this->mutate(function (int $org) use ($key,$line,$site,$previous,$department,$accept,$promise,$reason): array {
            $row = $this->db->row('SELECT l.id,l.scenario_id,n.as_of FROM network_plan_lines l
                JOIN network_plan_submissions p ON p.line_id=l.id JOIN network_scenarios n ON n.id=l.scenario_id
                JOIN network_site_signers g ON g.site_id IN (l.source_site,l.destination_site)
                WHERE g.org_id=? AND g.site_id=? AND g.user_id=? AND g.enabled=1 AND l.id=?',
                [$org,$site,$this->scope->user,$line]);
            Value::assert($row!==null,'本厂没有此待办行。',404);
            Value::assert($this->db->row('SELECT id FROM departments WHERE org_id=? AND id=?',[$org,$department])!==null,'责任中心不在当前法人。',404);
            Value::assert($promise >= $row['as_of'],'承诺日期不能早于计划基准。');
            return $this->replay('network.decide',$key,[$line,$site,$previous,$department,$accept,$promise,$reason],
                function () use ($org,$line,$site,$previous,$department,$accept,$promise,$reason): array {
                    Value::assert($this->db->row('SELECT line_id FROM network_plan_executions WHERE line_id=?',[$line])===null,
                        '此建议已发布，不能再更改签认。',409);
                    $last = $this->db->row('SELECT id FROM network_plan_decisions WHERE line_id=? AND site_id=? ORDER BY id DESC LIMIT 1',[$line,$site]);
                    Value::assert((int)($last['id'] ?? 0)===$previous,'签认已变更，请刷新。',409);
                    $id = $this->db->insert('INSERT INTO network_plan_decisions (line_id,site_id,org_id,department_id,accepted,promised_on,reason,actor_id)
                        VALUES (?,?,?,?,?,?,?,?)',[$line,$site,$org,$department,(int)$accept,$promise,$reason,$this->scope->user]);
                    $this->audit('network.plan.decided',$line,['approval_id'=>$id,'site_id'=>$site,'accepted'=>$accept]);
                    return ['approval_id'=>$id,'line_id'=>$line,'site_id'=>$site,'accepted'=>$accept,'promised_on'=>$promise,'reason'=>$reason];
                });
        },'planner','production');
    }

    public function inbox(int $before = PHP_INT_MAX): array
    {
        $this->requireRole('planner','production');
        return $this->db->rows("SELECT l.id AS line_id,s.code AS site,g.site_id,l.scenario_id,l.item_id,l.kind,l.qty_milli,l.needed_on,
            d.id AS approval_id,d.accepted,d.reason,d.promised_on FROM network_plan_submissions p JOIN network_plan_lines l ON l.id=p.line_id
            JOIN network_site_signers g ON g.site_id IN (l.source_site,l.destination_site) JOIN network_sites s ON s.id=g.site_id
            LEFT JOIN network_plan_decisions d ON d.id=(SELECT MAX(a.id) FROM network_plan_decisions a WHERE a.line_id=l.id AND a.site_id=g.site_id)
            WHERE g.org_id=? AND g.user_id=? AND g.enabled=1 AND l.id<? ORDER BY l.id DESC,g.site_id",
            [$this->scope->org,$this->scope->user,$before]);
    }
}
