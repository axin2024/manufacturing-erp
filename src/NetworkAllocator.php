<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;

final class NetworkAllocator
{
    public static function calculate(array $snapshot, string $start, string $end): array
    {
        $policies = $local = $incoming = $calendar = $lanes = [];
        foreach ($snapshot['items'] as $row) {
            $policies[$row['site_id']][$row['item_id']] = $row;
        }
        foreach ($snapshot['stock'] as $row) {
            $site = $row['site_id'];
            $item = $row['item_id'];
            $local[$site][$item] = ($local[$site][$item] ?? 0) + $row['qty_milli'] - $row['committed_milli'];
        }
        foreach ($snapshot['calendar'] as $row) {
            $calendar[$row['site_id']][$row['work_date']] = (bool) $row['working'];
        }
        foreach ($snapshot['lanes'] as $row) {
            $lanes[$row['destination_site']][] = $row;
        }
        $substitutes = [];
        foreach ($snapshot['substitutes'] ?? [] as $row) {
            $substitutes[$row['lane_id']][$row['item_id']][$row['source_item_id']] = $row['evidence'];
        }
        foreach ($snapshot['work'] as $row) {
            $incoming[$row['site_id']][$row['item_id']][] = [$row['available_on'],'released',(int) $row['remaining_milli']];
        }
        foreach ($snapshot['transfers'] as $row) {
            $source = $row['source_site'];
            $item = $row['item_id'];
            $outstanding = $row['ordered_milli'] - $row['shipped_milli'];
            $sourceItem = $row['source_item_id'] ?? $item;
            $local[$source][$sourceItem] = max(0, ($local[$source][$sourceItem] ?? 0) - $outstanding);
            $incoming[$row['destination_site']][$item][] = [$row['promised_on'],'committed',$outstanding];
            $incoming[$row['destination_site']][$item][] = [$row['promised_on'],'transit',$row['shipped_milli'] - $row['received_milli'] - ($row['lost_milli'] ?? 0)];
        }
        foreach ($snapshot['plannedWork'] as $row) {
            $incoming[$row['site_id']][$row['item_id']][] = [$row['due_on'],'committed',(int) $row['qty_milli']];
        }
        foreach ($incoming as &$byItem) {
            foreach ($byItem as &$supplyDates) {
                sort($supplyDates);
            }
            unset($supplyDates);
        }
        unset($byItem);
        // Cover every plant's own commitments first; a donor can only offer the unused physical stock.
        $balances = $shortages = [];
        $receivable = $position = [];
        foreach (self::requirements($snapshot, $start, $end) as $day=>$demands) {
            foreach ($demands as $site=>$byItem) {
                foreach ($byItem as $item=>$required) {
                    $available = $local[$site][$item] ?? 0;
                    $used = min($required, $available);
                    $local[$site][$item] = $available - $used;
                    $supply = $incoming[$site][$item] ?? [];
                    $offset = $position[$site][$item] ?? 0;
                    $pool = $receivable[$site][$item] ?? ['transit'=>0,'released'=>0,'committed'=>0];
                    while (isset($supply[$offset]) && $supply[$offset][0] <= $day) {
                        [, $kind, $quantity] = $supply[$offset++];
                        $pool[$kind] += $quantity;
                    }
                    $position[$site][$item] = $offset;
                    $missing = $required - $used;
                    $covered = [];
                    foreach ($pool as $kind=>$quantity) {
                        $covered[$kind] = min($missing,$quantity);
                        $pool[$kind] -= $covered[$kind];
                        $missing -= $covered[$kind];
                    }
                    $receivable[$site][$item] = $pool;
                    $balances[] = ['site_id'=>$site,'item_id'=>$item,'needed_on'=>$day,'demand_milli'=>$required,
                        'available_milli'=>$available,'stock_used_milli'=>$used,'released_used_milli'=>$covered['released'],
                        'transit_used_milli'=>$covered['transit'],'committed_used_milli'=>$covered['committed'],'shortage_milli'=>$missing];
                    if ($missing > 0) {
                        $shortages[] = [$site,$item,$day,$missing];
                    }
                }
            }
        }
        $lines = [];
        foreach ($shortages as [$site,$item,$day,$missing]) {
            $policy = $policies[$site][$item];
            $alternatives = [];
            foreach ($lanes[$site] ?? [] as $lane) {
                $source = $lane['source_site'];
                $arrival = FactoryNetwork::arrivalDate($start, (int) $lane['lead_days'], $calendar[$site] ?? []);
                foreach ([$item=>null]+($substitutes[$lane['id']][$item] ?? []) as $candidate=>$evidence) {
                    if (isset($policies[$source][$candidate])) {
                        $alternatives[] = ['kind'=>'transfer','source_site'=>$source,'source_item_id'=>$candidate,'qualification'=>$evidence,
                            'available_milli'=>$local[$source][$candidate] ?? 0,'arrival_date'=>$arrival,
                            'unit_cost_minor'=>(int)$policies[$source][$candidate]['standard_cost_minor'],'lane_id'=>$lane['id']];
                    }
                }
            }
            usort($alternatives, static fn(array $a, array $b): int =>
                [$a['arrival_date'],$a['source_item_id']!==$item,$a['unit_cost_minor'],$a['source_site'],$a['source_item_id']]
                <=> [$b['arrival_date'],$b['source_item_id']!==$item,$b['unit_cost_minor'],$b['source_site'],$b['source_item_id']]);
            $localDate = FactoryNetwork::arrivalDate($start,(int) $policy['lead_days'],$calendar[$site] ?? []);
            foreach (['manufacture','subcontract'] as $kind) {
                $reason = match (true) {
                    $policy['sourcing'] !== $kind => 'sourcing_not_enabled',
                    $policy['bom_id'] === null => 'missing_bom',
                    $kind === 'manufacture' && $policy['route_id'] === null => 'missing_route',
                    default => null,
                };
                $alternatives[] = ['kind'=>$kind, 'source_site'=>$site, 'reason'=>$reason,
                    'arrival_date'=>$reason === null ? $localDate : null, 'unit_cost_minor'=>(int) $policy['standard_cost_minor']];
            }
            foreach ($alternatives as $option) {
                if ($option['kind'] !== 'transfer' || $option['arrival_date'] > $day || !$option['available_milli']) {
                    continue;
                }
                $source = $option['source_site'];
                $quantity = min($missing, $option['available_milli']);
                $sourceItem = $option['source_item_id'];
                $lines[] = [$source,$site,$item,'transfer',$day,$option['arrival_date'],$quantity,$option['unit_cost_minor'],$alternatives,$sourceItem];
                $local[$source][$sourceItem] -= $quantity;
                $missing -= $quantity;
                if (!$missing) {
                    break;
                }
            }
            if ($missing) {
                $choice = array_values(array_filter($alternatives, static fn(array $option): bool =>
                    $option['kind'] !== 'transfer' && $option['reason'] === null))[0] ?? null;
                $lines[] = [$site,$site,$item,$choice['kind'] ?? 'uncovered',$day,$choice['arrival_date'] ?? null,
                    $missing,(int) $policy['standard_cost_minor'],$alternatives,$item];
            }
        }
        return ['balances'=>$balances, 'lines'=>$lines];
    }

    private static function requirements(array $snapshot, string $start, string $end): array
    {
        $booked = $demands = [];
        foreach ($snapshot['sales'] as $row) {
            $site = $row['site_id'];
            $item = $row['item_id'];
            $day = $row['due_on'];
            $week = (new DateTimeImmutable($day))->modify('monday this week')->format('Y-m-d');
            $quantity = (int) ($row['state'] === 'cancelled' ? $row['dispatched_milli'] : $row['qty_milli']);
            $booked[$week][$site][$item] = ($booked[$week][$site][$item] ?? 0) + $quantity;
            $open = max(0, $quantity - $row['dispatched_milli'] - $row['reserved_milli']);
            if ($open && $day >= $start && $day <= $end) {
                $demands[$day][$site][$item] = ($demands[$day][$site][$item] ?? 0) + $open;
            }
        }
        foreach ($snapshot['forecasts'] as $row) {
            $site = $row['site_id'];
            $item = $row['item_id'];
            $week = $row['week_start'];
            $day = (new DateTimeImmutable($week))->modify('+6 days')->format('Y-m-d');
            $open = max(0, $row['qty_milli'] - ($booked[$week][$site][$item] ?? 0));
            if ($open && $day >= $start && $day <= $end) {
                $demands[$day][$site][$item] = ($demands[$day][$site][$item] ?? 0) + $open;
            }
        }
        ksort($demands);
        return $demands;
    }
}
