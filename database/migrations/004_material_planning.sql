CREATE TABLE IF NOT EXISTS mrp_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    prior_id BIGINT UNSIGNED NULL,
    as_of DATE NOT NULL,
    horizon_on DATE NOT NULL,
    policy VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('review','approved','superseded') NOT NULL DEFAULT 'review',
    stats JSON NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id) REFERENCES organizations(id),
    FOREIGN KEY (org_id,prior_id) REFERENCES mrp_runs(org_id,id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    CHECK (as_of<=horizon_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mrp_requirements (
    org_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    ref CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    parent_ref CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    root_kind ENUM('sale','work') NOT NULL,
    root_id BIGINT UNSIGNED NOT NULL,
    sales_demand_id BIGINT UNSIGNED NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    needed_on DATE NOT NULL,
    bom_id BIGINT UNSIGNED NULL,
    gross_milli BIGINT UNSIGNED NOT NULL,
    stock_milli BIGINT UNSIGNED NOT NULL,
    incoming_milli BIGINT UNSIGNED NOT NULL,
    shortage_milli BIGINT UNSIGNED NOT NULL,
    supply_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (run_id,ref),
    KEY (org_id,run_id,item_id,needed_on),
    FOREIGN KEY (org_id,run_id) REFERENCES mrp_runs(org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    FOREIGN KEY (org_id,bom_id) REFERENCES boms(org_id,id),
    FOREIGN KEY (org_id,sales_demand_id) REFERENCES sales_demands(org_id,id),
    CHECK (gross_milli=stock_milli+incoming_milli+shortage_milli)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mrp_pegs (
    org_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    requirement_ref CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_kind ENUM('stock','purchase','work') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    available_on DATE NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (run_id,requirement_ref,source_kind,source_id),
    FOREIGN KEY (org_id,run_id) REFERENCES mrp_runs(org_id,id),
    FOREIGN KEY (run_id,requirement_ref) REFERENCES mrp_requirements(run_id,ref),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mrp_proposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    requirement_ref CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind ENUM('purchase','manufacture','subcontract') NOT NULL,
    starts_on DATE NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    destination_id BIGINT UNSIGNED NULL,
    blocked_reason VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY (run_id,requirement_ref),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,run_id) REFERENCES mrp_runs(org_id,id),
    FOREIGN KEY (run_id,requirement_ref) REFERENCES mrp_requirements(run_id,ref),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mrp_differences (
    org_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    requirement_ref CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    root_kind ENUM('sale','work') NOT NULL,
    root_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    previous_values JSON NULL,
    current_values JSON NULL,
    PRIMARY KEY (run_id,requirement_ref),
    FOREIGN KEY (org_id,run_id) REFERENCES mrp_runs(org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    CHECK (previous_values IS NOT NULL OR current_values IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS planning_supplies (
    org_id BIGINT UNSIGNED NOT NULL,
    source_kind ENUM('purchase','work') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    available_on DATE NOT NULL,
    remaining_milli BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,source_kind,source_id),
    KEY (org_id,item_id,available_on),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS planning_work_needs (
    org_id BIGINT UNSIGNED NOT NULL,
    id BIGINT UNSIGNED NOT NULL,
    work_id BIGINT UNSIGNED NOT NULL,
    sales_demand_id BIGINT UNSIGNED NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    needed_on DATE NOT NULL,
    remaining_milli BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,id),
    KEY (org_id,needed_on,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    FOREIGN KEY (org_id,sales_demand_id) REFERENCES sales_demands(org_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    remaining_milli BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,id),
    KEY (org_id,lot_id,location_id,remaining_milli),
    KEY (org_id,demand_id,remaining_milli),
    FOREIGN KEY (org_id,demand_id) REFERENCES sales_demands(org_id,id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id)
) ENGINE=InnoDB;
