<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use PhanAn\Poddle\Exceptions\InvalidChapterElementException;
use Saloon\XmlWrangler\Data\Element;
use Throwable;

/**
 * @template TKey of array-key
 * @template TModel of Chapter
 * @extends Collection<TKey, TModel>
 */
class ChapterCollection extends Collection
{
    /**
     * @param array<TModel> $items
     */
    public function __construct(array $items = [])
    {
        parent::__construct($items);
    }

    /**
     * @param  array<array-key, Element>  $elements
     */
    public static function fromXmlElements(array $elements): static
    {
        return tap(new static(), static function (self $collection) use ($elements): void {
            foreach ($elements as $element) {
                if (!$element instanceof Element) {
                    continue;
                }

                try {
                    $collection->add(Chapter::fromXmlElement($element));
                } catch (InvalidChapterElementException) {
                    continue;
                }
            }
        });
    }

    /**
     * @param  array<array-key, array<array-key, mixed>>  $data
     */
    public static function fromArray(array $data): static
    {
        return tap(new static(), static function (self $collection) use ($data): void {
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $collection->add(Chapter::fromArray($item));
            }
        });
    }

    public static function fromExternalUrl(string $url, int $timeoutInSeconds = 10): static
    {
        try {
            $body = self::http()->timeout($timeoutInSeconds)->get($url)->body();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $chapters = Arr::get($decoded, 'chapters', $decoded);

            if (!is_array($chapters)) {
                return new static();
            }

            return static::fromArray(array_map(
                static fn (array $chapter): array => [
                    'start' => Arr::get(
                        $chapter,
                        'start',
                        Arr::get(
                            $chapter,
                            'startTime',
                            Arr::get($chapter, 'start_time', Arr::get($chapter, 'timestamp'))
                        )
                    ),
                    'title' => Arr::get($chapter, 'title'),
                    'url' => Arr::get($chapter, 'url', Arr::get($chapter, 'href')),
                    'image' => Arr::get($chapter, 'image'),
                ],
                array_filter($chapters, 'is_array')
            ));
        } catch (Throwable) {
            return new static();
        }
    }

    public function as_json(): string
    {
        return json_encode($this->toArray()) ?: '[]';
    }

    private static function http(): Factory
    {
        return new Factory();
    }
}
