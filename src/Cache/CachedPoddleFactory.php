<?php

namespace PhanAn\Poddle\Cache;

use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use PhanAn\Poddle\Poddle;
use PhanAn\Poddle\Values\Channel;
use PhanAn\Poddle\Values\Episode;
use Psr\Http\Client\ClientInterface;

class CachedPoddleFactory
{
    public function __construct(private readonly CacheManager $manager, private readonly CacheConfig $config)
    {
    }

    public static function fromConfig(CacheConfig $config): self
    {
        return new self(CacheManager::make($config), $config);
    }

    public function fromXml(string $feedUrl, string $xml): Poddle
    {
        $cached = $this->manager->cachedPodcast($feedUrl);
        $checksum = hash($this->config->checksumAlgo, $xml);

        if (!$cached) {
            $this->config->log("cache miss (xml): {$feedUrl}");

            return $this->refreshFromXml($feedUrl, $xml);
        }

        $podcastGuid = $this->manager->podcastGuid($feedUrl);
        $stale = $podcastGuid ? $this->isStale($podcastGuid) : true;

        if (!$stale && $cached->checksum === $checksum) {
            $this->config->log("cache hit (xml): {$feedUrl}");

            return new Poddle($cached->xml, new CacheContext('cache', false, $cached->fetchedAt));
        }

        $this->config->log("cache stale (xml): {$feedUrl}");

        return $this->compareAndMaybeRefresh($feedUrl, $xml, $cached);
    }

    public function fromUrl(string $url, int $timeoutInSeconds, ?ClientInterface $client = null): Poddle
    {
        $cached = $this->manager->cachedPodcast($url);

        if (!$cached || $this->config->forceRefresh) {
            $this->config->log($cached ? "force refresh: {$url}" : "cache miss: {$url}");

            return $this->fetchAndCompare($url, $timeoutInSeconds, $client, $cached);
        }

        $podcastGuid = $this->manager->podcastGuid($url);
        $stale = $podcastGuid ? $this->isStale($podcastGuid) : true;

        if (!$stale) {
            $this->config->log("cache hit: {$url}");

            return new Poddle($cached->xml, new CacheContext('cache', false, $cached->fetchedAt));
        }

        $this->config->log("cache stale: {$url}");

        if ($this->config->refreshOnStale) {
            $this->maybeRefreshAsync($url, $timeoutInSeconds, $client);

            return new Poddle($cached->xml, new CacheContext('cache', true, $cached->fetchedAt));
        }

        return $this->fetchAndCompare($url, $timeoutInSeconds, $client, $cached);
    }

    private function refreshFromXml(string $feedUrl, string $xml): Poddle
    {
        $poddle = Poddle::fromXml($xml);
        $episodes = iterator_to_array($poddle->getEpisodes());
        $checksum = hash($this->config->checksumAlgo, $xml);

        $this->manager->refreshPodcast($feedUrl, $xml, $poddle->getChannel(), $episodes, $checksum);

        return new Poddle($xml, new CacheContext('live', false, time()));
    }

    private function fetchAndCompare(
        string $url,
        int $timeoutInSeconds,
        ?ClientInterface $client = null,
        ?CachedPodcast $cached = null
    ): Poddle
    {
        $xml = $client
            ? $client->sendRequest(
                new Request('GET', $url, ['timeout' => (string) $timeoutInSeconds])
            )->getBody()
            : $this->http()->timeout($timeoutInSeconds)->get($url)->body();

        $xml = (string) $xml;

        return $this->compareAndMaybeRefresh($url, $xml, $cached);
    }

    private function compareAndMaybeRefresh(string $feedUrl, string $xml, ?CachedPodcast $cached = null): Poddle
    {
        $checksum = hash($this->config->checksumAlgo, $xml);
        $poddle = Poddle::fromXml($xml);
        $channel = $poddle->getChannel();
        $episodes = iterator_to_array($poddle->getEpisodes());

        if ($cached) {
            $podcastGuid = $this->manager->podcastGuid($feedUrl);
            $channelChecksum = $this->hashPayload($channel);
            $episodeChecksums = $this->hashEpisodes($episodes);

            if ($podcastGuid) {
                $storedChecksums = $this->manager->episodeChecksums($podcastGuid);
                $channelMatches = $cached->channelChecksum === $channelChecksum;
                $episodesMatch = $this->episodeChecksumsMatch($storedChecksums, $episodeChecksums);

                if ($channelMatches && $episodesMatch) {
                    $this->config->log("hash match: {$feedUrl}");
                    $this->manager->touchPodcast($podcastGuid);

                    return new Poddle($cached->xml, new CacheContext('live', false, time()));
                }

                $this->config->log("hash mismatch: {$feedUrl}");
            }
        }

        $this->manager->refreshPodcast($feedUrl, $xml, $channel, $episodes, $checksum);

        return new Poddle($xml, new CacheContext('live', false, time()));
    }

    private function maybeRefreshAsync(string $url, int $timeoutInSeconds, ?ClientInterface $client = null): void
    {
        if ($this->config->refreshCallback) {
            ($this->config->refreshCallback)($url);

            return;
        }

        $this->fetchAndCompare($url, $timeoutInSeconds, $client, $this->manager->cachedPodcast($url));
    }

    private function isStale(string $podcastGuid): bool
    {
        $staleTimestamp = $this->manager->staleTimestamp($podcastGuid);

        if ($staleTimestamp === null) {
            return true;
        }

        return (time() - $staleTimestamp) > $this->config->maxAgeSeconds;
    }

    /** @param array<Episode> $episodes */
    private function hashEpisodes(array $episodes): array
    {
        $checksums = [];

        foreach ($episodes as $episode) {
            $checksums[$episode->guid->value] = $this->hashPayload($episode);
        }

        return $checksums;
    }

    private function hashPayload(Channel|Episode $payload): string
    {
        return hash($this->config->checksumAlgo, json_encode($payload->toArray(), JSON_THROW_ON_ERROR));
    }

    private function episodeChecksumsMatch(array $stored, array $current): bool
    {
        if (count($stored) !== count($current)) {
            return false;
        }

        foreach ($current as $guid => $checksum) {
            if (!array_key_exists($guid, $stored) || $stored[$guid] !== $checksum) {
                return false;
            }
        }

        return true;
    }

    private function http(): \Illuminate\Http\Client\Factory
    {
        return Http::getFacadeRoot() instanceof \Illuminate\Http\Client\Factory
            ? Http::getFacadeRoot()
            : new \Illuminate\Http\Client\Factory();
    }
}
