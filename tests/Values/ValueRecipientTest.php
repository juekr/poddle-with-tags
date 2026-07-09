<?php

namespace Tests\Values;

use PhanAn\Poddle\Exceptions\InvalidValueElementException;
use PhanAn\Poddle\Values\ValueRecipient;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class ValueRecipientTest extends TestCase
{
    public function testCreateFromXmlElement(): void
    {
        $element = new Element(attributes: [
            'name' => 'podcaster',
            'type' => 'node',
            'address' => '03ae9f...',
            'split' => '90',
            'fee' => 'true',
        ]);

        $recipient = ValueRecipient::fromXmlElement($element);

        self::assertEqualsCanonicalizing([
            'name' => 'podcaster',
            'type' => 'node',
            'address' => '03ae9f...',
            'split' => 90.0,
            'custom_key' => null,
            'custom_value' => null,
            'fee' => true,
        ], $recipient->toArray());
    }

    public function testCreateFromInvalidXmlElement(): void
    {
        self::expectException(InvalidValueElementException::class);

        ValueRecipient::fromXmlElement(new Element());
    }
}
