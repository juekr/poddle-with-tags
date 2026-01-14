<?php

namespace PhanAn\Poddle\Cache;

use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use PhanAn\Poddle\Poddle;
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
        $checksum = hash($this->config->checksumAlgo, $xml);
        $cached = $this->manager->cachedPodcast($feedUrl);
        $fresh = $cached && $cached->checksum === $checksum && (time() - $cached->fetchedAt) <= $this->config->maxAgeSeconds;

        if ($fresh) {
            return new Poddle($cached->xml, new CacheContext('cache', false, $cached->fetchedAt));
        }

        $poddle = Poddle::fromXml($xml);
        $episodes = iterator_to_array($poddle->getEpisodes());
        $this->manager->refreshPodcast($feedUrl, $xml, $poddle->getChannel(), $episodes, $checksum);

        $wasStale = $cached && $cached->checksum === $checksum;

        return new Poddle($xml, new CacheContext('live', $wasStale, time()));
    }

    public function fromUrl(string $url, int $timeoutInSeconds, ?ClientInterface $client = null): Poddle
    {
        $cached = $this->manager->cachedPodcast($url);

        if (!$cached || $this->config->forceRefresh) {
            return $this->fetchAndStore($url, $timeoutInSeconds, $client);
        }

        $stale = (time() - $cached->fetchedAt) > $this->config->maxAgeSeconds;

        if (!$stale) {
            return new Poddle($cached->xml, new CacheContext('cache', false, $cached->fetchedAt));
        }

        if ($this->config->refreshOnStale) {
            $this->maybeRefreshAsync($url, $timeoutInSeconds, $client);

            return new Poddle($cached->xml, new CacheContext('cache', true, $cached->fetchedAt));
        }

        return $this->fetchAndStore($url, $timeoutInSeconds, $client, $cached);
    }

    private function fetchAndStore(
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
        $checksum = hash($this->config->checksumAlgo, $xml);

        if ($cached && $cached->checksum === $checksum) {
            // We fetched fresh data and validated the cache; mark it as refreshed.
            return new Poddle($cached->xml, new CacheContext('live', false, time()));
        }

        $poddle = Poddle::fromXml($xml);
        $episodes = iterator_to_array($poddle->getEpisodes());
        $this->manager->refreshPodcast($url, $xml, $poddle->getChannel(), $episodes, $checksum);

        return new Poddle($xml, new CacheContext('live', false, time()));
    }

    private function maybeRefreshAsync(string $url, int $timeoutInSeconds, ?ClientInterface $client = null): void
    {
        if ($this->config->refreshCallback) {
            ($this->config->refreshCallback)($url);

            return;
        }

        $this->fetchAndStore($url, $timeoutInSeconds, $client);
    }

    private function http(): \Illuminate\Http\Client\Factory
    {
        return Http::getFacadeRoot() instanceof \Illuminate\Http\Client\Factory
            ? Http::getFacadeRoot()
            : new \Illuminate\Http\Client\Factory();
    }
}
