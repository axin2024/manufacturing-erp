CREATE TABLE IF NOT EXISTS inspection_rule_versions (
    org_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    rule_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (org_id,case_id),
    FOREIGN KEY (org_id,case_id) REFERENCES inspection_cases(org_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_cost_policies (
    org_id BIGINT UNSIGNED NOT NULL,
    work_id BIGINT UNSIGNED NOT NULL,
    policy_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (org_id,work_id),
    FOREIGN KEY (org_id,work_id) REFERENCES work_orders(org_id,id)
) ENGINE=InnoDB;
