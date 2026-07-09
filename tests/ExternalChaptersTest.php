<?php

namespace Tests;

use PhanAn\Poddle\Poddle;

/**
 * Confirms podcast:chapters pointing at an external JSON URL is exposed
 * as (chaptersUrl, chaptersType) rather than fetched during parsing. This
 * used to make a live HTTP call inside EpisodeMetadata::fromXmlElement()
 * (via ChapterCollection::fromExternalUrl(), 10s timeout, swallowed
 * failures) — callers now decide for themselves whether/how to fetch it.
 * The fake, deliberately unresolvable domain is the actual proof: if a
 * fetch were still attempted, this test would hang or throw instead of
 * completing instantly.
 */
class ExternalChaptersTest extends TestCase
{
    private const XML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"
             xmlns:atom="http://www.w3.org/2005/Atom"
             xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
             xmlns:podcast="https://podcastindex.org/namespace/1.0">
            <channel>
                <title>External Chapters Fixture</title>
                <link>https://example.invalid</link>
                <description>Fixture for external podcast:chapters</description>
                <language>en-US</language>
                <atom:link rel="self" type="application/rss+xml" href="https://example.invalid/feed.xml"/>
                <item>
                    <title>Episode With External Chapters</title>
                    <guid isPermaLink="false">ep-external-chapters</guid>
                    <enclosure url="https://example.invalid/ep1.mp3" type="audio/mpeg" length="1000"/>
                    <podcast:chapters url="https://this-domain-does-not-resolve.invalid/chapters.json" type="application/json+chapters"/>
                </item>
            </channel>
        </rss>
        XML;

    public function testExternalChaptersUrlIsExposedNotFetched(): void
    {
        $start = microtime(true);

        $episode = Poddle::fromXml(self::XML)->getEpisodes()->first();

        $elapsed = microtime(true) - $start;

        self::assertTrue(
            $episode->metadata->chapters->isEmpty(),
            'Chapters collection should stay empty — resolving it is the caller\'s job now.'
        );
        self::assertSame('https://this-domain-does-not-resolve.invalid/chapters.json', $episode->metadata->chaptersUrl);
        self::assertSame('application/json+chapters', $episode->metadata->chaptersType);
        self::assertLessThan(1.0, $elapsed, 'Parsing took over a second — looks like a network call was attempted.');
    }
}
