CREATE TABLE IF NOT EXISTS stock_lot_values (
    org_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    carrying_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,lot_id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    CHECK (qty_milli>0 OR carrying_minor=0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendor_material_values (
    org_id BIGINT UNSIGNED NOT NULL,
    dispatch_id BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,dispatch_id),
    FOREIGN KEY (org_id,dispatch_id) REFERENCES subcontract_materials(org_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    source_kind VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    memo VARCHAR(255) NOT NULL,
    posted_on DATE NOT NULL,
    period_start DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,source_kind,source_id),
    KEY (org_id,period_start,posted_on,id),
    FOREIGN KEY (org_id,period_start) REFERENCES fiscal_periods(org_id,period_start),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS journal_lines (
    org_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    account_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    debit_minor BIGINT UNSIGNED NOT NULL,
    credit_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,journal_id,line_no),
    KEY (org_id,account_code,currency,journal_id),
    FOREIGN KEY (org_id,journal_id) REFERENCES journals(org_id,id),
    CHECK ((debit_minor>0 AND credit_minor=0) OR (credit_minor>0 AND debit_minor=0))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS open_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('ar','ap') NOT NULL,
    party_id BIGINT UNSIGNED NOT NULL,
    document_ref VARCHAR(40) NOT NULL,
    source_kind VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    posted_on DATE NOT NULL,
    due_on DATE NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    open_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,direction,source_kind,source_id),
    KEY (org_id,direction,due_on,id),
    FOREIGN KEY (org_id,party_id) REFERENCES parties(org_id,id),
    CHECK (amount_minor>0 AND open_minor<=amount_minor AND due_on>=posted_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS settlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    open_item_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(40) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    posted_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,open_item_id,reference),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,open_item_id) REFERENCES open_items(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (amount_minor>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS purchase_bills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    posted_on DATE NOT NULL,
    due_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,supplier_id,number),
    FOREIGN KEY (org_id,supplier_id) REFERENCES parties(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS purchase_bill_matches (
    org_id BIGINT UNSIGNED NOT NULL,
    bill_id BIGINT UNSIGNED NOT NULL,
    receipt_line_id BIGINT UNSIGNED NOT NULL,
    expected_minor BIGINT UNSIGNED NOT NULL,
    invoiced_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,bill_id,receipt_line_id),
    UNIQUE KEY (org_id,receipt_line_id),
    FOREIGN KEY (org_id,bill_id) REFERENCES purchase_bills(org_id,id),
    FOREIGN KEY (org_id,receipt_line_id) REFERENCES receipt_lines(org_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    corrects_journal_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NOT NULL,
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,number),
    FOREIGN KEY (org_id) REFERENCES organizations(id),
    FOREIGN KEY (org_id,corrects_journal_id) REFERENCES journals(org_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS intercompany_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    source_org_id BIGINT UNSIGNED NOT NULL,
    destination_org_id BIGINT UNSIGNED NOT NULL,
    source_lot_id BIGINT UNSIGNED NOT NULL,
    destination_lot_id BIGINT UNSIGNED NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    posted_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (tenant_id,number),
    KEY (source_org_id,posted_on,id),
    KEY (destination_org_id,posted_on,id),
    FOREIGN KEY (tenant_id,source_org_id) REFERENCES organizations(tenant_id,id),
    FOREIGN KEY (tenant_id,destination_org_id) REFERENCES organizations(tenant_id,id),
    FOREIGN KEY (source_org_id,source_lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (destination_org_id,destination_lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (source_org_id<>destination_org_id AND qty_milli>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS financial_closures (
    org_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    snapshot JSON NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (org_id,period_start),
    FOREIGN KEY (org_id,period_start) REFERENCES fiscal_periods(org_id,period_start)
) ENGINE=InnoDB;
