<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use armorshard\tsarray\TsArray;
use Exception;
use InvalidArgumentException;
use Logger;
use OutOfBoundsException;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\plugin\PluginBase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SQLite3Stmt;
use Throwable;
use UnexpectedValueException;

use function array_key_first;
use function count;
use function file_exists;
use function is_string;
use function sprintf;
use function time;

use const SQLITE3_BLOB;
use const SQLITE3_INTEGER;
use const SQLITE3_OPEN_CREATE;
use const SQLITE3_OPEN_READWRITE;
use const SQLITE3_TEXT;

final readonly class PlayerDataApi {
    private Sqlite $db;
    private SQLite3Stmt $selectUuidStmt;
    private SQLite3Stmt $selectDataStmt;
    private SQLite3Stmt $insertPlayerDataStmt;
    private SQLite3Stmt $insertUsernameDataStmt;

    /**
     * Get the UUID last associated with the given username.
     * @return ?UuidInterface The UUID or null if not found.
     * @throws PlayerDataException When database operations fail.
     */
    public function getUuid(string $username): ?UuidInterface {
        try {
            $this->selectUuidStmt->reset();
            $this->db->bind($this->selectUuidStmt, ':username', $username, SQLITE3_TEXT);
            $rows = $this->db->result($this->selectUuidStmt);
            if (count($rows) === 0) {
                return null;
            }
            $row = $rows[array_key_first($rows)];
            return Uuid::fromBytes(TsArray::getString($row, 'uuid'));
        } catch (SqliteException|OutOfBoundsException|UnexpectedValueException|InvalidArgumentException $e) {
            throw new PlayerDataException(
                sprintf('Failed to retrieve UUID for "%s": %s', $username, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * Get saved player data.
     * @param UuidInterface|string $uuidOrUsername The UUID or username of the player.
     * @throws PlayerDataException When database operations fail.
     */
    public function getPlayerData(UuidInterface|string $uuidOrUsername): ?PlayerData {
        if (is_string($uuidOrUsername)) {
            //username
            $uuid = $this->getUuid($uuidOrUsername)?->getBytes();
            if ($uuid === null) {
                return null;
            }
        } else {
            //uuid
            $uuid = $uuidOrUsername->getBytes();
        }
        try {
            $this->selectDataStmt->reset();
            $this->db->bind($this->selectDataStmt, ':uuid', $uuid, SQLITE3_BLOB);
            $rows = $this->db->result($this->selectDataStmt);
            if (count($rows) === 0) {
                return null;
            }
            $row = $rows[array_key_first($rows)];
            return new PlayerData(
                Uuid::fromBytes(TsArray::getString($row, 'uuid')),
                TsArray::getString($row, 'username'),
                TsArray::getInt($row, 'firstSeen'),
                TsArray::getInt($row, 'lastSeen'),
            );
        } catch (SqliteException|OutOfBoundsException|UnexpectedValueException|InvalidArgumentException $e) {
            throw new PlayerDataException(
                sprintf('Failed to get player data for %s: %s', (string) $uuidOrUsername, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * @internal
     * @throws Exception When opening the database fails
     */
    public function __construct(
        string $dbpath,
        private Logger $logger,
    ) {
        try {
            $db = null;
            if (file_exists($dbpath)) {
                $db = new Sqlite($dbpath, SQLITE3_OPEN_READWRITE);
            } else {
                $db = new Sqlite($dbpath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                $db->run(
                    'CREATE TABLE IF NOT EXISTS PlayerData '
                    . '(uuid BLOB NOT NULL PRIMARY KEY, username TEXT COLLATE NOCASE NOT NULL, firstSeen INT NOT NULL, lastSeen INT NOT NULL)',
                );
                $db->run(
                    'CREATE TABLE IF NOT EXISTS UsernameData (username TEXT COLLATE NOCASE NOT NULL PRIMARY KEY, uuid BLOB NOT NULL)',
                );
            }
            $db->run('PRAGMA journal_mode=WAL');
            $db->run('PRAGMA synchronous=NORMAL');

            $this->selectUuidStmt = $db->prepare('SELECT uuid FROM UsernameData WHERE username = :username');
            $this->selectDataStmt = $db->prepare('SELECT * FROM PlayerData WHERE uuid = :uuid');
            $this->insertPlayerDataStmt = $db->prepare(
                'INSERT INTO PlayerData VALUES (:uuid, :username, :firstSeen, :lastSeen) '
                . 'ON CONFLICT(uuid) DO UPDATE SET username = :username, lastSeen = :lastSeen',
            );
            $this->insertUsernameDataStmt = $db->prepare('INSERT INTO UsernameData VALUES (:username, :uuid) '
            . 'ON CONFLICT(username) DO UPDATE SET uuid = :uuid');
            $this->db = $db;
        } catch (Throwable $e) {
            if ($db !== null) {
                $db->close();
            }
            throw $e;
        }
    }

    /**
     * @internal
     */
    public function registerEvents(PluginBase $plugin): void {
        $plugin
            ->getServer()
            ->getPluginManager()
            ->registerEvent(PlayerLoginEvent::class, $this->handleLogin(...), EventPriority::MONITOR, $plugin);
    }

    private function handleLogin(PlayerLoginEvent $ev): void {
        $this->updatePlayerData($ev->getPlayer()->getUniqueId(), $ev->getPlayer()->getName(), time());
    }

    /**
     * @throws SqliteException
     */
    private function updatePlayerData(UuidInterface $uuid, string $username, int $time): void {
        try {
            $this->db->run('BEGIN;');
            $stmt = $this->insertPlayerDataStmt;
            $stmt->reset();
            $this->db->bind($stmt, ':uuid', $uuid->getBytes(), SQLITE3_BLOB);
            $this->db->bind($stmt, ':username', $username, SQLITE3_TEXT);
            $this->db->bind($stmt, ':firstSeen', $time, SQLITE3_INTEGER);
            $this->db->bind($stmt, ':lastSeen', $time, SQLITE3_INTEGER);
            $this->db->execute($stmt);

            $stmt2 = $this->insertUsernameDataStmt;
            $stmt2->reset();
            $this->db->bind($stmt2, ':username', $username, SQLITE3_TEXT);
            $this->db->bind($stmt2, ':uuid', $uuid->getBytes(), SQLITE3_BLOB);
            $this->db->execute($stmt2);
            $this->db->run('COMMIT;');
        } catch (SqliteException $e) {
            try {
                $this->db->run('ROLLBACK;');
            } catch (SqliteException $_ignored) { //@mago-expect lint:no-empty-catch-clause
            }
            $this->logger->critical(sprintf(
                'Cannot update players.db (username=%s uuid=%s): %s',
                $username,
                $uuid->toString(),
                $e->getMessage(),
            ));
            $this->logger->logException($e);
        }
    }

    /**
     * @internal
     */
    public function close(): void {
        $this->db->close();
    }
}
