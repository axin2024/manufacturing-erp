CREATE TABLE IF NOT EXISTS operation_actuals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    machine_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    actual_seconds INT UNSIGNED NOT NULL,
    labor_rate_minor BIGINT UNSIGNED NOT NULL,
    overhead_rate_minor BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,operation_id),
    KEY (org_id,machine_id,starts_at,ends_at),
    FOREIGN KEY (org_id,operation_id) REFERENCES work_operations(org_id,id),
    FOREIGN KEY (org_id,slot_id) REFERENCES operation_slots(org_id,id),
    FOREIGN KEY (org_id,machine_id) REFERENCES machines(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (starts_at<ends_at AND actual_seconds>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_outputs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    work_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    actual_minor BIGINT UNSIGNED NOT NULL,
    standard_minor BIGINT UNSIGNED NOT NULL,
    reported_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (org_id,lot_id),
    KEY (org_id,work_id,id),
    FOREIGN KEY (org_id,work_id) REFERENCES work_orders(org_id,id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_output_inputs (
    org_id BIGINT UNSIGNED NOT NULL,
    output_id BIGINT UNSIGNED NOT NULL,
    issue_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    actual_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,output_id,issue_id),
    FOREIGN KEY (org_id,output_id) REFERENCES work_outputs(org_id,id),
    FOREIGN KEY (org_id,issue_id) REFERENCES work_issues(org_id,id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rework_inputs (
    org_id BIGINT UNSIGNED NOT NULL,
    work_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    consumed_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (org_id,work_id),
    FOREIGN KEY (org_id,work_id) REFERENCES work_orders(org_id,id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    CHECK (qty_milli>0 AND consumed_milli<=qty_milli)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lot_genealogy (
    org_id BIGINT UNSIGNED NOT NULL,
    input_lot_id BIGINT UNSIGNED NOT NULL,
    output_lot_id BIGINT UNSIGNED NOT NULL,
    source_kind ENUM('issue','rework','subcontract') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,input_lot_id,output_lot_id,source_kind,source_id),
    KEY (org_id,output_lot_id,input_lot_id),
    FOREIGN KEY (org_id,input_lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,output_lot_id) REFERENCES stock_lots(org_id,id),
    CHECK (input_lot_id<>output_lot_id AND qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shipments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    dispatched_on DATE NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,number),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,customer_id) REFERENCES parties(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS shipment_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    shipment_id BIGINT UNSIGNED NOT NULL,
    reservation_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    revenue_minor BIGINT UNSIGNED NOT NULL,
    cogs_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,id),
    KEY (org_id,lot_id,id),
    FOREIGN KEY (org_id,shipment_id) REFERENCES shipments(org_id,id),
    FOREIGN KEY (org_id,reservation_id) REFERENCES stock_reservations(org_id,id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,demand_id) REFERENCES sales_demands(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;
