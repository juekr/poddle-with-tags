<?php

namespace Tests\Values;

use PhanAn\Poddle\Values\Value;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class ValueTest extends TestCase
{
    public function testCreateFromXmlElementWithMultipleRecipients(): void
    {
        $element = new Element(
            content: [
                'podcast:valueRecipient' => [
                    new Element(attributes: ['name' => 'Host', 'type' => 'node', 'address' => 'abc123', 'split' => '90', 'fee' => 'false']),
                    new Element(attributes: ['name' => 'App', 'type' => 'node', 'address' => 'def456', 'split' => '10', 'fee' => 'true']),
                ],
            ],
            attributes: ['type' => 'lightning', 'method' => 'keysend', 'suggested' => '0.00000005'],
        );

        $value = Value::fromXmlElement($element);

        self::assertSame('lightning', $value->type);
        self::assertSame('keysend', $value->method);
        self::assertSame(0.00000005, $value->suggested);
        self::assertCount(2, $value->recipients);
        self::assertSame('abc123', $value->recipients[0]->address);
        self::assertSame('def456', $value->recipients[1]->address);
    }

    public function testCreateFromXmlElementWithSingleRecipient(): void
    {
        // XmlWrangler gives a bare Element (not wrapped in an array) when a
        // repeatable tag appears exactly once — must be handled too.
        $element = new Element(
            content: [
                'podcast:valueRecipient' => new Element(
                    attributes: ['name' => 'Host', 'type' => 'node', 'address' => 'abc123', 'split' => '100', 'fee' => 'false']
                ),
            ],
            attributes: ['type' => 'lightning', 'method' => 'keysend'],
        );

        $value = Value::fromXmlElement($element);

        self::assertCount(1, $value->recipients);
        self::assertSame('abc123', $value->recipients[0]->address);
        self::assertNull($value->suggested);
    }
}
