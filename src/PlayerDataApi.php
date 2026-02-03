<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use Exception;
use InvalidArgumentException;
use Logger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;

final class PlayerDataApi {
    private Sqlite $db;

    /**
     * @throws PlayerDataException When database operations fail
     */
    public function getUuid(string $username): ?UuidInterface {
        try {
            $stmt = $this->db->prepare("SELECT uuid FROM UsernameData WHERE username = :username");
            $this->db->bind($stmt, ":username", $username, SQLITE3_TEXT);
            $rows = $this->db->result($stmt);
            if (count($rows) === 0) {
                $o = null;
            } else {
                $row = $rows[array_key_first($rows)];
                if (!is_string($row["uuid"] ?? null)) {
                    throw new PlayerDataException("uuid column is malformed: `$row[uuid]`");
                }
                try {
                    $uuid = Uuid::fromBytes($row["uuid"]);
                } catch (InvalidArgumentException $e) {
                    throw new PlayerDataException("Uuid constructor: " . $e->getMessage(), previous: $e);
                }
                $o = $uuid;
            }
        } catch (SqliteException $e) {
            throw new PlayerDataException("Sqlite exception: " . $e->getMessage(), previous: $e);
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }
        return $o;
    }

    /**
     * @throws PlayerDataException When database operations fail
     */
    public function getPlayerData(UuidInterface|string $id): ?PlayerData {
        if (is_string($id)) {
            $uuid = $this->getUuid($id)?->getBytes();
            if ($uuid === null) {
                return null;
            }
        } else {
            $uuid = $id->getBytes();
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM PlayerData WHERE uuid = :uuid");
            $this->db->bind($stmt, ":uuid", $uuid, SQLITE3_BLOB);
            $rows = $this->db->result($stmt);
            if (count($rows) === 0) {
                $o = null;
            } else {
                $row = $rows[array_key_first($rows)];
                if (!is_string($row["uuid"] ?? null) || !is_string($row["username"] ?? null) || !is_int($row["firstSeen"] ?? null) || !is_int($row["lastSeen"] ?? null)) {
                    throw new PlayerDataException("Malformed columns: `$row[uuid]` `$row[username]` `$row[firstSeen]` `$row[lastseen]`");
                }
                try {
                    $uuid = Uuid::fromBytes($row["uuid"]);
                } catch (InvalidArgumentException $e) {
                    throw new PlayerDataException("Uuid constructor: " . $e->getMessage(), previous: $e);
                }
                $o = new PlayerData($uuid, $row["username"], $row["firstSeen"], $row["lastSeen"]);
            }
        } catch (SqliteException $e) {
            throw new PlayerDataException("Sqlite exception: " . $e->getMessage(), previous: $e);
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }
        return $o;
    }

    private function handleLogin(Logger $logger, PlayerLoginEvent $ev): void {
        try {
            $this->db->run("BEGIN;");
            $stmt = $this->db->prepare("INSERT INTO PlayerData VALUES (:uuid, :username, :firstSeen, :lastSeen) "
                . "ON CONFLICT(uuid) DO UPDATE SET username = :username, lastSeen = :lastSeen");
            $this->db->bind($stmt, ":uuid", $ev->getPlayer()->getUniqueId()->getBytes(), SQLITE3_BLOB);
            $this->db->bind($stmt, ":username", $ev->getPlayer()->getName(), SQLITE3_TEXT);
            $t = time();
            $this->db->bind($stmt, ":firstSeen", $t, SQLITE3_INTEGER);
            $this->db->bind($stmt, ":lastSeen", $t, SQLITE3_INTEGER);
            $this->db->execute($stmt);

            $stmt2 = $this->db->prepare("INSERT INTO UsernameData VALUES (:username, :uuid) "
                . "ON CONFLICT(username) DO UPDATE SET uuid = :uuid");
            $this->db->bind($stmt2, ":username", $ev->getPlayer()->getName(), SQLITE3_TEXT);
            $this->db->bind($stmt2, ":uuid", $ev->getPlayer()->getUniqueId()->getBytes(), SQLITE3_BLOB);
            $this->db->execute($stmt2);
            $this->db->run("COMMIT;");
        } catch (SqliteException $e) {
            try {
                $this->db->run("ROLLBACK;");
            } catch (SqliteException $_ignored) {
            }
            $logger->critical("Cannot update players.db");
            $logger->critical("username: {$ev->getPlayer()->getName()}, uuid: {$ev->getPlayer()->getUniqueId()->toString()}");
            $logger->logException($e);
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
            if (isset($stmt2)) {
                $stmt2->close();
            }
        }
    }

    /**
     * @internal
     * @throws Exception When opening the database fails
     */
    public function __construct(Main $plugin) {
        $dbpath = "{$plugin->getDataFolder()}players.db";

        $db = null;
        try {
            if (file_exists($dbpath)) {
                $db = new Sqlite($dbpath, SQLITE3_OPEN_READWRITE);
            } else {
                $db = new Sqlite($dbpath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                $db->run("CREATE TABLE IF NOT EXISTS PlayerData "
                    . "(uuid BLOB NOT NULL PRIMARY KEY, username TEXT COLLATE NOCASE NOT NULL, firstSeen INT NOT NULL, lastSeen INT NOT NULL)");
                $db->run("CREATE TABLE IF NOT EXISTS UsernameData (username TEXT COLLATE NOCASE NOT NULL PRIMARY KEY, uuid BLOB NOT NULL)");
            }
        } catch (SqliteException $e) {
            if ($db !== null) {
                $db->close();
            }
            throw $e;
        }
        $this->db = $db;

        $plugin->getServer()->getPluginManager()->registerEvent(PlayerLoginEvent::class, fn($e) => $this->handleLogin($plugin->getLogger(), $e), EventPriority::MONITOR, $plugin);
    }

    /**
     * @internal
     */
    public function close(): void {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
