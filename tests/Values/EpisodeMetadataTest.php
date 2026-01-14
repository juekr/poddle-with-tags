<?php

namespace Tests\Values;

use PhanAn\Poddle\Values\EpisodeMetadata;
use PhanAn\Poddle\Values\KeywordCollection;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class EpisodeMetadataTest extends TestCase
{
    public function testItParsesKeywordsFromXmlElement(): void
    {
        $item = new Element(content: [
            'itunes:keywords' => new Element(content: 'outdoors, hiking ,  camping'),
        ]);

        $metadata = EpisodeMetadata::fromXmlElement($item);

        self::assertSame(['outdoors', 'hiking', 'camping'], $metadata->keywords->toArray());
    }

    public function testItAcceptsKeywordsFromArray(): void
    {
        $metadata = EpisodeMetadata::fromArray(['keywords' => ['php', 'rss']]);

        self::assertInstanceOf(KeywordCollection::class, $metadata->keywords);
        self::assertSame(['php', 'rss'], $metadata->keywords->toArray());
        self::assertSame(['php', 'rss'], $metadata->toArray()['keywords']);
    }

    public function testItNormalizesKeywordStringFromArray(): void
    {
        $metadata = EpisodeMetadata::fromArray(['keywords' => 'php,  rss,  podcasts ']);

        self::assertSame(['php', 'rss', 'podcasts'], $metadata->keywords->toArray());
    }

    public function testItHandlesMissingKeywords(): void
    {
        $metadata = EpisodeMetadata::fromArray([]);

        self::assertSame([], $metadata->keywords->toArray());
        self::assertSame([], $metadata->toArray()['keywords']);
    }
}
