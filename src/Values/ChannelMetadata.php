<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Arr;
use PhanAn\Poddle\Enums\PodcastType;

class ChannelMetadata extends Serializable
{
    public function __construct(
        public readonly bool $locked,
        public readonly ?string $lockedOwner,
        public readonly ?string $guid,
        public readonly ?string $author,
        public readonly ?string $copyright,
        public readonly TxtCollection $txts,
        public readonly FundingCollection $fundings,
        public readonly ?PodcastType $type,
        public readonly bool $complete,
        public readonly ?string $licenseUrl,
        public readonly ?string $licenseType,
        public readonly ?string $updateFrequency,
        public readonly PersonCollection $persons,
        public readonly ?Value $value
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new static(
            locked: Arr::get($data, 'locked', false),
            lockedOwner: Arr::get($data, 'locked_owner'),
            guid: Arr::get($data, 'guid'),
            author: Arr::get($data, 'author'),
            copyright: Arr::get($data, 'copyright'),
            txts: TxtCollection::fromArray(Arr::get($data, 'txts', [])),
            fundings: FundingCollection::fromArray(Arr::get($data, 'fundings', [])),
            type: PodcastType::tryFrom(Arr::get($data, 'type') ?? ''),
            complete: Arr::get($data, 'complete', false),
            licenseUrl: Arr::get($data, 'license_url'),
            licenseType: Arr::get($data, 'license_type'),
            updateFrequency: Arr::get($data, 'update_frequency'),
            persons: PersonCollection::fromArray(Arr::get($data, 'persons', [])),
            value: optional(Arr::get($data, 'value'), static fn (array $v) => Value::fromArray($v)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locked' => $this->locked,
            'locked_owner' => $this->lockedOwner,
            'guid' => $this->guid,
            'author' => $this->author,
            'copyright' => $this->copyright,
            'txts' => $this->txts->toArray(),
            'fundings' => $this->fundings->toArray(),
            'type' => $this->type?->value,
            'complete' => $this->complete,
            'license_url' => $this->licenseUrl,
            'license_type' => $this->licenseType,
            'update_frequency' => $this->updateFrequency,
            'persons' => $this->persons->toArray(),
            'value' => $this->value?->toArray(),
        ];
    }
}
