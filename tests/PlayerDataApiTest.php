<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use InvalidArgumentException;
use Logger;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\player\Player;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use SimpleLogger;
use Symfony\Component\Filesystem\Path;

class PlayerDataApiTest extends TestCase {
    private string $dir;
    private string $path;
    private Logger $logger;
    private PlayerDataApi $api;

    #[Before(30)]
    public function setPath(): void {
        $this->dir = $this->tempdir(new ReflectionClass($this)->getShortName());
        $this->path = Path::join($this->dir, 'players.db');
    }

    #[Before(20)]
    public function setLogger(): void {
        $this->logger = new class extends SimpleLogger {
            public function log($level, $message) {}
        };
    }

    #[Before(10)]
    public function setApi(): void {
        $this->api = new PlayerDataApi($this->path, $this->logger);
    }

    public function testCreatesDatabaseFile(): void {
        $this->assertTrue(is_file($this->path));
    }

    #[Depends('testCreatesDatabaseFile')]
    public function testCreatesEntriesOnFirstLogin(): void {
        $r = new ReflectionClass($this->api);
        $m = $r->getMethod('handleLogin');

        $username = 'test';
        $uuid = Uuid::fromInteger('123');
        $m->invoke($this->api, new PlayerLoginEvent($this->getPlayer($username, $uuid), ''));

        $this->assertTrue($this->api->getUuid($username)?->equals($uuid));
        $this->assertSame($this->api->getPlayerData($uuid)?->username, $username);
    }

    #[Depends('testCreatesEntriesOnFirstLogin')]
    public function testUpdatesEntriesOnNewUsername(): void {
        $r = new ReflectionClass($this->api);
        $m = $r->getMethod('handleLogin');

        $username = 'test2';
        $uuid = Uuid::fromInteger('123');
        $m->invoke($this->api, new PlayerLoginEvent($this->getPlayer($username, $uuid), ''));

        $this->assertTrue($this->api->getUuid($username)?->equals($uuid));
        $this->assertSame($this->api->getPlayerData($uuid)?->username, $username);
    }

    #[Depends('testUpdatesEntriesOnNewUsername')]
    public function testReturnsCorrectDataEntry(): void {
        $r = new ReflectionClass($this->api);
        $m = $r->getMethod('handleLogin');

        $username = 'test3';
        $uuid = Uuid::fromInteger('1234');
        $m->invoke($this->api, new PlayerLoginEvent($this->getPlayer($username, $uuid), ''));

        $this->assertTrue($this->api->getUuid($username)?->equals($uuid));
        $this->assertSame($this->api->getPlayerData($uuid)?->username, $username);
    }

    #[Depends('testReturnsCorrectDataEntry')]
    public function testErrorsOnInvalidUuidInDatabase(): void {
        $this->expectException(PlayerDataException::class);
        $username = 'test4';

        $db = new Sqlite($this->path, SQLITE3_OPEN_READWRITE);
        $stmt = $db->prepare('INSERT INTO UsernameData (username, uuid) VALUES (:username, :uuid)');
        $db->bind($stmt, ':uuid', 'garbage', SQLITE3_BLOB);
        $db->bind($stmt, ':username', $username, SQLITE3_TEXT);
        $db->execute($stmt);
        $db->close();

        $uuid = $this->api->getUuid($username);
    }

    #[After]
    public function deleteFile(): void {
        if (isset($this->api)) {
            $this->api->close();
        }
        $this->rrmdir($this->dir);
    }

    private function getPlayer(string $username, UuidInterface $uuid): Player {
        $player = $this
            ->getStubBuilder(Player::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getName',
                'getUniqueId',
                'onDispose',
            ])
            ->getStub();
        $player->method('getName')->willReturn($username);
        $player->method('getUniqueId')->willReturn($uuid);
        $player->method('onDispose');
        new ReflectionProperty(Player::class, 'logger')->setValue($player, $this->logger);
        return $player;
    }

    private function tempdir(string $prefix, int $mode = 0700) {
        do {
            $path = Path::join(sys_get_temp_dir(), $prefix . Uuid::uuid4()->toString());
        } while (!mkdir($path, $mode));
        return $path;
    }

    private function rrmdir(string $src) {
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
