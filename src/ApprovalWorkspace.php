<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class ApprovalWorkspace extends WorkspaceScreen
{
    private const PAGES = ['plan' => 'planning', 'work' => 'work'];

    public function inbox(string $kind, int $before): array
    {
        $rows = (new ApprovalDelegations($this->db, $this->scope))->inbox($kind, $before);
        $page = self::PAGES[$kind];
        $list = $this->paged($rows, 'id', 'home', null, $kind . '_before');
        return $this->table($kind === 'plan' ? '代办计划审批' : '代办工单审批', [
            'id' => '单据号', 'delegated_by' => '委托人', 'expires_at' => '到期时间（UTC）',
        ], $list['rows'], $page, 'id', $list['next']);
    }

    public function controls(string $kind, int $id, string $hint): array
    {
        $delegations = new ApprovalDelegations($this->db, $this->scope);
        $record = $delegations->record($kind, $id);
        $planner = $this->scope->can('planner');
        $pending = $delegations->isPending($kind, $id);
        $forms = [];
        $details = [];
        if ($record !== null && ($planner || (int) $record['assigned_to'] === $this->scope->user)) {
            $details = ['审批委托' => $record['assigner_name'] . ' → ' . $record['delegate_name'],
                '委托到期（UTC）' => $record['expires_at'], '委托状态' => match ($record['state']) {
                    'revoked' => '已撤销',
                    'completed' => '已代办',
                    default => $record['current_grant'] && $pending ? '待代办' : '已失效',
                }];
        }
        if ($pending) {
            $action = 'approve-' . $kind;
            if ($record !== null && $delegations->availableToActor($kind, $record)) {
                $forms[] = $this->form('代办批准', $action, [], ['id' => $id, 'acting_for' => '1'], $hint);
            }
            if ($planner) {
                $forms[] = $this->form($kind === 'plan' ? '批准计划' : '批准工单', $action, [], ['id' => $id], $hint);
                $candidates = $delegations->candidates($kind);
                if ($candidates !== []) {
                    $forms[] = $this->form('委托审批', 'delegate-approval', [
                        $this->field('delegate_id', '本法人代办人', 'select', ['options' => $this->options($candidates, 'name', 'id')]),
                        $this->field('hours', '有效小时数', 'number', ['min' => 1, 'max' => 168, 'value' => 24]),
                    ], ['id' => $id, 'kind' => $kind]);
                }
                if ($record !== null && $record['state'] === 'pending') {
                    $forms[] = $this->form('撤销审批委托', 'revoke-approval', [], ['id' => $id, 'kind' => $kind]);
                }
            }
        }
        return ['forms' => $forms, 'details' => $details];
    }
}
