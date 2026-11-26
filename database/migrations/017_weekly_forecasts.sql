CREATE TABLE IF NOT EXISTS network_forecast_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    batch_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,batch_code),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (org_id) REFERENCES organizations(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_forecast_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED NOT NULL,
    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (batch_id,site_id,item_id,week_start),
    KEY (site_id,item_id,week_start,id),
    KEY (org_id,id),
    FOREIGN KEY (org_id,batch_id) REFERENCES network_forecast_batches(org_id,id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id),
    FOREIGN KEY (site_id,item_id) REFERENCES network_site_items(site_id,item_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_demand_sites (
    org_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,demand_id),
    KEY (site_id,demand_id),
    FOREIGN KEY (org_id,demand_id) REFERENCES sales_demands(org_id,id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id)
) ENGINE=InnoDB;
