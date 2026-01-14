<?php

namespace Tests\Cache;

use Illuminate\Support\Facades\Http;
use PhanAn\Poddle\Cache\CacheConfig;
use PhanAn\Poddle\Cache\CacheManager;
use PhanAn\Poddle\Poddle;
use Tests\TestCase;

class CacheTest extends TestCase
{
    public function testUsesCacheWhenFresh(): void
    {
        $db = $this->tempDb();
        $config = new CacheConfig(enabled: true, databasePath: $db, maxAgeSeconds: 3600);

        Http::fake([
            'https://example.com/feed' => Http::response(file_get_contents(__DIR__ . '/../fixtures/sample.xml')),
        ]);

        $live = Poddle::fromUrl('https://example.com/feed', cacheConfig: $config);
        self::assertFalse($live->cacheContext?->fromCache());

        Http::fake(['https://example.com/feed' => Http::response('should not be used', 500)]);
        $cached = Poddle::fromUrl('https://example.com/feed', cacheConfig: $config);

        self::assertTrue($cached->cacheContext?->fromCache());
        Http::assertNothingSent();
    }

    public function testStaleCacheRefreshesWithNewChecksum(): void
    {
        $db = $this->tempDb();
        $config = new CacheConfig(enabled: true, databasePath: $db, maxAgeSeconds: 1);

        Http::fake([
            'https://example.com/feed' => Http::response(file_get_contents(__DIR__ . '/../fixtures/sample.xml')),
        ]);

        Poddle::fromUrl('https://example.com/feed', cacheConfig: $config);

        $this->ageCache($db);

        Http::fake([
            'https://example.com/feed' => Http::response(file_get_contents(__DIR__ . '/../fixtures/sample_updated.xml')),
        ]);

        $live = Poddle::fromUrl('https://example.com/feed', cacheConfig: $config);

        self::assertFalse($live->cacheContext?->fromCache());
        Http::assertSent(static function ($request) {
            return (string) $request->url() === 'https://example.com/feed';
        });
    }

    public function testFromXmlWithCacheUsesCacheWhenChecksumMatches(): void
    {
        $db = $this->tempDb();
        $config = new CacheConfig(enabled: true, databasePath: $db, maxAgeSeconds: 3600);
        $xml = file_get_contents(__DIR__ . '/../fixtures/sample.xml');

        $first = Poddle::fromXmlWithCache($xml, 'https://example.com/feed', $config);
        self::assertFalse($first->cacheContext?->fromCache());

        $second = Poddle::fromXmlWithCache($xml, 'https://example.com/feed', $config);
        self::assertTrue($second->cacheContext?->fromCache());
    }

    public function testFromXmlWithCacheRefreshesWhenChecksumDiffers(): void
    {
        $db = $this->tempDb();
        $config = new CacheConfig(enabled: true, databasePath: $db, maxAgeSeconds: 3600);

        $firstXml = file_get_contents(__DIR__ . '/../fixtures/sample.xml');
        $secondXml = file_get_contents(__DIR__ . '/../fixtures/sample_updated.xml');

        Poddle::fromXmlWithCache($firstXml, 'https://example.com/feed', $config);
        $second = Poddle::fromXmlWithCache($secondXml, 'https://example.com/feed', $config);

        self::assertFalse($second->cacheContext?->fromCache());
    }

    public function testForceRefreshOverridesCache(): void
    {
        $db = $this->tempDb();
        $config = new CacheConfig(enabled: true, databasePath: $db, forceRefresh: true);

        Http::fake([
            'https://example.com/feed' => Http::response(file_get_contents(__DIR__ . '/../fixtures/sample.xml')),
        ]);

        $live = Poddle::fromUrl('https://example.com/feed', cacheConfig: $config);

        self::assertFalse($live->cacheContext?->fromCache());
        Http::assertSentCount(1);
    }

    public function testSupportsMultiplePodcastsAndCrud(): void
    {
        $db = $this->tempDb();
        $config = new CacheConfig(enabled: true, databasePath: $db, maxAgeSeconds: 3600);

        Http::fake([
            'https://a.com/feed' => Http::response(file_get_contents(__DIR__ . '/../fixtures/sample.xml')),
            'https://b.com/feed' => Http::response(file_get_contents(__DIR__ . '/../fixtures/sample_updated.xml')),
        ]);

        Poddle::fromUrl('https://a.com/feed', cacheConfig: $config);
        Poddle::fromUrl('https://b.com/feed', cacheConfig: $config);

        $manager = CacheManager::make($config);
        self::assertNotNull($manager->cachedPodcast('https://a.com/feed'));
        self::assertNotNull($manager->cachedPodcast('https://b.com/feed'));

        $first = $manager->cachedPodcast('https://a.com/feed');
        $manager->deleteEpisode('https://a.com/feed', $first?->episodes[0]->guid->value ?? '');

        $afterDelete = CacheManager::make($config)->cachedPodcast('https://a.com/feed');
        self::assertCount(
            max(0, count($first?->episodes ?? []) - 1),
            $afterDelete?->episodes ?? []
        );
    }

    private function tempDb(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'poddle_cache_');
        if ($path === false) {
            $this->fail('Could not create temp db file');
        }

        return $path;
    }

    private function ageCache(string $dbPath): void
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('UPDATE podcasts SET fetched_at = fetched_at - 3600');
    }
}
