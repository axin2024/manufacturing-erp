CREATE TABLE IF NOT EXISTS network_site_signers (
    org_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    enabled BOOLEAN NOT NULL,
    PRIMARY KEY (site_id,user_id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_plan_submissions (
    scenario_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (line_id),
    FOREIGN KEY (scenario_id,line_id) REFERENCES network_plan_lines(scenario_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_plan_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    line_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    org_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    accepted BOOLEAN NOT NULL,
    promised_on DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY (line_id,site_id,id),
    FOREIGN KEY (line_id) REFERENCES network_plan_submissions(line_id),
    FOREIGN KEY (org_id,site_id) REFERENCES network_sites(org_id,id),
    FOREIGN KEY (org_id,department_id) REFERENCES departments(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
