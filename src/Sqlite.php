<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use Exception;
use SQLite3;
use SQLite3Stmt;

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
            throw new SqliteException("Cannot open DB: {$e->getMessage()}", previous: $e);
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
            throw new SqliteException("Failed to prepare `$query`");
        }
        return $stmt;
    }

    public function bind(SQLite3Stmt $stmt, string $var, mixed $val, int $type): void {
        if (!$stmt->bindValue($var, $val, $type)) {
            throw new SqliteException("Failed to bind `$var` to statement: `{$stmt->getSQL()}`");
        }
    }

    /**
     * @return array<array<mixed>>
     */
    public function result(SQLite3Stmt $stmt): array {
        $result = $stmt->execute();
        if ($result === false) {
            throw new SqliteException("Failed to execute statement: `{$stmt->getSQL()}`");
        }
        $rows = [];
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !==  false) {
            $rows[] = $row;
        }
        $result->finalize();
        return $rows;
    }

    public function execute(SQLite3Stmt $stmt): void {
        $result = $stmt->execute();
        if ($result === false) {
            throw new SqliteException("Failed to execute statement: `{$stmt->getSQL()}`");
        }
    }

    public function close(): void {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
