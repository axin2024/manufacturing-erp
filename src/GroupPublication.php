<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class GroupPublication extends Service
{
    public function publish(string $key,int $scenario,int $revision,array $selection,string $horizon): array
    {
        $this->requireRole('admin');
        $ids = PlantConfirmations::selected($selection);
        Value::day($horizon,'horizon_end');
        return $this->db->transaction(function () use ($key,$scenario,$revision,$ids,$horizon): array {
            $plans = new GroupScenarios($this->db,$this->scope);
            $plan = $plans->detail($scenario);
            $sites = (new NetworkSnapshot($this->db,$this->scope))->lockSites($plan['sites']);
            $actor = (new Identity($this->db))->scope($this->scope->user,$this->scope->org);
            Value::assert($actor->can('admin'),'集团发布需要主管权限。',403);
            return $this->replay('network.publish',$key,[$scenario,$revision,$ids,$horizon],
                function () use ($scenario,$revision,$ids,$horizon,$plans,$sites,$plan): array {
                    Value::assert($plan['revision']===$revision && $plan['horizon_end']===$horizon,'revision 或 horizon_end 与情景不符。',409);
                    $lines = array_column($plan['lines'],null,'id');
                    $bySite = array_column($sites,null,'id');
                    $result = ['scenario_id'=>$scenario,'revision'=>$revision,'selected_line_ids'=>$ids,'transfer_ids'=>[], 'production_ids'=>[], 'blocked_lines'=>[]];
                    $fresh = false;
                    foreach ($ids as $id) {
                        Value::assert(isset($lines[$id]),'selected_line_ids 中的建议不属于此情景。',422,'selected_line_ids');
                        $line = $lines[$id];
                        $prior = $this->db->row('SELECT transfer_id,work_id FROM network_plan_executions WHERE line_id=?',[$id]);
                        if ($prior !== null) {
                            $this->append($result,$prior);
                            continue;
                        }
                        if (!$fresh) {
                            try {
                                $plans->verifyForPublish($scenario,$revision,$horizon);
                            } catch (BusinessError $error) {
                                if ($error->status === 409) {
                                    throw new BusinessError($error->getMessage().' selected_line_ids='.implode(',',$ids),409);
                                }
                                throw $error;
                            }
                            $fresh = true;
                        }
                        $decision = $this->confirmations($line);
                        if ($decision['blocked'] !== []) {
                            $result['blocked_lines'][] = ['line_id'=>$id,'sites'=>$decision['blocked']];
                            continue;
                        }
                        $execution = ['transfer_id'=>null,'work_id'=>null];
                        $date = max($decision['promise'],$line['promised_on']);
                        if ($date>$horizon) {
                            $result['blocked_lines'][] = ['line_id'=>$id,'reason'=>'promise_outside_horizon','promised_on'=>$date];
                            continue;
                        }
                        if ($line['kind']==='transfer') {
                            $execution['transfer_id'] = $this->db->insert('INSERT INTO network_transfer_orders (line_id,tenant_id,source_site,
                                destination_site,item_id,source_item_id,ordered_milli,promised_on,unit_price_minor,currency) VALUES (?,?,?,?,?,?,?,?,?,?)',
                                [$id,$this->scope->tenant,$line['source_site'],$line['destination_site'],$line['item_id'],$line['source_item_id'],$line['qty_milli'],
                                    $date,$line['unit_cost_minor'],$this->scope->currency]);
                        } elseif (in_array($line['kind'],['manufacture','subcontract'],true)) {
                            $org = $bySite[$line['destination_site']]['org_id'];
                            $target = (new Identity($this->db))->scope($this->scope->user,$org);
                            $work = (new WorkOrders($this->db,$target))->create('group-line-'.$id,'GP-'.$scenario.'-'.$id,
                                $line['item_id'],$line['qty_milli'],$plan['as_of'],$plan['as_of'],$date);
                            $execution['work_id'] = $work['work_id'];
                            $this->db->run('INSERT INTO network_work_sites (org_id,work_id,site_id) VALUES (?,?,?)',
                                [$org,$work['work_id'],$line['destination_site']]);
                        } else {
                            $result['blocked_lines'][] = ['line_id'=>$id,'reason'=>'no_supply_path'];
                            continue;
                        }
                        $this->db->run('INSERT INTO network_plan_executions (line_id,transfer_id,work_id,actor_id) VALUES (?,?,?,?)',
                            [$id,$execution['transfer_id'],$execution['work_id'],$this->scope->user]);
                        $this->append($result,$execution);
                    }
                    $this->audit('network.plan.published',$scenario,$result+['selected_line_ids'=>$ids,'revision'=>$revision]);
                    return $result;
                });
        });
    }

    private function confirmations(array $line): array
    {
        $blocked = [];
        $promise = '';
        foreach (array_unique([$line['source_site'],$line['destination_site']]) as $site) {
            $decision = $this->db->row('SELECT id,accepted,reason,promised_on FROM network_plan_decisions
                WHERE line_id=? AND site_id=? ORDER BY id DESC LIMIT 1',[$line['id'],$site]);
            if (!$decision || !$decision['accepted']) {
                $blocked[] = ['site_id'=>$site,'approval_id'=>$decision['id'] ?? null,'reason'=>$decision['reason'] ?? 'awaiting_confirmation'];
            } else {
                $promise = max($promise,$decision['promised_on']);
            }
        }
        return compact('blocked','promise');
    }

    private function append(array &$result,array $execution): void
    {
        if ($execution['transfer_id'] !== null) {
            $result['transfer_ids'][] = (int) $execution['transfer_id'];
        } else {
            $result['production_ids'][] = (int) $execution['work_id'];
        }
    }
}
