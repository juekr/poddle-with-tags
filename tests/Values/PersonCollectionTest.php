<?php

namespace Tests\Values;

use PhanAn\Poddle\Values\PersonCollection;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class PersonCollectionTest extends TestCase
{
    public function testCreateFromXmlElements(): void
    {
        $collection = PersonCollection::fromXmlElements([
            new Element('Dave Jones', ['role' => 'host']),
            new Element('Say Sarah', ['role' => 'guest']),
        ]);

        self::assertEqualsCanonicalizing([
            ['name' => 'Dave Jones', 'role' => 'host', 'group' => null, 'img' => null, 'href' => null],
            ['name' => 'Say Sarah', 'role' => 'guest', 'group' => null, 'img' => null, 'href' => null],
        ], $collection->toArray());
    }
}
