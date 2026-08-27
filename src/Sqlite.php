<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use Exception;
use SQLite3;
use SQLite3Stmt;

use function sprintf;

use const SQLITE3_ASSOC;

/**
 * @internal
 */
final class Sqlite {
    private SQLite3 $db;

    public function __construct(string $dbpath, int $flags) {
        try {
            $this->db = new SQLite3($dbpath, $flags);
        } catch (Exception $e) {
            throw new SqliteException(sprintf('Failed to load database %s: %s', $dbpath, $e->getMessage()), 0, $e);
        }
    }

    public function run(string $query): void {
        if (!$this->db->exec($query)) {
            throw new SqliteException();
        }
    }

    public function prepare(string $query): SQLite3Stmt {
        $stmt = $this->db->prepare($query);
        if ($stmt === false) {
            throw new SqliteException(sprintf('Failed to prepare query "%s": %s', $query, $this->err()));
        }
        return $stmt;
    }

    public function bind(SQLite3Stmt $stmt, string $var, mixed $val, int $type): void {
        if (!$stmt->bindValue($var, $val, $type)) {
            throw new SqliteException(sprintf(
                'Failed to bind "%s" to statement "%s": %s',
                $var,
                $stmt->getSQL(),
                $this->err(),
            ));
        }
    }

    public function reset(SQLite3Stmt $stmt): void {
        if (!$stmt->reset()) {
            throw new SqliteException(sprintf('Failed to reset statement "%s": %s', $stmt->getSQL(), $this->err()));
        }
    }

    /**
     * @return array<array<mixed>>
     */
    public function result(SQLite3Stmt $stmt): array {
        $result = $stmt->execute();
        if ($result === false) {
            throw new SqliteException(sprintf('Failed to execute statement "%s": %s', $stmt->getSQL(), $this->err()));
        }
        $rows = [];
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }
        $result->finalize();
        return $rows;
    }

    public function execute(SQLite3Stmt $stmt): void {
        $result = $stmt->execute();
        if ($result === false) {
            throw new SqliteException(sprintf('Failed to execute statement "%s": %s', $stmt->getSQL(), $this->err()));
        }
        $result->finalize();
    }

    private function err(): string {
        return $this->db->lastErrorMsg();
    }

    public function close(): void {
        $this->db->close();
    }
}
