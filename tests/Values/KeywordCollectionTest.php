<?php

namespace Tests\Values;

use PhanAn\Poddle\Values\KeywordCollection;
use Tests\TestCase;

class KeywordCollectionTest extends TestCase
{
    public function testCreateFromString(): void
    {
        $keywords = KeywordCollection::fromString('php,  laravel ,rss , ,podcasts');

        self::assertSame(['php', 'laravel', 'rss', 'podcasts'], $keywords->toArray());
    }

    public function testCreateFromArray(): void
    {
        $keywords = KeywordCollection::fromArray(['php', null, ' ', 'rss']);

        self::assertSame(['php', 'rss'], $keywords->toArray());
    }
}
