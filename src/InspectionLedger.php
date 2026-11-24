<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class InspectionLedger extends Service
{
    public function register(int $lot, int $location, string $kind, int $source, int $quantity): int
    {
        $id = $this->db->insert('INSERT INTO inspection_cases (org_id,lot_id,location_id,source_kind,source_id,qty_milli)
            VALUES (?,?,?,?,?,?)', [$this->scope->org, $lot, $location, $kind, $source, $quantity]);
        $version = Extensions::load()->qualityVersion($kind);
        if ($version !== Extensions::QUALITY_DEFAULT) {
            $this->db->run('INSERT INTO inspection_rule_versions (org_id,case_id,rule_version) VALUES (?,?,?)',
                [$this->scope->org, $id, $version]);
        }
        $this->audit('quality.submitted', $id, ['lot_id' => $lot, 'source_kind' => $kind, 'source_id' => $source, 'rule_version' => $version]);
        return $id;
    }
}
