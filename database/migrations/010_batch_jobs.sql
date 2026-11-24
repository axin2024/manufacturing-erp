CREATE TABLE IF NOT EXISTS batch_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('items','bom','opening','mrp') NOT NULL,
    format_version SMALLINT UNSIGNED NOT NULL,
    state ENUM('queued','running','completed','partial','failed','cancelled') NOT NULL DEFAULT 'queued',
    total_rows SMALLINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    KEY (state,id),
    FOREIGN KEY (org_id) REFERENCES organizations(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    CHECK (total_rows>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS batch_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    row_no SMALLINT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    state ENUM('pending','succeeded','rejected','retryable','cancelled') NOT NULL DEFAULT 'pending',
    result JSON NULL,
    error_text VARCHAR(255) NULL,
    executor_id BIGINT UNSIGNED NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY (org_id,id),
    UNIQUE KEY (job_id,row_no),
    KEY (job_id,state,row_no),
    FOREIGN KEY (org_id,job_id) REFERENCES batch_jobs(org_id,id),
    FOREIGN KEY (executor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS batch_attempts (
    org_id BIGINT UNSIGNED NOT NULL,
    row_id BIGINT UNSIGNED NOT NULL,
    ordinal SMALLINT UNSIGNED NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('succeeded','rejected','retryable') NOT NULL,
    result JSON NULL,
    error_text VARCHAR(255) NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (org_id,row_id,ordinal),
    FOREIGN KEY (org_id,row_id) REFERENCES batch_rows(org_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
