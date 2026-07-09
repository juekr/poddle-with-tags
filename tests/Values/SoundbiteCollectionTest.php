<?php

namespace Tests\Values;

use PhanAn\Poddle\Values\SoundbiteCollection;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class SoundbiteCollectionTest extends TestCase
{
    public function testCreateFromXmlElements(): void
    {
        $collection = SoundbiteCollection::fromXmlElements([
            new Element('Intro', ['startTime' => '0', 'duration' => '30']),
            new Element('Main topic', ['startTime' => '73', 'duration' => '60']),
        ]);

        self::assertEqualsCanonicalizing([
            ['start_time' => 0.0, 'duration' => 30.0, 'display_text' => 'Intro'],
            ['start_time' => 73.0, 'duration' => 60.0, 'display_text' => 'Main topic'],
        ], $collection->toArray());
    }
}
