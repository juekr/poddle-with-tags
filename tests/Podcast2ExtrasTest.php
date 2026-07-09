<?php

namespace Tests;

use PhanAn\Poddle\Poddle;

/**
 * Integration-level coverage for the podcast:person, podcast:value,
 * podcast:soundbite, podcast:license, podcast:updateFrequency,
 * podcast:locked@owner, and itunes owner/subtitle/summary/new-feed-url
 * fields — exercised through Poddle end-to-end (real XmlReader queries),
 * not just the isolated Value class unit tests in tests/Values/.
 */
class Podcast2ExtrasTest extends TestCase
{
    private function poddle(): Poddle
    {
        return Poddle::fromXml(file_get_contents(__DIR__ . '/fixtures/podcast2-extras.xml'));
    }

    public function testChannelLevelFields(): void
    {
        $channel = $this->poddle()->getChannel();

        self::assertSame('A short subtitle', $channel->subtitle);
        self::assertSame('A longer channel summary', $channel->summary);
        self::assertSame('Jane Doe', $channel->ownerName);
        self::assertSame('jane@example.com', $channel->ownerEmail);
        self::assertSame('https://example.com/new-feed.xml', $channel->newFeedUrl);
        self::assertTrue($channel->block);
    }

    public function testChannelLevelPodcastMetadata(): void
    {
        $metadata = $this->poddle()->getChannel()->metadata;

        self::assertTrue($metadata->locked);
        self::assertSame('owner@example.com', $metadata->lockedOwner);
        self::assertSame('https://example.com/license', $metadata->licenseUrl);
        self::assertSame('cc-by-4.0', $metadata->licenseType);
        self::assertSame('weekly', $metadata->updateFrequency);
    }

    public function testChannelLevelPersons(): void
    {
        $persons = $this->poddle()->getChannel()->metadata->persons;

        self::assertCount(2, $persons);
        self::assertSame('Dave Jones', $persons[0]->name);
        self::assertSame('host', $persons[0]->role);
        self::assertSame('cast', $persons[0]->group);
        self::assertSame('Sarah Smith', $persons[1]->name);
        self::assertSame('guest', $persons[1]->role);
    }

    public function testChannelLevelValue(): void
    {
        $value = $this->poddle()->getChannel()->metadata->value;

        self::assertNotNull($value);
        self::assertSame('lightning', $value->type);
        self::assertSame('keysend', $value->method);
        self::assertSame(0.00000005, $value->suggested);
        self::assertCount(2, $value->recipients);
        self::assertSame('feednode123', $value->recipients[0]->address);
        self::assertSame(90.0, $value->recipients[0]->split);
        self::assertFalse($value->recipients[0]->fee);
        self::assertSame('appnode456', $value->recipients[1]->address);
        self::assertTrue($value->recipients[1]->fee);
    }

    public function testEpisodeLevelFields(): void
    {
        $episode = $this->poddle()->getEpisodes()->first();
        $metadata = $episode->metadata;

        self::assertSame('Episode One (iTunes override)', $metadata->titleOverride);
        self::assertSame('Dave Jones', $metadata->author);
        self::assertSame('Episode subtitle', $metadata->subtitle);
        self::assertSame('Episode summary', $metadata->summary);
        self::assertSame('Winter Season', $metadata->podcastSeasonName);
        self::assertSame('1a', $metadata->podcastEpisodeDisplay);
        self::assertSame('Tokyo, Japan', $metadata->locationText);
        self::assertSame('geo:35.65,139.90', $metadata->locationGeo);
        self::assertSame('R1751536', $metadata->locationOsm);
    }

    public function testEpisodeLevelPersons(): void
    {
        $episode = $this->poddle()->getEpisodes()->first();

        self::assertCount(1, $episode->metadata->persons);
        self::assertSame('Dave Jones', $episode->metadata->persons[0]->name);
        self::assertSame('host', $episode->metadata->persons[0]->role);
    }

    public function testEpisodeLevelSoundbites(): void
    {
        $episode = $this->poddle()->getEpisodes()->first();
        $soundbites = $episode->metadata->soundbites;

        self::assertCount(2, $soundbites);
        self::assertSame(73.0, $soundbites[0]->startTime);
        self::assertSame(60.0, $soundbites[0]->duration);
        self::assertSame('Why the Podcast Namespace Matters', $soundbites[0]->displayText);
        self::assertSame(180.5, $soundbites[1]->startTime);
        self::assertNull($soundbites[1]->displayText);
    }

    public function testEpisodeLevelValue(): void
    {
        $episode = $this->poddle()->getEpisodes()->first();
        $value = $episode->metadata->value;

        self::assertNotNull($value);
        self::assertCount(1, $value->recipients);
        self::assertSame('episodenode789', $value->recipients[0]->address);
        self::assertSame(100.0, $value->recipients[0]->split);
    }
}
