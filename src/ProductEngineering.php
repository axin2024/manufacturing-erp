<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class ProductEngineering extends Service
{
    public function publishBom(int $product, string $effective, array $lines): int
    {
        Value::day($effective);
        Value::assert(array_is_list($lines) && count($lines) >= 1 && count($lines) <= 100, 'BOM 须包含 1 至 100 个组件。');
        return $this->mutate(function (int $org) use ($product, $effective, $lines): int {
            $item = $this->organizationItem($product);
            Value::assert($item['sourcing'] !== 'purchase', '外购料无需发布生产 BOM。');
            Value::assert($this->db->row('SELECT id FROM boms WHERE org_id=? AND item_id=? AND effective_on=?',
                [$org, $product, $effective]) === null, '同一日期已有 BOM 版本。', 409);
            $components = [];
            foreach ($lines as $line) {
                $id = $line['item'] ?? null;
                $qty = $line['quantity'] ?? null;
                $unit = $line['uom'] ?? null;
                $loss = $line['loss_bps'] ?? 0;
                $alternatives = $line['alternatives'] ?? [];
                Value::assert(is_int($id) && is_int($qty) && is_string($unit) && is_int($loss)
                    && $loss >= 0 && $loss < 10_000 && is_array($alternatives) && array_is_list($alternatives)
                    && count($alternatives) <= 12, 'BOM 组件、损耗率或替代料格式无效。');
                Value::assert(!isset($components[$id]), 'BOM 中不能重复登记同一组件。');
                $unit = Value::unit($unit);
                $conversion = $this->conversion($id, $unit, $effective);
                $baseQuantity = Value::convert($qty, (int) $conversion['factor_milli']);
                $eligible = [];
                foreach ($alternatives as $alternative) {
                    Value::assert(is_int($alternative) && $alternative !== $id && $alternative !== $product
                        && !isset($eligible[$alternative]), '替代料必须唯一且与组件和父件不同。');
                    $substitute = $this->organizationItem($alternative);
                    Value::assert($substitute['base_uom'] === $this->organizationItem($id)['base_uom'], '替代料的基础单位必须一致。');
                    $eligible[$alternative] = true;
                }
                $components[$id] = [$baseQuantity, $unit, (int) $conversion['factor_milli'], $loss, array_keys($eligible)];
            }
            $this->assertAcyclic($product, $effective, $components);
            $bom = $this->db->insert('INSERT INTO boms (org_id,item_id,effective_on,published_by) VALUES (?,?,?,?)',
                [$org, $product, $effective, $this->scope->user]);
            foreach ($components as $id => [$qty, $unit, $factor, $loss, $alternatives]) {
                $this->db->run('INSERT INTO bom_components
                    (org_id,bom_id,item_id,qty_per_milli,source_uom,factor_milli,loss_bps) VALUES (?,?,?,?,?,?,?)',
                    [$org, $bom, $id, $qty, $unit, $factor, $loss]);
                foreach ($alternatives as $alternative) {
                    $this->db->run('INSERT INTO bom_alternatives (org_id,bom_id,item_id,alternative_item_id) VALUES (?,?,?,?)',
                        [$org, $bom, $id, $alternative]);
                }
            }
            $this->audit('bom.published', $bom, ['item' => $product, 'effective_on' => $effective, 'components' => count($components)]);
            return $bom;
        }, 'planner');
    }

    private function assertAcyclic(int $product, string $effective, array $components): void
    {
        $revisions = [];
        $next = null;
        $boundaries = [$effective => true];
        $rows = $this->db->rows('SELECT b.item_id AS parent_id,b.effective_on,c.item_id AS child_id,a.alternative_item_id
            FROM boms b JOIN bom_components c ON c.bom_id=b.id
            LEFT JOIN bom_alternatives a ON a.bom_id=b.id AND a.item_id=c.item_id
            WHERE b.org_id=? ORDER BY b.effective_on,b.id', [$this->scope->org]);
        foreach ($rows as $row) {
            $from = $row['effective_on'];
            $parent = (int) $row['parent_id'];
            $revisions[$parent][$from][(int) $row['child_id']] = true;
            if ($row['alternative_item_id'] !== null) {
                $revisions[$parent][$from][(int) $row['alternative_item_id']] = true;
            }
            if ($from > $effective) {
                $boundaries[$from] = true;
                if ($parent === $product && $next === null) {
                    $next = $from;
                }
            }
        }
        $candidate = array_fill_keys(array_keys($components), true);
        foreach ($components as $line) {
            foreach ($line[4] as $alternative) {
                $candidate[$alternative] = true;
            }
        }
        $revisions[$product][$effective] = $candidate;
        ksort($revisions[$product], SORT_STRING);
        ksort($boundaries, SORT_STRING);
        foreach (array_keys($boundaries) as $day) {
            if ($next !== null && $day >= $next) {
                break;
            }
            $path = [];
            $seen = [];
            $cycle = $this->cycle($product, $day, $revisions, $path, $seen);
            if ($cycle !== null) {
                $catalog = array_column((new MasterData($this->db, $this->scope))->items(), 'code', 'id');
                $label = implode(' → ', array_map(static fn(int $id): string => $catalog[$id], $cycle));
                throw new BusinessError('BOM 循环引用（' . $day . '）' . $label, 409);
            }
        }
    }

    private function cycle(int $item, string $day, array &$revisions, array &$path, array &$done): ?array
    {
        if (isset($path[$item])) {
            return [...array_slice(array_keys($path), $path[$item]), $item];
        }
        if (isset($done[$item])) {
            return null;
        }
        $path[$item] = count($path);
        $active = [];
        foreach ($revisions[$item] ?? [] as $version => $children) {
            if ($version > $day) {
                break;
            }
            $active = $children;
        }
        foreach (array_keys($active) as $child) {
            $cycle = $this->cycle($child, $day, $revisions, $path, $done);
            if ($cycle !== null) {
                return $cycle;
            }
        }
        unset($path[$item]);
        $done[$item] = true;
        return null;
    }

    public function bom(int $product, string $day): ?array
    {
        Value::day($day);
        $this->organizationItem($product);
        $bom = $this->db->row('SELECT id,item_id,effective_on FROM boms
            WHERE org_id=? AND item_id=? AND effective_on<=? ORDER BY effective_on DESC LIMIT 1', [$this->scope->org, $product, $day]);
        if ($bom === null) {
            return null;
        }
        return $this->assembleBom($bom);
    }

    public function bomRevision(int $id): array
    {
        $bom = $this->db->row('SELECT id,item_id,effective_on FROM boms WHERE org_id=? AND id=?', [$this->scope->org, $id]);
        Value::assert($bom !== null, '该 BOM 版本不在本账套。', 404);
        return $this->assembleBom($bom);
    }

    private function assembleBom(array $bom): array
    {
        $bom['lines'] = $this->db->rows('SELECT c.item_id,i.code,i.name,i.base_uom,c.qty_per_milli,c.source_uom,c.factor_milli,c.loss_bps
            FROM bom_components c JOIN items i ON i.id=c.item_id WHERE c.org_id=? AND c.bom_id=? ORDER BY i.code', [$this->scope->org, $bom['id']]);
        $alternatives = $this->db->rows('SELECT item_id,alternative_item_id FROM bom_alternatives
            WHERE org_id=? AND bom_id=? ORDER BY item_id,alternative_item_id', [$this->scope->org, $bom['id']]);
        $byItem = [];
        foreach ($alternatives as $row) {
            $byItem[$row['item_id']][] = (int) $row['alternative_item_id'];
        }
        foreach ($bom['lines'] as &$line) {
            $line['alternatives'] = $byItem[$line['item_id']] ?? [];
        }
        unset($line);
        return $bom;
    }

    public static function componentRequirement(int $productQuantity, array $line): int
    {
        $product = Value::quantity($productQuantity) * Value::quantity((int) $line['qty_per_milli']);
        $denominator = Value::SCALE * (10_000 - (int) $line['loss_bps']);
        $whole = intdiv($product, $denominator);
        Value::assert($whole <= intdiv(Value::MAX_QUANTITY, 10_000), 'BOM 展开数量超出范围。');
        $remainder = $product % $denominator;
        return Value::quantity($whole * 10_000 + intdiv($remainder * 10_000 + $denominator - 1, $denominator));
    }

    public function addCenter(int $department, string $code, string $name): int
    {
        $code = Value::code($code, '工作中心编码');
        $name = Value::text($name, 140, '工作中心名称');
        return $this->mutate(function (int $org) use ($department, $code, $name): int {
            Value::assert($this->db->row('SELECT id FROM departments WHERE org_id=? AND id=?', [$org, $department]) !== null,
                '部门不存在。', 404);
            Value::assert($this->db->row('SELECT id FROM work_centers WHERE org_id=? AND code=?', [$org, $code]) === null,
                '工作中心编码已使用。', 409);
            $center = $this->db->insert('INSERT INTO work_centers (org_id,department_id,code,name) VALUES (?,?,?,?)',
                [$org, $department, $code, $name]);
            $this->audit('center.created', $center, ['department' => $department, 'code' => $code]);
            return $center;
        }, 'planner');
    }

    public function publishRoute(int $product, string $effective, array $steps): int
    {
        Value::day($effective);
        Value::assert(array_is_list($steps) && count($steps) >= 1 && count($steps) <= 30, '工艺路线须包含 1 至 30 道工序。');
        return $this->mutate(function (int $org) use ($product, $effective, $steps): int {
            $this->organizationItem($product);
            Value::assert($this->db->row('SELECT id FROM routes WHERE org_id=? AND item_id=? AND effective_on=?',
                [$org, $product, $effective]) === null, '该日期已有工艺路线版本。', 409);
            $route = $this->db->insert('INSERT INTO routes (org_id,item_id,effective_on,published_by) VALUES (?,?,?,?)',
                [$org, $product, $effective, $this->scope->user]);
            foreach ($steps as $index => $step) {
                $center = $step['center'] ?? null;
                $label = $step['label'] ?? null;
                $setup = $step['setup_minutes'] ?? null;
                $seconds = $step['run_seconds_per_unit'] ?? null;
                Value::assert(is_int($center) && is_string($label) && is_int($setup) && is_int($seconds)
                    && $setup >= 0 && $setup <= 1440 && $seconds >= 1 && $seconds <= 86_400, '工序与标准工时参数无效。');
                Value::assert($this->db->row('SELECT id FROM work_centers WHERE org_id=? AND id=?', [$org, $center]) !== null,
                    '工作中心不存在。', 404);
                $this->db->run('INSERT INTO route_steps (org_id,route_id,sequence_no,center_id,label,setup_minutes,run_seconds_per_unit)
                    VALUES (?,?,?,?,?,?,?)', [$org, $route, $index + 1, $center, Value::text($label, 140, '工序名称'), $setup, $seconds]);
            }
            $this->audit('route.published', $route, ['item' => $product, 'effective_on' => $effective, 'steps' => count($steps)]);
            return $route;
        }, 'planner');
    }

    public function route(int $product, string $day): ?array
    {
        Value::day($day);
        $this->organizationItem($product);
        $route = $this->db->row('SELECT id,item_id,effective_on FROM routes
            WHERE org_id=? AND item_id=? AND effective_on<=? ORDER BY effective_on DESC LIMIT 1', [$this->scope->org, $product, $day]);
        return $route === null ? null : $this->assembleRoute($route);
    }

    public function routeRevision(int $id): array
    {
        $route = $this->db->row('SELECT id,item_id,effective_on FROM routes WHERE org_id=? AND id=?', [$this->scope->org, $id]);
        Value::assert($route !== null, '该工艺路线版本不在本账套。', 404);
        return $this->assembleRoute($route);
    }

    private function assembleRoute(array $route): array
    {
        $route['steps'] = $this->db->rows('SELECT s.sequence_no,s.center_id,s.label,s.setup_minutes,s.run_seconds_per_unit,w.code
            FROM route_steps s JOIN work_centers w ON w.id=s.center_id
            WHERE s.org_id=? AND s.route_id=? ORDER BY s.sequence_no', [$this->scope->org, $route['id']]);
        return $route;
    }
}
