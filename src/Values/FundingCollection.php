<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use PhanAn\Poddle\Exceptions\InvalidFundingElementException;
use Saloon\XmlWrangler\Data\Element;

/**
 * @template TKey of array-key
 * @template TModel of Funding
 * @extends Collection<TKey, TModel>
 */
class FundingCollection extends Collection
{
    /**
     * @param array<TModel> $items
     */
    public function __construct(array $items = [])
    {
        parent::__construct($items);
    }

    /**
     * Skips individual malformed <podcast:funding> elements rather than
     * failing the whole feed parse over one bad tag — real-world feeds
     * commonly nest an <a href="..."> inside the element instead of using
     * the spec's url attribute, which is non-compliant but common enough
     * that one bad funding tag shouldn't take down fetching. Same pattern
     * ChapterCollection::fromXmlElements() already uses for
     * InvalidChapterElementException.
     */
    public static function fromXmlElements(Enumerable $elements): static
    {
        return tap(new static(), static function (self $collection) use ($elements): void {
            $elements->each(static function (Element $element) use ($collection): void {
                try {
                    $collection->add(Funding::fromXmlElement($element));
                } catch (InvalidFundingElementException) {
                    // Skip this one element, keep the rest of the funding list.
                }
            });
        });
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $data
     */
    public static function fromArray(array $data): static
    {
        return tap(new static(), static function (self $collection) use ($data): void {
            foreach ($data as $item) {
                $collection->add(Funding::fromArray($item));
            }
        });
    }
}
