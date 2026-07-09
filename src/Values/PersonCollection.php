<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Saloon\XmlWrangler\Data\Element;

/**
 * @template TKey of array-key
 * @template TModel of Person
 * @extends Collection<TKey, TModel>
 */
class PersonCollection extends Collection
{
    /**
     * @param array<TModel> $items
     */
    public function __construct(array $items = [])
    {
        parent::__construct($items);
    }

    /**
     * @param Enumerable<array-key, Element>|array<array-key, Element> $elements
     */
    public static function fromXmlElements(Enumerable|array $elements): static
    {
        return tap(new static(), static function (self $collection) use ($elements): void {
            foreach ($elements as $element) {
                if (!$element instanceof Element) {
                    continue;
                }

                $collection->add(Person::fromXmlElement($element));
            }
        });
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $data
     */
    public static function fromArray(array $data): static
    {
        return tap(new static(), static function (self $collection) use ($data): void {
            foreach ($data as $item) {
                $collection->add(Person::fromArray($item));
            }
        });
    }
}
