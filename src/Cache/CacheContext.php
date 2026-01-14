<?php

namespace PhanAn\Poddle\Cache;

class CacheContext
{
    public function __construct(
        public readonly string $source,
        public readonly bool $stale,
        public readonly int $fetchedAt
    ) {
    }

    public function fromCache(): bool
    {
        return $this->source === 'cache';
    }
}
