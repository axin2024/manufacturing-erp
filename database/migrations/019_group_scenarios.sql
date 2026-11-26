CREATE TABLE IF NOT EXISTS network_scenarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    revision INT UNSIGNED NOT NULL,
    as_of DATE NOT NULL,
    horizon_end DATE NOT NULL,
    snapshot JSON NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (tenant_id,org_id) REFERENCES organizations(tenant_id,id),
    FOREIGN KEY (org_id,parent_id) REFERENCES network_scenarios(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (as_of<=horizon_end)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_plan_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scenario_id BIGINT UNSIGNED NOT NULL,
    source_site BIGINT UNSIGNED NOT NULL,
    destination_site BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    source_item_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('transfer','manufacture','subcontract','uncovered') NOT NULL,
    needed_on DATE NOT NULL,
    promised_on DATE NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    unit_cost_minor BIGINT UNSIGNED NOT NULL,
    alternatives JSON NOT NULL,
    UNIQUE KEY (scenario_id,id),
    KEY (destination_site,scenario_id,id),
    FOREIGN KEY (scenario_id) REFERENCES network_scenarios(id),
    FOREIGN KEY (source_site,source_item_id) REFERENCES network_site_items(site_id,item_id),
    FOREIGN KEY (destination_site,item_id) REFERENCES network_site_items(site_id,item_id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;
