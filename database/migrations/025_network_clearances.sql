CREATE TABLE IF NOT EXISTS network_clearances (
    transfer_id BIGINT UNSIGNED PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    posted_on DATE NOT NULL,
    evidence VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (transfer_id) REFERENCES network_transfer_orders(id),
    FOREIGN KEY (org_id) REFERENCES organizations(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
