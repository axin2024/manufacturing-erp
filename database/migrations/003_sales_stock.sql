CREATE TABLE IF NOT EXISTS sales_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(40) NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    state ENUM('draft','frozen','cancelled','completed') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    frozen_at DATETIME(6) NULL,
    UNIQUE KEY (org_id,number),
    UNIQUE KEY (org_id,id),
    KEY (org_id,state,id),
    FOREIGN KEY (org_id,customer_id) REFERENCES parties(org_id,id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS sales_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (order_id,line_no),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,order_id) REFERENCES sales_orders(org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_demands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    delivery_no SMALLINT UNSIGNED NOT NULL,
    due_on DATE NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    allocated_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    dispatched_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    state ENUM('draft','released','fulfilled','cancelled') NOT NULL DEFAULT 'draft',
    UNIQUE KEY (line_id,delivery_no,version),
    UNIQUE KEY (org_id,id),
    KEY (org_id,state,due_on,id),
    FOREIGN KEY (org_id,line_id) REFERENCES sales_lines(org_id,id),
    CHECK (qty_milli>0 AND dispatched_milli<=allocated_milli AND allocated_milli<=qty_milli)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS demand_dispositions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    source_kind VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    state ENUM('open','resolved') NOT NULL DEFAULT 'open',
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,demand_id,source_kind,source_id),
    FOREIGN KEY (org_id,demand_id) REFERENCES sales_demands(org_id,id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS demand_commitments (
    org_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    source_kind ENUM('purchase','work','shipping') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    state ENUM('committed','started','released','completed') NOT NULL DEFAULT 'committed',
    PRIMARY KEY (org_id,demand_id,source_kind,source_id),
    KEY (org_id,source_kind,source_id),
    FOREIGN KEY (org_id,demand_id) REFERENCES sales_demands(org_id,id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_lots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    lot_code VARCHAR(40) NOT NULL,
    origin_kind VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    origin_id BIGINT UNSIGNED NULL,
    source_uom VARCHAR(24) NOT NULL,
    source_qty_milli BIGINT UNSIGNED NOT NULL,
    factor_milli BIGINT UNSIGNED NOT NULL,
    conversion_id BIGINT UNSIGNED NULL,
    unit_cost_minor BIGINT UNSIGNED NOT NULL,
    received_on DATE NOT NULL,
    UNIQUE KEY (org_id,item_id,lot_code),
    UNIQUE KEY (org_id,id),
    KEY (org_id,item_id,received_on,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    FOREIGN KEY (org_id,conversion_id) REFERENCES unit_conversions(org_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS stock_balances (
    org_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    quality ENUM('available','pending','rejected','rework') NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,lot_id,location_id,quality),
    KEY (org_id,quality,location_id,lot_id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    quality ENUM('available','pending','rejected','rework') NOT NULL,
    delta_milli BIGINT NOT NULL,
    event_kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_id BIGINT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    posted_on DATE NOT NULL,
    period_start DATE NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (org_id,lot_id,id),
    KEY (org_id,event_kind,event_id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    FOREIGN KEY (org_id,period_start) REFERENCES fiscal_periods(org_id,period_start),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (delta_milli<>0)
) ENGINE=InnoDB;
