<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use Logger;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionProperty;
use SimpleLogger;
use Symfony\Component\Filesystem\Path;

class PlayerDataApiTest extends TestCase {
    private static string $dir;
    private static string $path;
    private static Logger $logger;
    private PlayerDataApi $api;
    private Sqlite $db;

    #[BeforeClass]
    public static function setPathAndLogger(): void {
        self::$dir = self::tempdir(new ReflectionClass(self::class)->getShortName());
        self::$path = Path::join(self::$dir, 'players.db');
        self::$logger = new class extends SimpleLogger {
            public function log($level, $message) {}
        };
    }

    #[Before(20)]
    public function setApi(): void {
        $this->api = new PlayerDataApi(self::$path, self::$logger);
        $this->db = new ReflectionProperty($this->api::class, 'db')->getValue($this->api);
    }

    #[Before(10)]
    public function startTransaction(): void {
        $this->db->run('BEGIN;');
    }

    #[After(20)]
    public function endTransaction(): void {
        $this->db->run('ROLLBACK;');
    }

    #[After(10)]
    public function closeApi(): void {
        if (isset($this->api)) {
            $this->api->close();
            unset($this->api);
        }
    }

    #[AfterClass]
    public static function deletePath(): void {
        self::rrmdir(self::$dir);
    }

    public function testCreatesDatabaseFile(): void {
        $this->assertTrue(is_file(self::$path));
    }

    public function testCreatesEntriesOnFirstLogin(): void {
        $uuid = Uuid::fromInteger('123');
        $username = 'test';
        $this->updatePlayerData($uuid, $username, time());

        $this->assertTrue($this->api->getUuid($username)?->equals($uuid));
        $this->assertSame($this->api->getPlayerData($uuid)?->username, $username);
    }

    public function testUpdatesEntriesOnNewUsername(): void {
        $uuid = Uuid::fromInteger('123');
        $username = 'test';
        $this->updatePlayerData($uuid, $username, time());

        $newUsername = 'test2';
        $this->updatePlayerData($uuid, $newUsername, time());

        $this->assertTrue($this->api->getUuid($newUsername)?->equals($uuid));
        $this->assertSame($this->api->getPlayerData($uuid)?->username, $newUsername);
    }

    public function testReturnsCorrectDataEntry(): void {
        $uuid = Uuid::fromInteger('123');
        $username = 'test';
        $this->updatePlayerData($uuid, $username, time());

        $uuid = Uuid::fromInteger('123');
        $username = 'test2';
        $this->updatePlayerData($uuid, $username, time());

        $uuid = Uuid::fromInteger('1234');
        $username = 'test3';
        $this->updatePlayerData($uuid, $username, time());

        $this->assertTrue($this->api->getUuid($username)?->equals($uuid));
        $this->assertSame($this->api->getPlayerData($uuid)?->username, $username);
    }

    public function testErrorsOnInvalidUuidInDatabase(): void {
        $this->expectException(PlayerDataException::class);
        $username = 'test4';

        $stmt = $this->db->prepare('INSERT INTO UsernameData (username, uuid) VALUES (:username, :uuid)');
        $this->db->bind($stmt, ':uuid', 'garbage', SQLITE3_BLOB);
        $this->db->bind($stmt, ':username', $username, SQLITE3_TEXT);
        $this->db->execute($stmt);
        $stmt->close();

        $uuid = $this->api->getUuid($username);
    }

    private function updatePlayerData(UuidInterface $uuid, string $username, int $time): void {
        $stmt = $this->db->prepare(
            'INSERT INTO PlayerData VALUES (:uuid, :username, :firstSeen, :lastSeen) '
            . 'ON CONFLICT(uuid) DO UPDATE SET username = :username, lastSeen = :lastSeen',
        );
        $this->db->bind($stmt, ':uuid', $uuid->getBytes(), SQLITE3_BLOB);
        $this->db->bind($stmt, ':username', $username, SQLITE3_TEXT);
        $this->db->bind($stmt, ':firstSeen', $time, SQLITE3_INTEGER);
        $this->db->bind($stmt, ':lastSeen', $time, SQLITE3_INTEGER);
        $this->db->execute($stmt);
        $stmt->close();

        $stmt2 = $this->db->prepare('INSERT INTO UsernameData VALUES (:username, :uuid) '
        . 'ON CONFLICT(username) DO UPDATE SET uuid = :uuid');
        $this->db->bind($stmt2, ':username', $username, SQLITE3_TEXT);
        $this->db->bind($stmt2, ':uuid', $uuid->getBytes(), SQLITE3_BLOB);
        $this->db->execute($stmt2);
        $stmt2->close();
    }

    private static function tempdir(string $prefix, int $mode = 0o700) {
        do {
            $path = Path::join(sys_get_temp_dir(), $prefix . Uuid::uuid4()->toString());
        } while (!mkdir($path, $mode));
        return $path;
    }

    private static function rrmdir(string $src) {
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if ($file !== '.' && $file !== '..') {
                $full = $src . '/' . $file;
                if (is_dir($full)) {
                    rrmdir($full);
                } else {
                    unlink($full);
                }
            }
        }
        closedir($dir);
        rmdir($src);
    }
}
