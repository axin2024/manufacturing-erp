<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class NetworkPilot extends Service
{
    public function current(): array
    {
        return $this->db->row('SELECT state,revision FROM network_pilots WHERE tenant_id=?',[$this->scope->tenant])
            ?? ['state'=>'setup','revision'=>0];
    }

    /** Intake holds its organization lock. State changes lock all participating organizations. */
    public function admit(): void
    {
        Value::assert($this->current()['state']==='active','集团计划尚未开放新请求。',409);
    }

    public function change(string $key,int $revision,string $state): array
    {
        $this->requireRole('admin');
        Value::assert(in_array($state,['active','draining'],true),'试点状态无效。');
        return $this->db->transaction(function () use ($key,$revision,$state): array {
            $tenant = $this->scope->tenant;
            $orgs = $this->db->rows('SELECT id FROM organizations WHERE tenant_id=? ORDER BY id FOR UPDATE',[$tenant]);
            $this->db->row('SELECT id FROM tenants WHERE id=? FOR UPDATE',[$tenant]);
            Value::assert(count($orgs)===(int)$this->db->row('SELECT COUNT(*) n FROM organizations WHERE tenant_id=?',[$tenant])['n'],
                '法人清单已变化，请重试。',409);
            $identity = new Identity($this->db);
            foreach ($orgs as $org) {
                Value::assert($identity->scope($this->scope->user,$org['id'])->can('admin'),'本操作需要集团内各法人的管理员权限。',403);
            }
            return $this->replay('network.pilot',$key,[$revision,$state],function () use ($state,$revision,$orgs,$tenant,$key): array {
                $prior = $this->current();
                Value::assert($prior['revision']===$revision,'试点状态已有新版本。',409);
                Value::assert($prior['state']!==$state && !($prior['state']==='setup' && $state==='draining'),'试点状态不能这样切换。',409);
                if ($state==='active') {
                    foreach ($orgs as $org) {
                        $scope = (new Identity($this->db))->scope($this->scope->user,$org['id']);
                        $report = (new FactoryOwnership($this->db,$scope))->report();
                        foreach ($report['counts'] as $kind=>$count) {
                            Value::assert((int)$count['unresolved']===0,'请先核对法人 '.$scope->code.' 的 '.$kind.' 归属。',409);
                        }
                        foreach ($report['balances'] as $balance) {
                            Value::assert($balance['total_milli']===$balance['assigned_milli'],'库存归属数量未配平。',409);
                        }
                    }
                }
                $this->db->run('INSERT INTO network_pilots (tenant_id,state,revision,actor_id,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6))
                    ON DUPLICATE KEY UPDATE state=?,revision=?,actor_id=?,updated_at=UTC_TIMESTAMP(6)',
                    [$tenant,$state,$revision+1,$this->scope->user,$state,$revision+1,$this->scope->user]);
                $this->audit('network.pilot.changed',$tenant,['from'=>$prior['state'],'to'=>$state,'revision'=>$revision+1,'request_key'=>$key]);
                return $this->current();
            });
        });
    }
}
