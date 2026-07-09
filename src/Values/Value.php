<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Arr;
use PhanAn\Poddle\Exceptions\InvalidValueElementException;
use Saloon\XmlWrangler\Data\Element;
use Throwable;

class Value extends Serializable
{
    public function __construct(
        public readonly string $type,
        public readonly string $method,
        public readonly ?float $suggested,
        public readonly ValueRecipientCollection $recipients
    ) {
    }

    public static function fromXmlElement(Element $element): static
    {
        try {
            return new static(
                type: $element->getAttribute('type'),
                method: $element->getAttribute('method'),
                suggested: self::parseSuggested($element->getAttribute('suggested')),
                recipients: ValueRecipientCollection::fromXmlElements(self::extractRecipientElements($element)),
            );
        } catch (Throwable $exception) {
            throw new InvalidValueElementException($exception);
        }
    }

    public static function fromArray(array $data): static
    {
        return new static(
            type: Arr::get($data, 'type'),
            method: Arr::get($data, 'method'),
            suggested: self::parseSuggested(Arr::get($data, 'suggested')),
            recipients: ValueRecipientCollection::fromArray(Arr::get($data, 'recipients', [])),
        );
    }

    /**
     * Same wrapper-Element shape as EpisodeMetadata::normalizeToElementArray()
     * — XmlWrangler wraps 2+ sibling <podcast:valueRecipient> occurrences in
     * an intermediate Element whose own content is the array of real ones,
     * rather than returning a plain array directly.
     *
     * @return array<Element>
     */
    private static function extractRecipientElements(Element $element): array
    {
        $content = $element->getContent();
        $value = is_array($content) ? Arr::get($content, 'podcast:valueRecipient') : null;

        if ($value instanceof Element) {
            $innerContent = $value->getContent();

            if (is_array($innerContent)) {
                return array_values(array_filter($innerContent, static fn ($item): bool => $item instanceof Element));
            }

            return [$value];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($item): bool => $item instanceof Element));
        }

        return [];
    }

    private static function parseSuggested(string|float|null $suggested): ?float
    {
        return $suggested === null || $suggested === '' ? null : (float) $suggested;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'method' => $this->method,
            'suggested' => $this->suggested,
            'recipients' => $this->recipients->toArray(),
        ];
    }
}
