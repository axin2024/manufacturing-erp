<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class FactoryOwnership extends Service
{
    private const SOURCES = [
        'location' => ['locations', 'network_location_sites', 'location_id'],
        'work' => ['work_orders', 'network_work_sites', 'work_id'],
    ];

    private function source(string $kind): array
    {
        Value::assert(isset(self::SOURCES[$kind]), '归属类型须为库位或工单。');
        return self::SOURCES[$kind];
    }

    public function assign(string $kind, int $entity, int $site): void
    {
        $this->mutate(fn(int $org) => $this->bind($org, $kind, $entity, $site), 'admin');
    }

    private function bind(int $org, string $kind, int $entity, int $site): void
    {
        [$source, $target, $column] = $this->source($kind);
        $record = $this->db->row("SELECT e.* FROM $source e JOIN network_sites s ON s.org_id=e.org_id
            WHERE e.org_id=? AND e.id=? AND s.id=?", [$org, $entity, $site]);
        Value::assert($record !== null, '此法人账套没有该工厂或记录。', 404);
        $existing = $this->db->row("SELECT site_id FROM $target WHERE org_id=? AND $column=?", [$org, $entity]);
        if ($existing !== null) {
            Value::assert((int) $existing['site_id'] === $site, '已有工厂归属。', 409);
            return;
        }
        if ($kind === 'work') {
            Value::assert($this->db->row('SELECT item_id FROM network_site_items WHERE site_id=? AND item_id=?',
                [$site, $record['item_id']]) !== null, '工厂尚未获得此工单的物料资格。', 409);
        }
        $this->db->run("INSERT INTO $target (org_id,$column,site_id) VALUES (?,?,?)", [$org, $entity, $site]);
        $this->db->run('UPDATE network_ownership_issues SET resolved_at=UTC_TIMESTAMP(6),resolved_by=?,site_id=?
            WHERE org_id=? AND kind=? AND entity_id=? AND resolved_at IS NULL', [$this->scope->user, $site, $org, $kind, $entity]);
        $this->audit('network.ownership.assigned', $entity, ['kind'=>$kind, 'site_id'=>$site]);
    }

    public function backfill(string $key, string $kind, int $limit): array
    {
        [$source, $target, $column] = $this->source($kind);
        Value::assert($limit >= 1 && $limit <= 500, '每批扫描 1 至 500 条。');
        return $this->mutate(fn(int $org): array => $this->replay('network.ownership', $key, [$kind,$limit],
            function () use ($org, $kind, $source, $target, $column, $limit): array {
                $cursor = (int) ($this->db->row('SELECT last_id FROM network_ownership_cursors WHERE org_id=? AND kind=?',
                    [$org,$kind])['last_id'] ?? 0);
                $rows = $this->db->rows("SELECT e.*,m.site_id FROM $source e LEFT JOIN $target m ON m.org_id=e.org_id AND m.$column=e.id
                    WHERE e.org_id=? AND e.id>? ORDER BY e.id LIMIT $limit", [$org,$cursor]);
                $sites = $this->db->rows('SELECT id FROM network_sites WHERE org_id=? ORDER BY id LIMIT 2', [$org]);
                $assigned = $conflicts = 0;
                foreach ($rows as $row) {
                    $cursor = (int) $row['id'];
                    if ($row['site_id'] !== null) {
                        continue;
                    }
                    $reason = match (count($sites)) { 0=>'no_site', 1=>null, default=>'ambiguous' };
                    if ($reason === null && $kind === 'work' && $this->db->row(
                        'SELECT item_id FROM network_site_items WHERE site_id=? AND item_id=?', [$sites[0]['id'],$row['item_id']]) === null) {
                        $reason = 'item_unmapped';
                    }
                    if ($reason === null) {
                        $this->bind($org, $kind, $cursor, (int) $sites[0]['id']);
                        ++$assigned;
                    } else {
                        $this->db->run('INSERT INTO network_ownership_issues (org_id,kind,entity_id,reason) VALUES (?,?,?,?)',
                            [$org,$kind,$cursor,$reason]);
                        ++$conflicts;
                    }
                }
                $this->db->run('INSERT INTO network_ownership_cursors (org_id,kind,last_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_id=?',
                    [$org,$kind,$cursor,$cursor]);
                return ['kind'=>$kind, 'last_id'=>$cursor, 'scanned'=>count($rows), 'assigned'=>$assigned, 'conflicts'=>$conflicts];
            }), 'admin');
    }

    public function report(): array
    {
        $this->requireRole('admin');
        $org = $this->scope->org;
        $balances = $this->db->rows('SELECT i.code,i.base_uom,b.quality,SUM(b.qty_milli) AS total_milli,
            SUM(IF(m.site_id IS NULL,b.qty_milli,0)) AS unresolved_milli,SUM(IF(m.site_id IS NULL,0,b.qty_milli)) AS assigned_milli
            FROM stock_balances b JOIN stock_lots l ON l.id=b.lot_id JOIN items i ON i.id=l.item_id
            LEFT JOIN network_location_sites m ON m.org_id=b.org_id AND m.location_id=b.location_id WHERE b.org_id=?
            GROUP BY i.id,i.code,i.base_uom,b.quality ORDER BY i.code,b.quality', [$org]);
        $counts = [];
        foreach (self::SOURCES as $kind => [$source,$target,$column]) {
            $counts[$kind] = $this->db->row("SELECT COUNT(*) AS total, COUNT(m.site_id) AS assigned,
                COUNT(*)-COUNT(m.site_id) AS unresolved FROM $source e LEFT JOIN $target m ON m.org_id=e.org_id AND m.$column=e.id
                WHERE e.org_id=?", [$org]);
        }
        return ['counts'=>$counts, 'balances'=>$balances,
            'issues'=>$this->db->rows('SELECT kind,entity_id,reason FROM network_ownership_issues
                WHERE org_id=? AND resolved_at IS NULL ORDER BY kind,entity_id', [$org]),
            'cursors'=>$this->db->rows('SELECT kind,last_id FROM network_ownership_cursors WHERE org_id=? ORDER BY kind', [$org])];
    }
}
