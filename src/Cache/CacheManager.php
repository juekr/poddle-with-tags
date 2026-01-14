<?php

namespace PhanAn\Poddle\Cache;

use PhanAn\Poddle\Values\Channel;
use PhanAn\Poddle\Values\Episode;

class CacheManager
{
    public function __construct(private readonly CacheStore $store)
    {
    }

    public static function make(CacheConfig $config): self
    {
        return new self(new CacheStore($config));
    }

    public function cachedPodcast(string $feedUrl): ?CachedPodcast
    {
        return $this->store->findPodcast($feedUrl);
    }

    public function refreshPodcast(string $feedUrl, string $xml, Channel $channel, iterable $episodes, string $checksum): void
    {
        $this->store->persistPodcast($feedUrl, $xml, $channel, $episodes, $checksum);
    }

    public function deletePodcast(string $feedUrl): void
    {
        $this->store->deletePodcast($feedUrl);
    }

    public function upsertEpisode(string $feedUrl, Episode $episode): void
    {
        $this->store->upsertEpisode($feedUrl, $episode);
    }

    public function deleteEpisode(string $feedUrl, string $guid): void
    {
        $this->store->deleteEpisode($feedUrl, $guid);
    }

    public function updateChannel(string $feedUrl, Channel $channel): void
    {
        $this->store->updateChannel($feedUrl, $channel);
    }
}
