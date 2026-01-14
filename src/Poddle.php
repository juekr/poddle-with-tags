<?php

namespace PhanAn\Poddle;

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
use PhanAn\Poddle\Values\TxtCollection;
use Psr\Http\Client\ClientInterface;
use Saloon\XmlWrangler\Exceptions\QueryAlreadyReadException;
use Saloon\XmlWrangler\Exceptions\XmlReaderException;
use Saloon\XmlWrangler\XmlReader;
use Throwable;
use VeeWee\Xml\Encoding\Exception\EncodingException;

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
        return Http::getFacadeRoot() instanceof Factory
            ? Http::getFacadeRoot()
            : new Factory();
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
            url: $this->getSoleValue('atom:link@href'),
            title: $this->getSoleValue('title'),
            description: $this->getSoleValue('description'),
            link: $this->getSoleValue('link'),
            language: $this->getSoleValue('language'),
            categories: $this->getCategories(),
            explicit: $this->getSoleValue('itunes:explicit') === 'yes',
            image: $this->getSoleValue('itunes:image@href'),
            metadata: $this->getMetadata()
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

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
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

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getMetadata(): ChannelMetadata
    {
        return new ChannelMetadata(
            locked: $this->getSoleValue('podcast:locked') === 'yes',
            guid: $this->getSoleValue('podcast:guid'),
            author: $this->getSoleValue('itunes:author'),
            copyright: $this->getSoleValue('copyright'),
            txts: $this->getTxts(),
            fundings: $this->getFundings(),
            type: PodcastType::tryFrom($this->getSoleValue('itunes:type') ?? ''),
            complete: $this->getSoleValue('itunes:complete') === 'yes',
        );
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getFundings(): FundingCollection
    {
        return FundingCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.podcast:funding')->collectLazy()
        );
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getCategories(): CategoryCollection
    {
        return CategoryCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.itunes:category')->collectLazy()
        );
    }

    /**
     * @throws EncodingException
     * @throws QueryAlreadyReadException
     * @throws Throwable
     * @throws XmlReaderException
     */
    private function getTxts(): TxtCollection
    {
        return TxtCollection::fromXmlElements(
            $this->xmlReader->element('rss.channel.podcast:txt')->collectLazy()
        );
    }
}
