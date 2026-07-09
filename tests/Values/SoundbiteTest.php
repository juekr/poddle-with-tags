<?php

namespace Tests\Values;

use PhanAn\Poddle\Values\Soundbite;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class SoundbiteTest extends TestCase
{
    public function testCreateFromXmlElement(): void
    {
        $element = new Element(
            content: 'Why the Podcast Namespace Matters',
            attributes: ['startTime' => '73.0', 'duration' => '60.0'],
        );

        $soundbite = Soundbite::fromXmlElement($element);

        self::assertEqualsCanonicalizing([
            'start_time' => 73.0,
            'duration' => 60.0,
            'display_text' => 'Why the Podcast Namespace Matters',
        ], $soundbite->toArray());
    }

    public function testDisplayTextIsOptional(): void
    {
        $element = new Element(attributes: ['startTime' => '73.0', 'duration' => '60.0']);

        $soundbite = Soundbite::fromXmlElement($element);

        self::assertNull($soundbite->displayText);
    }
}
