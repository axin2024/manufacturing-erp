CREATE TABLE IF NOT EXISTS items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    base_uom VARCHAR(24) NOT NULL,
    UNIQUE KEY (tenant_id,code),
    UNIQUE KEY (tenant_id,id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS org_items (
    org_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    sourcing ENUM('purchase','manufacture','subcontract') NOT NULL,
    standard_cost_minor BIGINT UNSIGNED NOT NULL,
    lead_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (org_id,item_id),
    FOREIGN KEY (tenant_id,org_id) REFERENCES organizations(tenant_id,id),
    FOREIGN KEY (tenant_id,item_id) REFERENCES items(tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS unit_conversions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    uom VARCHAR(24) NOT NULL,
    effective_on DATE NOT NULL,
    factor_milli BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,item_id,uom,effective_on),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    CHECK (factor_milli>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(140) NOT NULL,
    UNIQUE KEY (org_id,code),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS parties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    kind ENUM('customer','supplier','both') NOT NULL,
    UNIQUE KEY (org_id,code),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS work_centers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(140) NOT NULL,
    UNIQUE KEY (org_id,code),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,department_id) REFERENCES departments(org_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS center_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    center_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    UNIQUE KEY (org_id,center_id,starts_at),
    FOREIGN KEY (org_id,center_id) REFERENCES work_centers(org_id,id),
    CHECK (starts_at < ends_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    effective_on DATE NOT NULL,
    published_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,item_id,effective_on),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    FOREIGN KEY (published_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS route_steps (
    org_id BIGINT UNSIGNED NOT NULL,
    route_id BIGINT UNSIGNED NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    center_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(140) NOT NULL,
    setup_minutes SMALLINT UNSIGNED NOT NULL,
    run_seconds_per_unit INT UNSIGNED NOT NULL,
    PRIMARY KEY (route_id,sequence_no),
    FOREIGN KEY (org_id,route_id) REFERENCES routes(org_id,id),
    FOREIGN KEY (org_id,center_id) REFERENCES work_centers(org_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS boms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    effective_on DATE NOT NULL,
    published_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,item_id,effective_on),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    FOREIGN KEY (published_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bom_components (
    org_id BIGINT UNSIGNED NOT NULL,
    bom_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    qty_per_milli BIGINT UNSIGNED NOT NULL,
    source_uom VARCHAR(24) NOT NULL,
    factor_milli BIGINT UNSIGNED NOT NULL,
    loss_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bom_id,item_id),
    FOREIGN KEY (org_id,bom_id) REFERENCES boms(org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id),
    CHECK (qty_per_milli>0 AND loss_bps<10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS bom_alternatives (
    org_id BIGINT UNSIGNED NOT NULL,
    bom_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    alternative_item_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bom_id,item_id,alternative_item_id),
    FOREIGN KEY (org_id,bom_id) REFERENCES boms(org_id,id),
    FOREIGN KEY (bom_id,item_id) REFERENCES bom_components(bom_id,item_id),
    FOREIGN KEY (org_id,alternative_item_id) REFERENCES org_items(org_id,item_id),
    CHECK (item_id<>alternative_item_id)
) ENGINE=InnoDB;
