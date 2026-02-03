<?php

declare(strict_types=1);

namespace armorshard\simpleplayerdata;

use Ramsey\Uuid\UuidInterface;

final readonly class PlayerData {
    public function __construct(
        public UuidInterface $uuid,
        public string $username,
        public int $firstSeen,
        public int $lastSeen,
    ) {}
}
