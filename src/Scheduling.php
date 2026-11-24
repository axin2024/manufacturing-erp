<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;
use DateTimeZone;

final class Scheduling extends Service
{
    public function addMachine(int $center, string $code, string $name): int
    {
        $code = Value::code($code, '机台编码');
        $name = Value::text($name, 140, '机台名称');
        return $this->mutate(function (int $org) use ($center, $code, $name): int {
            $this->center($center);
            Value::assert($this->db->row('SELECT id FROM machines WHERE org_id=? AND code=?', [$org, $code]) === null, '机台编码已使用。', 409);
            $id = $this->db->insert('INSERT INTO machines (org_id,center_id,code,name) VALUES (?,?,?,?)', [$org, $center, $code, $name]);
            $this->audit('capacity.machine_created', $id, ['center_id' => $center, 'code' => $code]);
            return $id;
        }, 'planner');
    }

    public function addShift(int $center, string $start, string $end): int
    {
        $this->checkInterval($start, $end);
        return $this->mutate(function (int $org) use ($center, $start, $end): int {
            $this->center($center);
            $overlap = $this->db->row('SELECT id FROM center_shifts WHERE org_id=? AND center_id=? AND starts_at<? AND ends_at>? LIMIT 1',
                [$org, $center, $end, $start]);
            Value::assert($overlap === null, '此工作中心已有交叠的班次。', 409);
            $id = $this->db->insert('INSERT INTO center_shifts (org_id,center_id,starts_at,ends_at) VALUES (?,?,?,?)',
                [$org, $center, $start, $end]);
            $this->audit('capacity.shift_created', $id, ['center_id' => $center, 'starts_at' => $start, 'ends_at' => $end]);
            return $id;
        }, 'planner');
    }

    public function book(string $key, int $operation, int $machine, string $start, string $end): array
    {
        $seconds = $this->checkInterval($start, $end);
        return $this->mutate(fn(int $org): array => $this->replay('capacity.book', $key, [$operation, $machine, $start, $end],
            function () use ($org, $operation, $machine, $start, $end, $seconds): array {
                $step = $this->db->row('SELECT p.*,w.state,w.starts_on,w.due_on FROM work_operations p JOIN work_orders w ON w.id=p.work_id
                    WHERE p.org_id=? AND p.id=?', [$org, $operation]);
                Value::assert($step !== null, '工序不存在。', 404);
                $equipment = $this->db->row('SELECT center_id,code FROM machines WHERE org_id=? AND id=?', [$org, $machine]);
                Value::assert($equipment !== null && (int) $equipment['center_id'] === (int) $step['center_id'], '机台不在工序指定的工作中心。', 409);
                Value::assert(in_array($step['state'], ['approved', 'released'], true), '请在工单审核后、开工前排程。', 409);
                $limit = (new DateTimeImmutable($step['due_on'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
                Value::assert($seconds >= (int) $step['standard_seconds'] && $start >= $step['starts_on'] . ' 00:00:00' && $end <= $limit,
                    '时段需要覆盖标准工时并落在工单计划区间内。', 409);
                $shift = $this->db->row('SELECT id FROM center_shifts WHERE org_id=? AND center_id=? AND starts_at<=? AND ends_at>=? LIMIT 1',
                    [$org, $step['center_id'], $start, $end]);
                Value::assert($shift !== null, '工序时段须落在工作中心的一个开放班次内。', 409);
                $collision = $this->db->row('SELECT w.number FROM operation_slots s JOIN work_operations p ON p.id=s.operation_id
                    JOIN work_orders w ON w.id=p.work_id WHERE s.org_id=? AND s.machine_id=? AND s.active=1
                    AND s.operation_id<>? AND s.starts_at<? AND s.ends_at>? LIMIT 1', [$org, $machine, $operation, $end, $start]);
                Value::assert($collision === null, '排程冲突，机台已分配给工单 ' . ($collision['number'] ?? '') . '。', 409);
                $orderConflict = $this->db->row('SELECT p.sequence_no FROM operation_slots s JOIN work_operations p ON p.id=s.operation_id
                    WHERE s.org_id=? AND p.work_id=? AND s.active=1
                    AND ((p.sequence_no<? AND s.ends_at>?) OR (p.sequence_no>? AND s.starts_at<?)) LIMIT 1',
                    [$org, $step['work_id'], $step['sequence_no'], $start, $step['sequence_no'], $end]);
                Value::assert($orderConflict === null, '排程与本工单的工序先后顺序冲突。', 409);
                $this->db->run('UPDATE operation_slots SET active=0 WHERE org_id=? AND operation_id=? AND active=1', [$org, $operation]);
                $id = $this->db->insert('INSERT INTO operation_slots (org_id,operation_id,machine_id,starts_at,ends_at,actor_id) VALUES (?,?,?,?,?,?)',
                    [$org, $operation, $machine, $start, $end, $this->scope->user]);
                $this->audit('capacity.booked', $id, ['operation_id' => $operation, 'machine_id' => $machine, 'seconds' => $seconds]);
                return ['slot_id' => $id, 'operation_id' => $operation, 'machine_id' => $machine];
            }), 'planner');
    }

    public function calendar(string $from, string $to): array
    {
        $this->requireRole('planner', 'production');
        Value::assert(Value::timestamp($from) < Value::timestamp($to), '查询开始时间须早于结束时间。');
        return $this->db->rows('SELECT s.id,s.starts_at,s.ends_at,m.code AS machine,w.number,p.label,p.standard_seconds
            FROM operation_slots s JOIN machines m ON m.id=s.machine_id JOIN work_operations p ON p.id=s.operation_id
            JOIN work_orders w ON w.id=p.work_id WHERE s.org_id=? AND s.active=1 AND s.starts_at<? AND s.ends_at>?
            ORDER BY s.starts_at,s.machine_id,s.id', [$this->scope->org, $to, $from]);
    }

    public function resources(): array
    {
        $this->requireRole('planner', 'production');
        $org = $this->scope->org;
        return [
            'centers' => $this->db->rows('SELECT id,code,name FROM work_centers WHERE org_id=? ORDER BY code', [$org]),
            'machines' => $this->db->rows('SELECT id,center_id,code,name FROM machines WHERE org_id=? ORDER BY code', [$org]),
        ];
    }

    private function center(int $center): void
    {
        Value::assert($this->db->row('SELECT id FROM work_centers WHERE org_id=? AND id=?', [$this->scope->org, $center]) !== null,
            '工作中心不存在。', 404);
    }

    private function checkInterval(string $start, string $end): int
    {
        Value::assert(Value::timestamp($start) < Value::timestamp($end), '时段开始时间须早于结束时间。');
        $utc = new DateTimeZone('UTC');
        $seconds = (new DateTimeImmutable($end, $utc))->getTimestamp() - (new DateTimeImmutable($start, $utc))->getTimestamp();
        Value::assert($seconds <= 86_400, '单个班次或机台时段不能超过 24 小时。');
        return $seconds;
    }
}
