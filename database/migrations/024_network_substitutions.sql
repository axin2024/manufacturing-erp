CREATE TABLE IF NOT EXISTS network_substitutions (
    lane_id BIGINT UNSIGNED NOT NULL,
    source_site BIGINT UNSIGNED NOT NULL,
    source_item_id BIGINT UNSIGNED NOT NULL,
    destination_site BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    enabled BOOLEAN NOT NULL,
    revision INT UNSIGNED NOT NULL,
    evidence VARCHAR(255) NOT NULL,
    PRIMARY KEY (lane_id,item_id,source_item_id),
    FOREIGN KEY (lane_id) REFERENCES network_lanes(id),
    FOREIGN KEY (source_site,source_item_id) REFERENCES network_site_items(site_id,item_id),
    FOREIGN KEY (destination_site,item_id) REFERENCES network_site_items(site_id,item_id),
    CHECK (source_item_id<>item_id AND revision>0)
) ENGINE=InnoDB;
