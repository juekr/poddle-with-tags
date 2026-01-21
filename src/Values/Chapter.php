<?php

namespace PhanAn\Poddle\Values;

use Illuminate\Support\Arr;
use PhanAn\Poddle\Exceptions\InvalidChapterElementException;
use Saloon\XmlWrangler\Data\Element;
use Throwable;

class Chapter extends Serializable
{
    public function __construct(
        public readonly ?string $start,
        public readonly ?string $title,
        public readonly ?string $url,
        public readonly ?string $image
    ) {
    }

    public static function fromXmlElement(Element $element): static
    {
        try {

            return new static(
                start: $element->getAttribute('start') ?? $element->getAttribute('timestamp'),
                title: $element->getAttribute('title'),
                url: $element->getAttribute('url') ?? $element->getAttribute('href'),
                image: $element->getAttribute('image') ?? $element->getAttribute('img'),
            );
        } catch (Throwable $exception) {
            throw new InvalidChapterElementException($exception);
        }
    }

    public static function fromArray(array $data): static
    {
        return new static(
            start: Arr::get($data, 'start', Arr::get($data, 'timestamp')),
            title: Arr::get($data, 'title'),
            url: Arr::get($data, 'url'),
            image: Arr::get($data, 'image'),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'title' => $this->title,
            'url' => $this->url,
            'image' => $this->image,
        ];
    }

    public function start_as_seconds(): ?float
    {
        return self::start_to_seconds($this->start);
    }

    public function timestamp_as_seconds(): ?float
    {
        return $this->start_as_seconds();
    }

    public static function start_to_seconds(?string $start): ?float
    {
        if ($start === null || $start === '') {
            return null;
        }

        if (preg_match('/^(\d+):(\d{2}):(\d{2}):(\d{2})$/', $start, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];

            return $hours * 3600 + $minutes * 60 + $seconds;
        }

        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:\.(\d{1,3}))?$/', $start, $matches)) {
            $hours = isset($matches[1]) ? (int) $matches[1] : 0;
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];
            $fraction = 0.0;

            if (!empty($matches[4])) {
                $fraction = (int) $matches[4] / (10 ** strlen($matches[4]));
            }

            return $hours * 3600 + $minutes * 60 + $seconds + $fraction;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $start)) {
            return (float) $start;
        }

        return null;
    }

    public static function timestamp_to_seconds(?string $timestamp): ?float
    {
        return self::start_to_seconds($timestamp);
    }

    public static function seconds_to_timestamp(float|int $seconds): string
    {
        $seconds = max(0, (float) $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = (int) floor($seconds % 60);
        $fraction = $seconds - floor($seconds);
        $milliseconds = (int) round($fraction * 1000);

        if ($milliseconds === 1000) {
            $milliseconds = 0;
            $secs++;
        }

        if ($secs === 60) {
            $secs = 0;
            $minutes++;
        }

        if ($minutes === 60) {
            $minutes = 0;
            $hours++;
        }

        $time = $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%02d:%02d', $minutes, $secs);

        return $milliseconds > 0
            ? sprintf('%s.%03d', $time, $milliseconds)
            : $time;
    }
}
