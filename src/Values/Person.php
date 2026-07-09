<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Arr;
use PhanAn\Poddle\Exceptions\InvalidPersonElementException;
use Saloon\XmlWrangler\Data\Element;
use Throwable;

class Person extends Serializable
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $role,
        public readonly ?string $group,
        public readonly ?string $img,
        public readonly ?string $href
    ) {
    }

    public static function fromXmlElement(Element $element): static
    {
        try {
            return new static(
                name: $element->getContent(),
                role: $element->getAttribute('role'),
                group: $element->getAttribute('group'),
                img: $element->getAttribute('img'),
                href: $element->getAttribute('href'),
            );
        } catch (Throwable $exception) {
            throw new InvalidPersonElementException($exception);
        }
    }

    public static function fromArray(array $data): static
    {
        return new static(
            name: Arr::get($data, 'name'),
            role: Arr::get($data, 'role'),
            group: Arr::get($data, 'group'),
            img: Arr::get($data, 'img'),
            href: Arr::get($data, 'href'),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role,
            'group' => $this->group,
            'img' => $this->img,
            'href' => $this->href,
        ];
    }
}
