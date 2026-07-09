<?php

namespace PhanAn\Poddle\Values;

use DateTime;
use DateTimeInterface;
use Illuminate\Support\Arr;
use PhanAn\Poddle\Enums\EpisodeType;
use PhanAn\Poddle\Exceptions\InvalidDateTimeFormatException;
use PhanAn\Poddle\Exceptions\InvalidDurationException;
use Saloon\XmlWrangler\Data\Element;

class EpisodeMetadata extends Serializable
{
    public function __construct(
        public readonly ?string $link,
        public readonly ?DateTime $pubDate,
        public readonly ?string $description,
        public readonly ?int $duration,
        public readonly ?string $image,
        public readonly ?bool $explicit,
        public readonly TranscriptCollection $transcripts,
        public readonly ChapterCollection $chapters,
        public readonly ?int $episode,
        public readonly ?int $season,
        public readonly ?EpisodeType $type,
        public readonly ?bool $block,
        public readonly KeywordCollection $keywords,
        public readonly ?string $titleOverride = null,
        public readonly ?string $author = null,
        public readonly ?string $subtitle = null,
        public readonly ?string $summary = null,
        public readonly ?string $podcastSeasonName = null,
        public readonly ?string $podcastEpisodeDisplay = null,
        public readonly ?string $locationText = null,
        public readonly ?string $locationGeo = null,
        public readonly ?string $locationOsm = null,
        public readonly PersonCollection $persons = new PersonCollection(),
        public readonly ?Value $value = null,
        public readonly SoundbiteCollection $soundbites = new SoundbiteCollection(),
    ) {
    }

    public static function fromXmlElement(Element $item): static
    {
        /** @var array<string, Element> $content */
        $content = $item->getContent();

        $location = Arr::get($content, 'podcast:location');

        return new static(
            link: Arr::get($content, 'link')?->getContent(),
            pubDate: self::parseDateTime(Arr::get($content, 'pubDate')?->getContent()),
            description: Arr::get($content, 'description')?->getContent(),
            duration: self::parseDuration(Arr::get($content, 'itunes:duration')?->getContent()),
            image: Arr::get($content, 'itunes:image')?->getAttribute('href'),
            explicit: Arr::get($content, 'itunes:explicit')?->getContent() === 'true',
            transcripts: self::getTranscripts(Arr::get($content, 'podcast:transcript')),
            chapters: self::getChapters(
                Arr::get($content, 'podcast:chapters')
                ?? Arr::get($content, 'psc:chapters')
                ?? Arr::get($content, 'podcast:chapter')
                ?? Arr::get($content, 'psc:chapter')
            ),
            episode: optional(Arr::get($content, 'itunes:episode')?->getContent(), 'intval'),
            season: optional(Arr::get($content, 'itunes:season')?->getContent(), 'intval'),
            type: EpisodeType::tryFrom(Arr::get($content, 'itunes:episodeType')?->getContent() ?? ''),
            block: Arr::get($content, 'itunes:block')?->getContent() === 'yes',
            keywords: KeywordCollection::fromString(Arr::get($content, 'itunes:keywords')?->getContent()),
            titleOverride: Arr::get($content, 'itunes:title')?->getContent(),
            author: Arr::get($content, 'itunes:author')?->getContent(),
            subtitle: Arr::get($content, 'itunes:subtitle')?->getContent(),
            summary: Arr::get($content, 'itunes:summary')?->getContent(),
            podcastSeasonName: Arr::get($content, 'podcast:season')?->getAttribute('name'),
            podcastEpisodeDisplay: Arr::get($content, 'podcast:episode')?->getAttribute('display'),
            locationText: $location?->getContent(),
            locationGeo: $location?->getAttribute('geo'),
            locationOsm: $location?->getAttribute('osm'),
            persons: PersonCollection::fromXmlElements(self::normalizeToElementArray(Arr::get($content, 'podcast:person'))),
            value: self::getValue(Arr::get($content, 'podcast:value')),
            soundbites: SoundbiteCollection::fromXmlElements(
                self::normalizeToElementArray(Arr::get($content, 'podcast:soundbite'))
            ),
        );
    }

    public static function fromArray(array $data): static
    {
        return new static(
            link: Arr::get($data, 'link'),
            pubDate: self::parseDateTime(Arr::get($data, 'pub_date')),
            description: Arr::get($data, 'description'),
            duration: self::parseDuration(Arr::get($data, 'duration')),
            image: Arr::get($data, 'image'),
            explicit: Arr::get($data, 'explicit'),
            transcripts: TranscriptCollection::fromArray(Arr::get($data, 'transcripts', [])),
            chapters: ChapterCollection::fromArray(Arr::get($data, 'chapters', [])),
            episode: optional(Arr::get($data, 'episode'), 'intval'),
            season: optional(Arr::get($data, 'season'), 'intval'),
            type: EpisodeType::tryFrom(Arr::get($data, 'type') ?? ''),
            block: optional(
                Arr::get($data, 'block'),
                static fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)
            ),
            keywords: KeywordCollection::fromMixed(Arr::get($data, 'keywords')),
            titleOverride: Arr::get($data, 'title_override'),
            author: Arr::get($data, 'author'),
            subtitle: Arr::get($data, 'subtitle'),
            summary: Arr::get($data, 'summary'),
            podcastSeasonName: Arr::get($data, 'podcast_season_name'),
            podcastEpisodeDisplay: Arr::get($data, 'podcast_episode_display'),
            locationText: Arr::get($data, 'location_text'),
            locationGeo: Arr::get($data, 'location_geo'),
            locationOsm: Arr::get($data, 'location_osm'),
            persons: PersonCollection::fromArray(Arr::get($data, 'persons', [])),
            value: optional(Arr::get($data, 'value'), static fn (array $v) => Value::fromArray($v)),
            soundbites: SoundbiteCollection::fromArray(Arr::get($data, 'soundbites', [])),
        );
    }

    private static function getTranscripts(Element|array|null $value): TranscriptCollection
    {
        if (!$value) {
            return TranscriptCollection::make();
        }

        if ($value instanceof Element) {
            $content = $value->getContent();

            return is_array($content)
                ? TranscriptCollection::fromXmlElements($content)
                : TranscriptCollection::fromXmlElements([$value]);
        }

        return TranscriptCollection::fromXmlElements($value);
    }

    private static function getChapters(Element|array|null $value): ChapterCollection
    {
        if (!$value) {
            return new ChapterCollection();
        }

        if ($value instanceof Element) {
            $content = $value->getContent();

            if ($content instanceof Element) {
                return ChapterCollection::fromXmlElements([$content]);
            }

            if (!is_array($content)) {
                $externalUrl = $value->getAttribute('url') ?? $value->getAttribute('href');

                return $externalUrl
                    ? ChapterCollection::fromExternalUrl($externalUrl)
                    : new ChapterCollection();
            }

            $elements = [];

            foreach ($content as $chapter) {
                if ($chapter instanceof Element) {
                    $chapterContent = $chapter->getContent();

                    if (is_array($chapterContent)) {
                        foreach ($chapterContent as $nested) {
                            if ($nested instanceof Element) {
                                $elements[] = $nested;
                            }
                        }
                    } else {
                        $elements[] = $chapter;
                    }
                    continue;
                }

                if (is_array($chapter)) {
                    foreach ($chapter as $nested) {
                        if ($nested instanceof Element) {
                            $elements[] = $nested;
                        }
                    }
                }
            }

            if ($elements !== []) {
                return ChapterCollection::fromXmlElements($elements);
            }

            $externalUrl = $value->getAttribute('url') ?? $value->getAttribute('href');

            return $externalUrl
                ? ChapterCollection::fromExternalUrl($externalUrl)
                : new ChapterCollection();
        }

        return ChapterCollection::fromXmlElements($value);
    }

    /**
     * Normalizes XmlWrangler's representation of a repeatable tag into a
     * plain array of the real Elements. Three shapes are possible: null
     * (tag absent), a single bare Element (tag appears exactly once), or
     * an Element whose own content is an array of Elements (XmlWrangler
     * wraps 2+ sibling occurrences this way, rather than returning a
     * plain array directly) — the same shape getTranscripts()/
     * getChapters() already unwrap.
     *
     * @return array<Element>
     */
    private static function normalizeToElementArray(Element|array|null $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof Element) {
            $content = $value->getContent();

            if (is_array($content)) {
                return array_values(array_filter($content, static fn ($item): bool => $item instanceof Element));
            }

            return [$value];
        }

        return array_values(array_filter($value, static fn ($item): bool => $item instanceof Element));
    }

    private static function getValue(Element|array|null $value): ?Value
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = Arr::first($value, static fn ($item): bool => $item instanceof Element);
        }

        return $value instanceof Element ? Value::fromXmlElement($value) : null;
    }

    private static function parseDateTime(?string $input): ?DateTime
    {
        $formatted = $input ? DateTime::createFromFormat(DateTimeInterface::RFC2822, $input) : null;

        if ($formatted === false) {
            throw new InvalidDateTimeFormatException($input);
        }

        return $formatted;
    }

    private static function parseDuration(string|int|null $duration): ?int
    {
        $duration = (string) $duration;

        return $duration
            ? match (sscanf($duration, '%d:%d:%d', $x, $y, $z)) {
                1 => $x,
                2 => $x * 60 + $y,
                3 => $x * 3600 + $y * 60 + $z, // @phpstan-ignore-line
                default => throw new InvalidDurationException($duration),
            }
        : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'link' => $this->link,
            'pub_date' => $this->pubDate?->format(DateTimeInterface::RFC2822),
            'description' => $this->description,
            'duration' => $this->duration,
            'image' => $this->image,
            'explicit' => $this->explicit,
            'transcripts' => $this->transcripts->toArray(),
            'chapters' => $this->chapters->toArray(),
            'episode' => $this->episode,
            'season' => $this->season,
            'type' => $this->type?->value,
            'block' => $this->block,
            'keywords' => $this->keywords->toArray(),
            'title_override' => $this->titleOverride,
            'author' => $this->author,
            'subtitle' => $this->subtitle,
            'summary' => $this->summary,
            'podcast_season_name' => $this->podcastSeasonName,
            'podcast_episode_display' => $this->podcastEpisodeDisplay,
            'location_text' => $this->locationText,
            'location_geo' => $this->locationGeo,
            'location_osm' => $this->locationOsm,
            'persons' => $this->persons->toArray(),
            'value' => $this->value?->toArray(),
            'soundbites' => $this->soundbites->toArray(),
        ];
    }
}
