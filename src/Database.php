<?php

declare(strict_types=1);

namespace ManufacturingErp;

use Generator;
use LogicException;
use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private int $savepoint = 0;

    private function __construct(private readonly PDO $connection)
    {
    }

    public static function connect(): self
    {
        $dsn = getenv('ERP_DSN') ?: '';
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/(?:^mysql:|;)charset=utf8mb4(?:;|$)/i', $dsn)) {
            throw new LogicException('ERP_DSN must be a MySQL DSN with charset=utf8mb4.');
        }
        $connection = new PDO($dsn, getenv('ERP_DB_USER') ?: '', (string) getenv('ERP_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $connection->exec("SET time_zone = '+00:00'");
        $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return new self($connection);
    }

    public function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public function row(string $sql, array $parameters = []): ?array
    {
        $row = $this->run($sql, $parameters)->fetch();
        return $row === false ? null : $row;
    }

    public function rows(string $sql, array $parameters = []): array
    {
        return $this->run($sql, $parameters)->fetchAll();
    }

    public function stream(string $sql, array $parameters = []): Generator
    {
        $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        try {
            $statement = $this->run($sql, $parameters);
            while (($row = $statement->fetch()) !== false) {
                yield $row;
            }
        } finally {
            $statement?->closeCursor();
            $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    public function insert(string $sql, array $parameters): int
    {
        $this->run($sql, $parameters);
        return (int) $this->connection->lastInsertId();
    }

    public function insertRows(string $insert, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $tuple = '(' . implode(',', array_fill(0, count($rows[0]), '?')) . ')';
        foreach (array_chunk($rows, 250) as $chunk) {
            $parameters = [];
            foreach ($chunk as $row) {
                array_push($parameters, ...$row);
            }
            $this->run($insert . ' VALUES ' . implode(',', array_fill(0, count($chunk), $tuple)), $parameters);
        }
    }

    public function transaction(callable $work): mixed
    {
        if ($this->connection->inTransaction()) {
            $name = 'erp_' . ++$this->savepoint;
            $this->connection->exec('SAVEPOINT ' . $name);
            try {
                $result = $work();
                $this->connection->exec('RELEASE SAVEPOINT ' . $name);
                return $result;
            } catch (Throwable $error) {
                if ($this->connection->inTransaction()) {
                    $this->connection->exec('ROLLBACK TO SAVEPOINT ' . $name);
                    $this->connection->exec('RELEASE SAVEPOINT ' . $name);
                }
                throw $error;
            }
        }
        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }
}
