CREATE TABLE IF NOT EXISTS login_throttle (
    address_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    window_start BIGINT UNSIGNED NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    KEY (window_start)
) ENGINE=InnoDB;
