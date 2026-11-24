<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class SubcontractLedger extends Service
{
    public function order(int $id): array
    {
        $row = $this->db->row('SELECT s.*,p.name AS supplier_name,w.number AS work_number FROM subcontract_orders s
            JOIN parties p ON p.id=s.supplier_id JOIN work_orders w ON w.id=s.work_id WHERE s.org_id=? AND s.id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '委外合同不存在。', 404);
        return $row;
    }

    public function material(int $id): array
    {
        $row = $this->db->row('SELECT s.*,v.amount_minor AS basis,s.sent_milli-s.used_milli-s.returned_milli-s.loss_milli AS outside_milli
            FROM subcontract_materials s LEFT JOIN vendor_material_values v ON v.org_id=s.org_id AND v.dispatch_id=s.id
            WHERE s.org_id=? AND s.id=?', [$this->scope->org, $id]);
        Value::assert($row !== null, '委外供料明细不存在。', 404);
        return $row;
    }

    public function balances(int $contract): array
    {
        return $this->db->rows('SELECT s.*,i.code,l.lot_code,s.sent_milli-s.used_milli-s.returned_milli-s.loss_milli AS outside_milli
            FROM subcontract_materials s JOIN stock_lots l ON l.id=s.lot_id JOIN items i ON i.id=l.item_id
            WHERE s.org_id=? AND s.subcontract_id=? ORDER BY s.id', [$this->scope->org, $contract]);
    }

    public function fees(int $contract): array
    {
        return $this->db->rows('SELECT f.*,o.reported_on,l.lot_code,CAST(f.invoiced_minor AS SIGNED)-CAST(f.expected_minor AS SIGNED) AS variance_minor
            FROM subcontract_fees f JOIN work_outputs o ON o.id=f.output_id JOIN stock_lots l ON l.id=o.lot_id
            WHERE f.org_id=? AND f.subcontract_id=? ORDER BY f.id', [$this->scope->org, $contract]);
    }
}
