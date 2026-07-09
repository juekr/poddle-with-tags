<?php

namespace PhanAn\Poddle;

use DateTime;
use DateTimeInterface;
use Generator;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhanAn\Poddle\Cache\CacheConfig;
use PhanAn\Poddle\Cache\CacheContext;
use PhanAn\Poddle\Cache\CachedPoddleFactory;
use PhanAn\Poddle\Enums\PodcastType;
use PhanAn\Poddle\Values\CategoryCollection;
use PhanAn\Poddle\Values\Channel;
use PhanAn\Poddle\Values\ChannelMetadata;
use PhanAn\Poddle\Values\Episode;
use PhanAn\Poddle\Values\EpisodeCollection;
use PhanAn\Poddle\Values\FundingCollection;
use PhanAn\Poddle\Values\PersonCollection;
use PhanAn\Poddle\Values\TxtCollection;
use PhanAn\Poddle\Values\Value;
use Psr\Http\Client\ClientInterface;
use Saloon\XmlWrangler\XmlReader;
use Throwable;

class Poddle
{
    public readonly XmlReader $xmlReader;
    public readonly ?CacheContext $cacheContext;

    public function __construct(public readonly string $xml, ?CacheContext $cacheContext = null)
    {
        $this->cacheContext = $cacheContext;
        $this->xmlReader = XmlReader::fromString($xml);
    }

    public static function fromUrl(
        string $url,
        int $timeoutInSeconds = 30,
        ?ClientInterface $client = null,
        ?CacheConfig $cacheConfig = null
    ): self {
        if ($cacheConfig?->enabled) {
            return CachedPoddleFactory::fromConfig($cacheConfig)->fromUrl($url, $timeoutInSeconds, $client);
        }

        $xml = $client
            ? $client->sendRequest(new Request('GET', $url, ['timeout' => (string) $timeoutInSeconds]))->getBody()
            : self::http()->timeout($timeoutInSeconds)->get($url)->body();

        return new self((string) $xml);
    }

    public static function fromXml(string $xml): self
    {
        return new self($xml);
    }

    public static function fromXmlWithCache(string $xml, string $feedUrl, CacheConfig $cacheConfig): self
    {
        return CachedPoddleFactory::fromConfig($cacheConfig)->fromXml($feedUrl, $xml);
    }

    private static function http(): Factory
    {
        // Http::getFacadeRoot() throws (rather than returning null) when no
        // facade application has been bound at all, e.g. running outside a
        // Laravel app — the previous instanceof check never got a chance to
        // fall back in that case.
        try {
            $root = Http::getFacadeRoot();
            if ($root instanceof Factory) {
                return $root;
            }
        } catch (Throwable) {
            // fall through
        }

        return new Factory();
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    public function getChannel(): Channel
    {
        return new Channel(
            url: (string) $this->getSoleValue('atom:link@href'),
            title: (string) $this->getSoleValue('title'),
            description: (string) $this->getSoleValue('description'),
            link: (string) $this->getSoleValue('link'),
            language: (string) $this->getSoleValue('language'),
            categories: $this->getCategories(),
            explicit: $this->getSoleValue('itunes:explicit') === 'yes',
            image: (string) $this->getSoleValue('itunes:image@href'),
            metadata: $this->getMetadata(),
            subtitle: $this->getSoleValue('itunes:subtitle'),
            summary: $this->getSoleValue('itunes:summary'),
            ownerName: $this->getSoleValue('itunes:owner/itunes:name'),
            ownerEmail: $this->getSoleValue('itunes:owner/itunes:email'),
            newFeedUrl: $this->getSoleValue('itunes:new-feed-url'),
            block: $this->getSoleValue('itunes:block') === 'yes',
            imageUrl: $this->getSoleValue('image/url'),
            generator: $this->getSoleValue('generator'),
            lastBuildDate: self::parseRfc2822($this->getSoleValue('lastBuildDate')),
        );
    }

    public function getEpisodes(bool $ignoreInvalids = false): EpisodeCollection
    {
        return new EpisodeCollection(function () use ($ignoreInvalids): Generator {
            foreach ($this->xmlReader->element('rss.channel.item')->collectLazy() as $item) {
                try {
                    yield Episode::fromXmlElement($item);
                } catch (Throwable $e) {
                    if ($ignoreInvalids) {
                        continue;
                    }

                    throw $e;
                }
            }
        });
    }

    private function getSoleValue(string ...$queries): ?string
    {
        try {
            foreach ($queries as $query) {
                if (!Str::startsWith('/rss/channel/', $query)) {
                    $query = '/rss/channel/' . ltrim($query, '/');
                }

                if (Str::contains($query, '@')) {
                    [$query, $attribute] = explode('@', $query, 2);
                    $value = $this->xmlReader->xpathElement($query)->first()?->getAttribute($attribute);
                } else {
                    $value = $this->xmlReader->xpathValue($query)->first();
                }

                if ($value) {
                    return $value;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function parseRfc2822(?string $input): ?DateTime
    {
        if (!$input) {
            return null;
        }

        $parsed = DateTime::createFromFormat(DateTimeInterface::RFC2822, $input);

        return $parsed === false ? null : $parsed;
    }

    private function getMetadata(): ChannelMetadata
    {
        return new ChannelMetadata(
            locked: $this->getSoleValue('podcast:locked') === 'yes',
            lockedOwner: $this->getLockedOwner(),
            guid: $this->getSoleValue('podcast:guid'),
            author: $this->getSoleValue('itunes:author'),
            copyright: $this->getSoleValue('copyright'),
            txts: $this->getTxts(),
            fundings: $this->getFundings(),
            type: PodcastType::tryFrom($this->getSoleValue('itunes:type') ?? ''),
            complete: $this->getSoleValue('itunes:complete') === 'yes',
            licenseUrl: $this->getLicenseUrl(),
            licenseType: $this->getSoleValue('podcast:license'),
            updateFrequency: $this->getSoleValue('podcast:updateFrequency'),
            persons: $this->getPersons(),
            value: $this->getValue(),
        );
    }

    private function getLockedOwner(): ?string
    {
        try {
            return $this->xmlReader->xpathElement('/rss/channel/podcast:locked')->first()?->getAttribute('owner');
        } catch (Throwable) {
            return null;
        }
    }

    private function getLicenseUrl(): ?string
    {
        try {
            return $this->xmlReader->xpathElement('/rss/channel/podcast:license')->first()?->getAttribute('url');
        } catch (Throwable) {
            return null;
        }
    }

    private function getFundings(): FundingCollection
    {
        return FundingCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.podcast:funding')->collectLazy()
        );
    }

    private function getCategories(): CategoryCollection
    {
        return CategoryCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.itunes:category')->collectLazy()
        );
    }

    private function getTxts(): TxtCollection
    {
        return TxtCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.podcast:txt')->collectLazy()
        );
    }

    /**
     * podcast:person is valid at both channel and item level, unlike
     * funding/category/txt — element('rss.channel.podcast:person') matches
     * it anywhere under channel, including nested inside <item>, so this
     * needs the XPath direct-child axis (single '/') to stay scoped to the
     * channel's own persons.
     */
    private function getPersons(): PersonCollection
    {
        try {
            return PersonCollection::fromXmlElements(
                $this->xmlReader->xpathElement('/rss/channel/podcast:person')->get()
            );
        } catch (Throwable) {
            return new PersonCollection();
        }
    }

    /**
     * Same direct-child-axis reasoning as getPersons() — podcast:value is
     * also valid at both channel and item level.
     */
    private function getValue(): ?Value
    {
        try {
            $element = $this->xmlReader->xpathElement('/rss/channel/podcast:value')->first();
        } catch (Throwable) {
            return null;
        }

        return $element instanceof \Saloon\XmlWrangler\Data\Element ? Value::fromXmlElement($element) : null;
    }
}
