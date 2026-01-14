<?php

namespace PhanAn\Poddle\Cache;

class CacheConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly bool $forceRefresh = false,
        public readonly int $maxAgeSeconds = 3600*12, /* 12h */
        public readonly ?string $databasePath = null,
        public readonly bool $refreshOnStale = false,
        public readonly ?\Closure $refreshCallback = null,
        public readonly string $checksumAlgo = 'sha256',
        public readonly ?\PDO $pdo = null
    ) {
    }

    public function path(): string
    {
        return $this->databasePath ?? sys_get_temp_dir() . '/poddle-cache.sqlite';
    }
}
