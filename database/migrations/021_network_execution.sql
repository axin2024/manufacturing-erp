CREATE TABLE IF NOT EXISTS network_transfer_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    line_id BIGINT UNSIGNED NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_site BIGINT UNSIGNED NOT NULL,
    destination_site BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    source_item_id BIGINT UNSIGNED NOT NULL,
    ordered_milli BIGINT UNSIGNED NOT NULL,
    shipped_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    received_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lost_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    promised_on DATE NOT NULL,
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    FOREIGN KEY (line_id) REFERENCES network_plan_submissions(line_id),
    FOREIGN KEY (tenant_id,source_site) REFERENCES network_sites(tenant_id,id),
    FOREIGN KEY (tenant_id,destination_site) REFERENCES network_sites(tenant_id,id),
    FOREIGN KEY (source_site,source_item_id) REFERENCES network_site_items(site_id,item_id),
    FOREIGN KEY (destination_site,item_id) REFERENCES network_site_items(site_id,item_id),
    CHECK (source_site<>destination_site AND received_milli+lost_milli<=shipped_milli AND shipped_milli<=ordered_milli)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_plan_executions (
    line_id BIGINT UNSIGNED PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NULL UNIQUE,
    work_id BIGINT UNSIGNED NULL UNIQUE,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (line_id) REFERENCES network_plan_submissions(line_id),
    FOREIGN KEY (transfer_id) REFERENCES network_transfer_orders(id),
    FOREIGN KEY (work_id) REFERENCES work_orders(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK ((transfer_id IS NULL) <> (work_id IS NULL))
) ENGINE=InnoDB;
