<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Arr;
use PhanAn\Poddle\Exceptions\InvalidSoundbiteElementException;
use Saloon\XmlWrangler\Data\Element;
use Throwable;

class Soundbite extends Serializable
{
    public function __construct(
        public readonly float $startTime,
        public readonly float $duration,
        public readonly ?string $displayText
    ) {
    }

    public static function fromXmlElement(Element $element): static
    {
        try {
            $content = $element->getContent();

            return new static(
                startTime: (float) $element->getAttribute('startTime'),
                duration: (float) $element->getAttribute('duration'),
                displayText: is_string($content) && $content !== '' ? $content : null,
            );
        } catch (Throwable $exception) {
            throw new InvalidSoundbiteElementException($exception);
        }
    }

    public static function fromArray(array $data): static
    {
        return new static(
            startTime: (float) Arr::get($data, 'start_time', 0),
            duration: (float) Arr::get($data, 'duration', 0),
            displayText: Arr::get($data, 'display_text'),
        );
    }

    /** @return array<string, float|string|null> */
    public function toArray(): array
    {
        return [
            'start_time' => $this->startTime,
            'duration' => $this->duration,
            'display_text' => $this->displayText,
        ];
    }
}
