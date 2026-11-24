<?php

declare(strict_types=1);

namespace ManufacturingErp;

use RuntimeException;

final class Migrations
{
    private const LOCK = 'manufacturing-erp-schema-migration';

    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    public function migrate(): array
    {
        $lock = $this->db->row('SELECT GET_LOCK(CONCAT(?,LEFT(SHA2(DATABASE(),256),24)), 15) AS acquired', [self::LOCK]);
        if ((int) $lock['acquired'] !== 1) {
            throw new RuntimeException('Could not acquire the schema migration lock.');
        }
        try {
            $this->db->run('CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) PRIMARY KEY,
                digest CHAR(64) NOT NULL,
                installed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB');
            $files = $this->files();
            $existing = $this->existing();
            $this->checkHistory($files, $existing);
            $applied = [];
            foreach ($files as $version => $file) {
                if (isset($existing[$version])) {
                    continue;
                }
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException('Unable to read migration ' . $version);
                }
                foreach (preg_split('/;\s*(?:\r?\n|\z)/', $sql, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
                    $this->db->run($statement);
                }
                $this->db->run('INSERT INTO schema_migrations (version,digest) VALUES (?,?)', [$version, hash('sha256', $sql)]);
                $applied[] = $version;
            }
            return $applied;
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(CONCAT(?,LEFT(SHA2(DATABASE(),256),24)))', [self::LOCK]);
        }
    }

    public function ready(): bool
    {
        $files = $this->files();
        $existing = $this->existing();
        $this->checkHistory($files, $existing);
        return count($files) === count($existing);
    }

    private function files(): array
    {
        $files = [];
        foreach (glob($this->directory . '/*.sql') ?: [] as $file) {
            $files[basename($file, '.sql')] = $file;
        }
        if ($files === []) {
            throw new RuntimeException('No schema migrations found.');
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function existing(): array
    {
        return array_column($this->db->rows('SELECT version,digest FROM schema_migrations ORDER BY version'), 'digest', 'version');
    }

    private function checkHistory(array $files, array $existing): void
    {
        $prefix = array_slice(array_keys($files), 0, count($existing));
        if ($prefix !== array_keys($existing)) {
            throw new RuntimeException('Database migration history does not match this application version.');
        }
        foreach ($existing as $version => $digest) {
            if (!hash_equals($digest, hash_file('sha256', $files[$version]))) {
                throw new RuntimeException('Installed migration has changed: ' . $version);
            }
        }
    }
}
