CREATE TABLE IF NOT EXISTS network_warehouse_grants (
    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    enabled BOOLEAN NOT NULL,
    PRIMARY KEY (site_id,user_id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_shipments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL,
    org_id BIGINT UNSIGNED NOT NULL,
    local_ref VARCHAR(40) NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    received_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lost_milli BIGINT UNSIGNED NOT NULL DEFAULT 0,
    carrying_minor BIGINT UNSIGNED NOT NULL,
    transfer_minor BIGINT UNSIGNED NOT NULL,
    posted_on DATE NOT NULL,
    movement_id BIGINT UNSIGNED NULL,
    journal_id BIGINT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,local_ref),
    KEY (transfer_id,id),
    FOREIGN KEY (transfer_id) REFERENCES network_transfer_orders(id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    FOREIGN KEY (movement_id) REFERENCES stock_movements(id),
    FOREIGN KEY (org_id,journal_id) REFERENCES journals(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (received_milli+lost_milli<=qty_milli AND qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_id BIGINT UNSIGNED NOT NULL,
    org_id BIGINT UNSIGNED NOT NULL,
    local_ref VARCHAR(40) NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NULL,
    expected_milli BIGINT UNSIGNED NOT NULL,
    counted_milli BIGINT UNSIGNED NOT NULL,
    accepted_milli BIGINT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    posted_on DATE NOT NULL,
    movement_id BIGINT UNSIGNED NULL,
    journal_id BIGINT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY (org_id,local_ref),
    KEY (shipment_id,id),
    FOREIGN KEY (shipment_id) REFERENCES network_shipments(id),
    FOREIGN KEY (org_id,lot_id) REFERENCES stock_lots(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id),
    FOREIGN KEY (org_id,journal_id) REFERENCES journals(org_id,id),
    FOREIGN KEY (movement_id) REFERENCES stock_movements(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (expected_milli>0 AND accepted_milli<=counted_milli AND accepted_milli<=expected_milli)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_variances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL UNIQUE,
    site_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('short','over') NOT NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    explanation VARCHAR(255) NOT NULL,
    FOREIGN KEY (receipt_id) REFERENCES network_receipts(id),
    FOREIGN KEY (site_id) REFERENCES network_sites(id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_variance_resolutions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    variance_id BIGINT UNSIGNED NOT NULL,
    linked_receipt_id BIGINT UNSIGNED NULL,
    kind ENUM('received','returned','loss') NOT NULL,
    org_id BIGINT UNSIGNED NULL,
    local_ref VARCHAR(40) NULL,
    posted_on DATE NULL,
    journal_id BIGINT UNSIGNED NULL,
    amount_minor BIGINT UNSIGNED NULL,
    qty_milli BIGINT UNSIGNED NOT NULL,
    evidence VARCHAR(255) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,local_ref),
    FOREIGN KEY (org_id,journal_id) REFERENCES journals(org_id,id),
    FOREIGN KEY (variance_id) REFERENCES network_variances(id),
    FOREIGN KEY (linked_receipt_id) REFERENCES network_receipts(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (qty_milli>0)
) ENGINE=InnoDB;
