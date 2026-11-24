<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

/**
 * Nets an immutable snapshot in BOM order, then in need-date order within each material.
 */
final class PlanningNetter
{
    private const MAX_REQUIREMENTS = 25_000;
    private const MAX_PEGS = 50_000;

    public function calculate(array $items, array $boms, array $roots, array $supply): array
    {
        $needs = [];
        foreach ($roots as $root) {
            $root['ref'] = hash('sha256', $root['root_kind'] . ':' . $root['root_id']);
            $root['parent_ref'] = null;
            $needs[$root['item_id']][] = $root;
        }
        $count = count($roots);
        $requirements = $pegs = $proposals = [];
        foreach ($this->materials(array_keys($needs), $boms) as $item) {
            $queue = $needs[$item] ?? [];
            if ($queue === []) {
                continue;
            }
            usort($queue, static fn (array $a, array $b): int =>
                [$a['needed_on'], $a['root_kind'] !== 'work', $a['root_id'], $a['ref']]
                <=> [$b['needed_on'], $b['root_kind'] !== 'work', $b['root_id'], $b['ref']]);
            $stock = $supply[$item]['stock'] ?? [];
            $incoming = $supply[$item]['incoming'] ?? [];
            $stockIndex = $incomingIndex = 0;
            foreach ($queue as $need) {
                Value::assert($count <= self::MAX_REQUIREMENTS, '需求展开超过单次计划上限，请缩短需求截点。', 409);
                $stillNeeded = (int) $need['gross_milli'];
                $matched = [];
                $fromStock = $this->consume($stock, $stockIndex, null, $stillNeeded, $matched);
                $fromIncoming = $this->consume($incoming, $incomingIndex, $need['needed_on'], $stillNeeded, $matched);
                $need += [
                    'bom_id' => $boms[$item]['id'] ?? null,
                    'stock_milli' => $fromStock,
                    'incoming_milli' => $fromIncoming,
                    'shortage_milli' => $stillNeeded,
                    'supply_digest' => hash('sha256', Value::json($matched)),
                ];
                $requirements[$need['ref']] = $need;
                foreach ($matched as $peg) {
                    $pegs[] = [$need['ref'], ...$peg];
                }
                Value::assert(count($pegs) <= self::MAX_PEGS, '供需关联超过单次计划上限，请缩短需求截点。', 409);
                if ($stillNeeded === 0) {
                    continue;
                }
                $kind = $items[$item]['sourcing'];
                $starts = $this->startsOn($need['needed_on'], (int) $items[$item]['lead_days']);
                $blocked = $kind !== 'purchase' && $need['bom_id'] === null ? 'missing_bom' : null;
                $proposals[] = [$need['ref'], $kind, $starts, $stillNeeded, $blocked];
                if ($kind === 'purchase' || $blocked !== null) {
                    continue;
                }
                foreach ($boms[$item]['lines'] as $line) {
                    $child = (int) $line['item_id'];
                    $needs[$child][] = [
                        'ref' => hash('sha256', $need['ref'] . ':' . $child),
                        'parent_ref' => $need['ref'],
                        'root_kind' => $need['root_kind'],
                        'root_id' => $need['root_id'],
                        'sales_demand_id' => $need['sales_demand_id'],
                        'item_id' => $child,
                        'needed_on' => $starts,
                        'gross_milli' => ProductEngineering::componentRequirement($stillNeeded, $line),
                    ];
                    ++$count;
                }
            }
            unset($needs[$item]);
        }
        return [$requirements, $pegs, $proposals];
    }

    private function materials(array $roots, array $boms): array
    {
        $visited = $ordered = [];
        $visit = static function (int $item) use (&$visit, &$visited, &$ordered, $boms): void {
            if (isset($visited[$item])) {
                return;
            }
            $visited[$item] = true;
            foreach ($boms[$item]['lines'] ?? [] as $line) {
                $visit((int) $line['item_id']);
            }
            $ordered[] = $item;
        };
        foreach ($roots as $item) {
            $visit($item);
        }
        return array_reverse($ordered);
    }

    private function consume(array &$entries, int &$offset, ?string $latest, int &$remaining, array &$pegs): int
    {
        $consumed = 0;
        while ($remaining > 0 && isset($entries[$offset])) {
            [$kind, $id, $available, $quantity] = $entries[$offset];
            if ($latest !== null && $available > $latest) {
                break;
            }
            $used = min($quantity, $remaining);
            $pegs[] = [$kind, $id, $available, $used];
            $remaining -= $used;
            $consumed += $used;
            if ($used === $quantity) {
                ++$offset;
            } else {
                $entries[$offset][3] -= $used;
            }
        }
        return $consumed;
    }

    private function startsOn(string $need, int $leadDays): string
    {
        return max('1000-01-01', (new DateTimeImmutable($need))->modify('-' . $leadDays . ' days')->format('Y-m-d'));
    }
}
