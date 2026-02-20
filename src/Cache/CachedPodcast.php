<?php

namespace PhanAn\Poddle\Cache;

use PhanAn\Poddle\Values\Channel;
use PhanAn\Poddle\Values\Episode;

class CachedPodcast
{
    /**
     * @param array<Episode> $episodes
     */
    public function __construct(
        public readonly string $feedUrl,
        public readonly string $xml,
        public readonly string $checksum,
        public readonly string $channelChecksum,
        public readonly int $fetchedAt,
        public readonly int $lastUpdated,
        public readonly Channel $channel,
        public readonly array $episodes
    ) {
    }
}
