CREATE TABLE IF NOT EXISTS quote_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    needed_on DATE NOT NULL,
    proposal_id BIGINT UNSIGNED NULL,
    winning_quote_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,number),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    FOREIGN KEY (org_id,proposal_id) REFERENCES mrp_proposals(org_id,id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS supplier_quotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    available_on DATE NOT NULL,
    valid_until DATE NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,request_id,supplier_id,number),
    FOREIGN KEY (org_id,request_id) REFERENCES quote_requests(org_id,id),
    FOREIGN KEY (org_id,supplier_id) REFERENCES parties(org_id,id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    ordered_on DATE NOT NULL,
    state ENUM('draft','issued','received','closed','void') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    issued_by BIGINT UNSIGNED NULL,
    issued_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,number),
    UNIQUE KEY (org_id,id),
    KEY (org_id,state,id),
    FOREIGN KEY (org_id,supplier_id) REFERENCES parties(org_id,id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS purchase_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    quote_id BIGINT UNSIGNED NULL,
    proposal_id BIGINT UNSIGNED NULL,
    source_uom VARCHAR(24) NOT NULL,
    source_qty_milli BIGINT UNSIGNED NOT NULL,
    conversion_id BIGINT UNSIGNED NULL,
    factor_milli BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    due_on DATE NOT NULL,
    received_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cancelled_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (order_id,line_no),
    KEY (org_id,quote_id),
    FOREIGN KEY (org_id,order_id) REFERENCES purchase_orders(org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    FOREIGN KEY (org_id,quote_id) REFERENCES supplier_quotes(org_id,id),
    FOREIGN KEY (org_id,proposal_id) REFERENCES mrp_proposals(org_id,id),
    FOREIGN KEY (org_id,conversion_id) REFERENCES unit_conversions(org_id,id),
    CHECK (qty_milli>0 AND received_milli+cancelled_milli<=qty_milli)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    received_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,number),
    UNIQUE KEY (org_id,id),
    KEY (org_id,order_id,id),
    FOREIGN KEY (org_id,order_id) REFERENCES purchase_orders(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS receipt_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    receipt_id BIGINT UNSIGNED NOT NULL,
    purchase_line_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,lot_id),
    FOREIGN KEY (org_id,receipt_id) REFERENCES receipts(org_id,id),
    FOREIGN KEY (org_id,purchase_line_id) REFERENCES purchase_lines(org_id,id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inspection_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    source_kind ENUM('purchase','work','subcontract') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    accepted_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rejected_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rework_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state ENUM('pending','partial','completed') NOT NULL DEFAULT 'pending',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,lot_id),
    KEY (org_id,source_kind,source_id),
    KEY (org_id,state,id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    CHECK (qty_milli>0 AND accepted_milli+rejected_milli+rework_milli<=qty_milli)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inspection_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    accepted_milli BIGINT UNSIGNED NOT NULL,
    rejected_milli BIGINT UNSIGNED NOT NULL,
    rework_milli BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    measured_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,case_id,version),
    FOREIGN KEY (org_id,case_id) REFERENCES inspection_cases(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (accepted_milli+rejected_milli+rework_milli>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
