<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Collection;

/**
 * @template TKey of array-key
 * @template TValue of string
 * @extends Collection<TKey, TValue>
 */
class KeywordCollection extends Collection
{
    /**
     * @param array<TValue> $items
     */
    public function __construct(array $items = [])
    {
        parent::__construct($items);
    }

    public static function fromString(?string $keywords): static
    {
        return static::fromArray($keywords ? explode(',', $keywords) : []);
    }

    /**
     * @param  array<array-key, string|null>|null  $keywords
     */
    public static function fromArray(?array $keywords): static
    {
        if (!$keywords) {
            return new static();
        }

        $normalizedKeywords = array_filter(
            array_map(
                static fn (?string $keyword): ?string => $keyword !== null ? trim($keyword) : null,
                $keywords
            ),
            static fn (?string $keyword): bool => $keyword !== null && $keyword !== ''
        );

        return new static(array_values($normalizedKeywords));
    }

    public static function fromMixed(string|array|null $keywords): static
    {
        return is_array($keywords)
            ? static::fromArray($keywords)
            : static::fromString($keywords);
    }
}
