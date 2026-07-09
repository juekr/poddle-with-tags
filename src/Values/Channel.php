<?php

namespace PhanAn\Poddle\Values;

use DateTime;
use Illuminate\Support\Arr;

class Channel extends Serializable
{
    /** Alias for the channel's URL. */
    public string $atomLink;

    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $description,
        public readonly string $link,
        public readonly string $language,
        public readonly CategoryCollection $categories,
        public readonly bool $explicit,
        public readonly string $image,
        public readonly ChannelMetadata $metadata,
        public readonly ?string $subtitle = null,
        public readonly ?string $summary = null,
        public readonly ?string $ownerName = null,
        public readonly ?string $ownerEmail = null,
        public readonly ?string $newFeedUrl = null,
        public readonly bool $block = false,
        public readonly ?string $imageUrl = null,
        public readonly ?string $generator = null,
        public readonly ?DateTime $lastBuildDate = null,
    ) {
        $this->atomLink = $this->url;
    }

    public static function fromArray(array $data): static
    {
        return new static(
            url: Arr::get($data, 'url'),
            title: Arr::get($data, 'title'),
            description: Arr::get($data, 'description'),
            link: Arr::get($data, 'link'),
            language: Arr::get($data, 'language'),
            categories: CategoryCollection::fromArray(Arr::get($data, 'categories', [])),
            explicit: Arr::get($data, 'explicit', false),
            image: Arr::get($data, 'image'),
            metadata: ChannelMetadata::fromArray(Arr::get($data, 'metadata', [])),
            subtitle: Arr::get($data, 'subtitle'),
            summary: Arr::get($data, 'summary'),
            ownerName: Arr::get($data, 'owner_name'),
            ownerEmail: Arr::get($data, 'owner_email'),
            newFeedUrl: Arr::get($data, 'new_feed_url'),
            block: Arr::get($data, 'block', false),
            imageUrl: Arr::get($data, 'image_url'),
            generator: Arr::get($data, 'generator'),
            lastBuildDate: optional(Arr::get($data, 'last_build_date'), static fn ($v) => new DateTime($v)),
        );
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'atom_link' => $this->atomLink,
            'title' => $this->title,
            'description' => $this->description,
            'link' => $this->link,
            'language' => $this->language,
            'categories' => $this->categories->toArray(),
            'explicit' => $this->explicit,
            'image' => $this->image,
            'metadata' => $this->metadata->toArray(),
            'subtitle' => $this->subtitle,
            'summary' => $this->summary,
            'owner_name' => $this->ownerName,
            'owner_email' => $this->ownerEmail,
            'new_feed_url' => $this->newFeedUrl,
            'block' => $this->block,
            'image_url' => $this->imageUrl,
            'generator' => $this->generator,
            'last_build_date' => $this->lastBuildDate?->format(DATE_RFC2822),
        ];
    }
}
