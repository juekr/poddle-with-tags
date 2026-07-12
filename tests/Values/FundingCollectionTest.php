<?php

namespace Tests\Values;

use PhanAn\Poddle\Values\FundingCollection;
use Saloon\XmlWrangler\Data\Element;
use Tests\TestCase;

class FundingCollectionTest extends TestCase
{
    public function testCreateFromXmlElements(): void
    {
        $collection = FundingCollection::fromXmlElements(collect([
            new Element('Buy me a coffee!', ['url' => 'https://buymea.coffee']),
            new Element('Buy me a beer!', ['url' => 'https://buymea.beer'])
        ]));

        self::assertEqualsCanonicalizing([
            ['text' => 'Buy me a coffee!', 'url' => 'https://buymea.coffee'],
            ['text' => 'Buy me a beer!', 'url' => 'https://buymea.beer'],
        ], $collection->toArray());
    }

    /**
     * Real-world feeds sometimes nest an <a href="..."> inside
     * <podcast:funding> instead of using the spec's url attribute — e.g.
     * <podcast:funding><a href="https://paypal.com/...">https://paypal.com/...</a></podcast:funding>.
     * That's non-compliant (no url attribute at all), but one malformed
     * funding tag shouldn't take down parsing of an otherwise-valid feed.
     */
    public function testMalformedFundingElementIsSkippedRatherThanThrowing(): void
    {
        $collection = FundingCollection::fromXmlElements(collect([
            new Element('Buy me a coffee!', ['url' => 'https://buymea.coffee']),
            new Element('<a href="https://paypal.com/donate">https://paypal.com/donate</a>'),
        ]));

        self::assertEqualsCanonicalizing([
            ['text' => 'Buy me a coffee!', 'url' => 'https://buymea.coffee'],
        ], $collection->toArray());
    }
}
