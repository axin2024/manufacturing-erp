CREATE TABLE IF NOT EXISTS network_sites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    org_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    UNIQUE KEY (org_id,code),
    UNIQUE KEY (tenant_id,id),
    UNIQUE KEY (org_id,id),
    FOREIGN KEY (tenant_id,org_id) REFERENCES organizations(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS network_calendar (
    site_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    working BOOLEAN NOT NULL,
    PRIMARY KEY (site_id,work_date),
    FOREIGN KEY (site_id) REFERENCES network_sites(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_lanes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    source_site BIGINT UNSIGNED NOT NULL,
    destination_site BIGINT UNSIGNED NOT NULL,
    lead_days SMALLINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    UNIQUE KEY (source_site,destination_site),
    FOREIGN KEY (tenant_id,source_site) REFERENCES network_sites(tenant_id,id),
    FOREIGN KEY (tenant_id,destination_site) REFERENCES network_sites(tenant_id,id),
    CHECK (source_site<>destination_site AND lead_days<=365)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_site_items (
    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (site_id,item_id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id),
    FOREIGN KEY (org_id,item_id) REFERENCES org_items(org_id,item_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_location_sites (
    org_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id,location_id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id),
    FOREIGN KEY (org_id,location_id) REFERENCES locations(org_id,id)
) ENGINE=InnoDB;
