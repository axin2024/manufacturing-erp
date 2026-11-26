CREATE TABLE IF NOT EXISTS approval_delegations (
    org_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('plan','work') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    state ENUM('pending','revoked','completed') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (org_id,kind,object_id),
    KEY (org_id,assigned_to,state,expires_at),
    FOREIGN KEY (org_id) REFERENCES organizations(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    CHECK (assigned_by<>assigned_to)
) ENGINE=InnoDB;
