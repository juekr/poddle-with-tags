<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Arr;
use PhanAn\Poddle\Exceptions\InvalidValueElementException;
use Saloon\XmlWrangler\Data\Element;
use Throwable;

class ValueRecipient extends Serializable
{
    public function __construct(
        public readonly ?string $name,
        public readonly string $type,
        public readonly string $address,
        public readonly float $split,
        public readonly ?string $customKey,
        public readonly ?string $customValue,
        public readonly bool $fee
    ) {
    }

    public static function fromXmlElement(Element $element): static
    {
        try {
            return new static(
                name: $element->getAttribute('name'),
                type: $element->getAttribute('type'),
                address: $element->getAttribute('address'),
                split: (float) $element->getAttribute('split'),
                customKey: $element->getAttribute('customKey'),
                customValue: $element->getAttribute('customValue'),
                fee: $element->getAttribute('fee') === 'true',
            );
        } catch (Throwable $exception) {
            throw new InvalidValueElementException($exception);
        }
    }

    public static function fromArray(array $data): static
    {
        return new static(
            name: Arr::get($data, 'name'),
            type: Arr::get($data, 'type'),
            address: Arr::get($data, 'address'),
            split: (float) Arr::get($data, 'split', 0),
            customKey: Arr::get($data, 'custom_key'),
            customValue: Arr::get($data, 'custom_value'),
            fee: (bool) Arr::get($data, 'fee', false),
        );
    }

    /** @return array<string, string|float|bool|null> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'address' => $this->address,
            'split' => $this->split,
            'custom_key' => $this->customKey,
            'custom_value' => $this->customValue,
            'fee' => $this->fee,
        ];
    }
}
