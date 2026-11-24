CREATE TABLE IF NOT EXISTS subcontract_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    work_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    contract_on DATE NOT NULL,
    unit_fee_minor BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,number),
    UNIQUE KEY (org_id,work_id),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,work_id) REFERENCES work_orders(org_id,id),
    FOREIGN KEY (org_id,supplier_id) REFERENCES parties(org_id,id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS subcontract_materials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    subcontract_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    replacement_approval_id BIGINT UNSIGNED NULL,
    overage_approval_id BIGINT UNSIGNED NULL,
    sent_milli BIGINT UNSIGNED NOT NULL,
    used_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    returned_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    loss_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unit_cost_minor BIGINT UNSIGNED NOT NULL,
    sent_on DATE NOT NULL,
    last_posted_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,id),
    KEY (org_id,subcontract_id,id),
    FOREIGN KEY (org_id,subcontract_id) REFERENCES subcontract_orders(org_id,id),
    FOREIGN KEY (org_id,material_id) REFERENCES work_materials(org_id,id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    FOREIGN KEY (org_id,replacement_approval_id) REFERENCES work_issue_authorizations(org_id,id),
    FOREIGN KEY (org_id,overage_approval_id) REFERENCES work_issue_authorizations(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (sent_milli>0 AND used_milli+returned_milli+loss_milli<=sent_milli)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subcontract_recoveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    dispatch_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('return','loss') NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    posted_on DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,dispatch_id) REFERENCES subcontract_materials(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (qty_milli>0 AND ((kind='return' AND location_id IS NOT NULL) OR (kind='loss' AND location_id IS NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS subcontract_output_usage (
    org_id BIGINT UNSIGNED NOT NULL,
    output_id BIGINT UNSIGNED NOT NULL,
    dispatch_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,output_id,dispatch_id),
    FOREIGN KEY (org_id,output_id) REFERENCES work_outputs(org_id,id),
    FOREIGN KEY (org_id,dispatch_id) REFERENCES subcontract_materials(org_id,id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subcontract_fees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    subcontract_id BIGINT UNSIGNED NOT NULL,
    output_id BIGINT UNSIGNED NOT NULL,
    expected_minor BIGINT UNSIGNED NOT NULL,
    invoiced_minor BIGINT UNSIGNED NULL,
    vendor_invoice VARCHAR(40) NULL,
    matched_on DATE NULL,
    matched_by BIGINT UNSIGNED NULL,
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,output_id),
    KEY (org_id,subcontract_id,matched_on),
    FOREIGN KEY (org_id,subcontract_id) REFERENCES subcontract_orders(org_id,id),
    FOREIGN KEY (org_id,output_id) REFERENCES work_outputs(org_id,id),
    FOREIGN KEY (matched_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
