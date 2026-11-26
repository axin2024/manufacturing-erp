CREATE TABLE IF NOT EXISTS network_work_sites (
    org_id BIGINT UNSIGNED NOT NULL,
    work_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,work_id),
    KEY (site_id,work_id),
    FOREIGN KEY (org_id,work_id) REFERENCES work_orders(org_id,id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_ownership_cursors (
    org_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('location','work') NOT NULL,
    last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (org_id,kind),
    FOREIGN KEY (org_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_ownership_issues (
    org_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('location','work') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    reason ENUM('no_site','ambiguous','item_unmapped') NOT NULL,
    detected_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    resolved_at DATETIME(6) NULL,
    resolved_by BIGINT UNSIGNED NULL,
    site_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (org_id,kind,entity_id),
    KEY (org_id,resolved_at,kind,entity_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id)
) ENGINE=InnoDB;
