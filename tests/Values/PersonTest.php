<?php

namespace Tests\Values;

use PhanAn\Poddle\Exceptions\InvalidPersonElementException;
use PhanAn\Poddle\Values\Person;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class PersonTest extends TestCase
{
    public function testCreateFromXmlElement(): void
    {
        $element = new Element(
            content: 'Dave Jones',
            attributes: [
                'role' => 'host',
                'group' => 'cast',
                'img' => 'https://example.com/images/dave.jpg',
                'href' => 'https://example.com/dave',
            ]
        );

        $person = Person::fromXmlElement($element);

        self::assertEqualsCanonicalizing([
            'name' => 'Dave Jones',
            'role' => 'host',
            'group' => 'cast',
            'img' => 'https://example.com/images/dave.jpg',
            'href' => 'https://example.com/dave',
        ], $person->toArray());
    }

    public function testCreateFromInvalidXmlElement(): void
    {
        self::expectException(InvalidPersonElementException::class);

        Person::fromXmlElement(new Element());
    }
}
