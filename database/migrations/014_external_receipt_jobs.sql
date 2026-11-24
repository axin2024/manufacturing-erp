ALTER TABLE batch_jobs
    MODIFY kind ENUM('items','bom','opening','mrp','receipt_match') NOT NULL,
    MODIFY state ENUM('queued','running','completed','partial','failed','cancelled','waiting_external') NOT NULL DEFAULT 'queued';

ALTER TABLE batch_rows
    MODIFY state ENUM('pending','succeeded','rejected','retryable','cancelled','waiting_external') NOT NULL DEFAULT 'pending';

ALTER TABLE batch_attempts
    MODIFY state ENUM('succeeded','rejected','retryable','waiting_external') NOT NULL;
