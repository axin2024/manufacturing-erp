CREATE TABLE IF NOT EXISTS network_pilots (
    tenant_id BIGINT UNSIGNED PRIMARY KEY,
    state ENUM('active','draining') NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
