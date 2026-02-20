<?php

namespace PhanAn\Poddle\Cache;

use DateTimeInterface;
use PDO;
use PDOException;
use PhanAn\Poddle\Values\Category;
use PhanAn\Poddle\Values\Channel;
use PhanAn\Poddle\Values\Chapter;
use PhanAn\Poddle\Values\Episode;
use PhanAn\Poddle\Values\Funding;
use PhanAn\Poddle\Values\Transcript;
use PhanAn\Poddle\Values\Txt;

class CacheStore
{
    private PDO $pdo;

    public function __construct(private readonly CacheConfig $config)
    {
        $this->pdo = $config->pdo ?? new PDO('sqlite:' . $config->path());
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->initializeSchema();
    }

    public function persistPodcast(string $feedUrl, string $xml, Channel $channel, iterable $episodes, string $checksum): void
    {
        $now = time();
        $this->pdo->beginTransaction();

        try {
            $podcastGuid = $this->resolvePodcastGuid($channel, $feedUrl);
            $channelChecksum = $this->hashPayload($channel->toArray());

            $this->pdo->prepare(
                <<<SQL
                INSERT INTO podcasts (
                    guid,
                    metadata_guid,
                    feed_url,
                    url,
                    atom_link,
                    title,
                    description,
                    link,
                    language,
                    explicit,
                    image,
                    locked,
                    author,
                    copyright,
                    type,
                    complete,
                    checksum,
                    channel_checksum,
                    xml,
                    fetched_at,
                    last_updated
                ) VALUES (
                    :guid,
                    :metadata_guid,
                    :feed_url,
                    :url,
                    :atom_link,
                    :title,
                    :description,
                    :link,
                    :language,
                    :explicit,
                    :image,
                    :locked,
                    :author,
                    :copyright,
                    :type,
                    :complete,
                    :checksum,
                    :channel_checksum,
                    :xml,
                    :fetched_at,
                    :last_updated
                )
                ON CONFLICT(guid) DO UPDATE SET
                    metadata_guid = excluded.metadata_guid,
                    feed_url = excluded.feed_url,
                    url = excluded.url,
                    atom_link = excluded.atom_link,
                    title = excluded.title,
                    description = excluded.description,
                    link = excluded.link,
                    language = excluded.language,
                    explicit = excluded.explicit,
                    image = excluded.image,
                    locked = excluded.locked,
                    author = excluded.author,
                    copyright = excluded.copyright,
                    type = excluded.type,
                    complete = excluded.complete,
                    checksum = excluded.checksum,
                    channel_checksum = excluded.channel_checksum,
                    xml = excluded.xml,
                    fetched_at = excluded.fetched_at,
                    last_updated = excluded.last_updated
                SQL
            )->execute([
                'guid' => $podcastGuid,
                'metadata_guid' => $channel->metadata->guid,
                'feed_url' => $feedUrl,
                'url' => $channel->url,
                'atom_link' => $channel->atomLink,
                'title' => $channel->title,
                'description' => $channel->description,
                'link' => $channel->link,
                'language' => $channel->language,
                'explicit' => $channel->explicit ? 1 : 0,
                'image' => $channel->image,
                'locked' => $channel->metadata->locked ? 1 : 0,
                'author' => $channel->metadata->author,
                'copyright' => $channel->metadata->copyright,
                'type' => $channel->metadata->type?->value,
                'complete' => $channel->metadata->complete ? 1 : 0,
                'checksum' => $checksum,
                'channel_checksum' => $channelChecksum,
                'xml' => $xml,
                'fetched_at' => $now,
                'last_updated' => $now,
            ]);

            $this->clearPodcastMetadataRelations($podcastGuid);
            $this->persistPodcastCategories($podcastGuid, $channel, $now);
            $this->persistPodcastMetadataCollections($podcastGuid, $channel, $now);
            $this->persistEpisodes($podcastGuid, $episodes, $now);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function deletePodcast(string $feedUrl): void
    {
        $podcastGuid = $this->getPodcastGuidByFeedUrl($feedUrl);

        if ($podcastGuid === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM podcasts WHERE guid = :podcast')->execute([
                'podcast' => $podcastGuid,
            ]);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function deleteEpisode(string $feedUrl, string $guid): void
    {
        $podcastGuid = $this->getPodcastGuidByFeedUrl($feedUrl);

        if ($podcastGuid === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM episodes WHERE podcast = :podcast AND guid = :guid')->execute([
                'podcast' => $podcastGuid,
                'guid' => $guid,
            ]);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function upsertEpisode(string $feedUrl, Episode $episode): void
    {
        $podcastGuid = $this->getPodcastGuidByFeedUrl($feedUrl);

        if ($podcastGuid === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $this->upsertEpisodeRow($podcastGuid, $episode, time());

            foreach (['transcripts', 'episode_keywords', 'chapters'] as $table) {
                $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE episode = :guid')->execute([
                    'guid' => $episode->guid->value,
                ]);
            }

            $this->persistEpisodeCollections($episode, time());

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateChannel(string $feedUrl, Channel $channel): void
    {
        $podcastGuid = $this->getPodcastGuidByFeedUrl($feedUrl);

        if ($podcastGuid === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $now = time();
            $channelChecksum = $this->hashPayload($channel->toArray());

            $this->pdo->prepare(
                <<<SQL
                UPDATE podcasts SET
                    metadata_guid = :metadata_guid,
                    url = :url,
                    atom_link = :atom_link,
                    title = :title,
                    description = :description,
                    link = :link,
                    language = :language,
                    explicit = :explicit,
                    image = :image,
                    locked = :locked,
                    author = :author,
                    copyright = :copyright,
                    type = :type,
                    complete = :complete,
                    channel_checksum = :channel_checksum,
                    last_updated = :last_updated
                WHERE guid = :guid
                SQL
            )->execute([
                'metadata_guid' => $channel->metadata->guid,
                'url' => $channel->url,
                'atom_link' => $channel->atomLink,
                'title' => $channel->title,
                'description' => $channel->description,
                'link' => $channel->link,
                'language' => $channel->language,
                'explicit' => $channel->explicit ? 1 : 0,
                'image' => $channel->image,
                'locked' => $channel->metadata->locked ? 1 : 0,
                'author' => $channel->metadata->author,
                'copyright' => $channel->metadata->copyright,
                'type' => $channel->metadata->type?->value,
                'complete' => $channel->metadata->complete ? 1 : 0,
                'channel_checksum' => $channelChecksum,
                'last_updated' => $now,
                'guid' => $podcastGuid,
            ]);

            $this->clearPodcastRelations($podcastGuid);
            $this->persistPodcastCategories($podcastGuid, $channel, $now);
            $this->persistPodcastMetadataCollections($podcastGuid, $channel, $now);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function findPodcast(string $feedUrl): ?CachedPodcast
    {
        $podcastRow = $this->pdo->prepare('SELECT * FROM podcasts WHERE feed_url = :feed_url');
        $podcastRow->execute(['feed_url' => $feedUrl]);
        $podcast = $podcastRow->fetch();

        if (!$podcast) {
            return null;
        }

        $categories = $this->pdo->prepare('SELECT * FROM podcast_categories WHERE podcast = :podcast');
        $categories->execute(['podcast' => $podcast['guid']]);
        $categoryRows = $categories->fetchAll();

        $fundings = $this->pdo->prepare('SELECT * FROM fundings WHERE podcast = :podcast');
        $fundings->execute(['podcast' => $podcast['guid']]);
        $fundingRows = $fundings->fetchAll();

        $txts = $this->pdo->prepare('SELECT * FROM txts WHERE podcast = :podcast');
        $txts->execute(['podcast' => $podcast['guid']]);
        $txtRows = $txts->fetchAll();

        $episodes = $this->pdo->prepare('SELECT * FROM episodes WHERE podcast = :podcast ORDER BY guid');
        $episodes->execute(['podcast' => $podcast['guid']]);
        $episodeRows = $episodes->fetchAll();

        $episodeGuids = array_map(static fn (array $row): string => $row['guid'], $episodeRows);
        $transcriptsByEpisode = $this->fetchByEpisode('transcripts', $episodeGuids);
        $chaptersByEpisode = $this->fetchByEpisode('chapters', $episodeGuids);
        $keywordsByEpisode = $this->fetchByEpisode('episode_keywords', $episodeGuids);

        $channel = Channel::fromArray([
            'url' => $podcast['url'],
            'atom_link' => $podcast['atom_link'],
            'title' => $podcast['title'],
            'description' => $podcast['description'],
            'link' => $podcast['link'],
            'language' => $podcast['language'],
            'categories' => array_map(
                static fn (array $row): array => [
                    'text' => $row['text'],
                    'sub_category' => $row['sub_text'] !== null ? ['text' => $row['sub_text']] : null,
                ],
                $categoryRows
            ),
            'explicit' => (bool) $podcast['explicit'],
            'image' => $podcast['image'],
            'metadata' => [
                'locked' => (bool) $podcast['locked'],
                'guid' => $podcast['metadata_guid'],
                'author' => $podcast['author'],
                'copyright' => $podcast['copyright'],
                'txts' => array_map(
                    static fn (array $row): array => [
                        'content' => $row['content'],
                        'purpose' => $row['purpose'],
                    ],
                    $txtRows
                ),
                'fundings' => array_map(
                    static fn (array $row): array => [
                        'url' => $row['url'],
                        'text' => $row['text'],
                    ],
                    $fundingRows
                ),
                'type' => $podcast['type'],
                'complete' => (bool) $podcast['complete'],
            ],
        ]);

        $episodes = array_map(function (array $row) use ($transcriptsByEpisode, $chaptersByEpisode, $keywordsByEpisode): Episode {
            $transcriptRows = $transcriptsByEpisode[$row['guid']] ?? [];
            $chapterRows = $chaptersByEpisode[$row['guid']] ?? [];
            $keywordRows = $keywordsByEpisode[$row['guid']] ?? [];

            $keywords = array_map(static fn (array $keyword): string => $keyword['keyword'], $keywordRows);

            return Episode::fromArray([
                'title' => $row['title'],
                'guid' => [
                    'value' => $row['guid'],
                    'is_perma_link' => (bool) $row['guid_is_permalink'],
                ],
                'enclosure' => [
                    'url' => $row['enclosure_url'],
                    'type' => $row['enclosure_type'],
                    'length' => (int) ($row['enclosure_length'] ?? 0),
                ],
                'metadata' => [
                    'link' => $row['link'],
                    'pub_date' => $row['pubdate'],
                    'description' => $row['description'],
                    'duration' => $row['duration'],
                    'image' => $row['image'],
                    'explicit' => $row['explicit'],
                    'transcripts' => array_map(
                        static fn (array $transcript): array => [
                            'url' => $transcript['url'],
                            'type' => $transcript['type'],
                            'language' => $transcript['language'],
                            'rel' => $transcript['rel'],
                        ],
                        $transcriptRows
                    ),
                    'chapters' => array_map(
                        static fn (array $chapter): array => [
                            'start' => $chapter['start'],
                            'title' => $chapter['title'],
                            'url' => $chapter['url'],
                            'image' => $chapter['image'],
                        ],
                        $chapterRows
                    ),
                    'episode' => $row['episode'],
                    'season' => $row['season'],
                    'type' => $row['type'],
                    'block' => $row['blocked'],
                    'keywords' => $keywords,
                ],
                'shownotes' => [
                    'content' => $row['shownotes'],
                ],
            ]);
        }, $episodeRows);

        return new CachedPodcast(
            feedUrl: $feedUrl,
            xml: $podcast['xml'] ?? '',
            checksum: $podcast['checksum'] ?? '',
            channelChecksum: $podcast['channel_checksum'] ?? '',
            fetchedAt: (int) ($podcast['fetched_at'] ?? 0),
            lastUpdated: (int) ($podcast['last_updated'] ?? 0),
            channel: $channel,
            episodes: $episodes
        );
    }

    public function getPodcastGuidByFeedUrl(string $feedUrl): ?string
    {
        $statement = $this->pdo->prepare('SELECT guid FROM podcasts WHERE feed_url = :feed_url');
        $statement->execute(['feed_url' => $feedUrl]);
        $guid = $statement->fetchColumn();

        return $guid === false ? null : (string) $guid;
    }

    public function getEpisodeChecksums(string $podcastGuid): array
    {
        $statement = $this->pdo->prepare('SELECT guid, checksum FROM episodes WHERE podcast = :podcast');
        $statement->execute(['podcast' => $podcastGuid]);
        $rows = $statement->fetchAll();

        $checksums = [];
        foreach ($rows as $row) {
            $checksums[$row['guid']] = $row['checksum'];
        }

        return $checksums;
    }

    public function getStaleTimestamp(string $podcastGuid): ?int
    {
        $timestamps = [];

        $podcast = $this->pdo->prepare('SELECT last_updated FROM podcasts WHERE guid = :podcast');
        $podcast->execute(['podcast' => $podcastGuid]);
        $value = $podcast->fetchColumn();
        if ($value !== false && $value !== null) {
            $timestamps[] = (int) $value;
        }

        foreach (['episodes', 'podcast_categories', 'fundings', 'txts', 'transcripts', 'episode_keywords', 'chapters'] as $table) {
            $statement = match ($table) {
                'transcripts', 'episode_keywords', 'chapters'
                    => $this->pdo->prepare(
                        'SELECT MIN(last_updated) FROM ' . $table . ' WHERE episode IN (SELECT guid FROM episodes WHERE podcast = :podcast)'
                    ),
                default
                    => $this->pdo->prepare('SELECT MIN(last_updated) FROM ' . $table . ' WHERE podcast = :podcast'),
            };

            $statement->execute(['podcast' => $podcastGuid]);
            $min = $statement->fetchColumn();
            if ($min !== false && $min !== null) {
                $timestamps[] = (int) $min;
            }
        }

        if ($timestamps === []) {
            return null;
        }

        return min($timestamps);
    }

    public function touchPodcast(string $podcastGuid): void
    {
        $now = time();
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('UPDATE podcasts SET last_updated = :now, fetched_at = :now WHERE guid = :podcast')->execute([
                'now' => $now,
                'podcast' => $podcastGuid,
            ]);

            foreach (['episodes', 'podcast_categories', 'fundings', 'txts'] as $table) {
                $this->pdo->prepare('UPDATE ' . $table . ' SET last_updated = :now WHERE podcast = :podcast')->execute([
                    'now' => $now,
                    'podcast' => $podcastGuid,
                ]);
            }

            foreach (['transcripts', 'episode_keywords', 'chapters'] as $table) {
                $this->pdo->prepare(
                    'UPDATE ' . $table . ' SET last_updated = :now WHERE episode IN (SELECT guid FROM episodes WHERE podcast = :podcast)'
                )->execute([
                    'now' => $now,
                    'podcast' => $podcastGuid,
                ]);
            }

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function resolvePodcastGuid(Channel $channel, string $feedUrl): string
    {
        return $channel->metadata->guid
            ?? hash($this->config->checksumAlgo, $feedUrl);
    }

    private function clearPodcastRelations(string $podcastGuid): void
    {
        foreach (['podcast_categories', 'fundings', 'txts', 'episodes', 'transcripts', 'episode_keywords', 'chapters'] as $table) {
            if (in_array($table, ['transcripts', 'episode_keywords', 'chapters'], true)) {
                $this->pdo->prepare(
                    'DELETE FROM ' . $table . ' WHERE episode IN (SELECT guid FROM episodes WHERE podcast = :podcast)'
                )->execute([
                    'podcast' => $podcastGuid,
                ]);
                continue;
            }

            $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE podcast = :podcast')->execute([
                'podcast' => $podcastGuid,
            ]);
        }
    }

    private function clearPodcastMetadataRelations(string $podcastGuid): void
    {
        foreach (['podcast_categories', 'fundings', 'txts'] as $table) {
            $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE podcast = :podcast')->execute([
                'podcast' => $podcastGuid,
            ]);
        }
    }

    private function persistPodcastCategories(string $podcastGuid, Channel $channel, int $now): void
    {
        $insertCategory = $this->pdo->prepare(
            'INSERT INTO podcast_categories (podcast, text, sub_text, last_updated) VALUES (:podcast, :text, :sub_text, :last_updated)'
        );

        /** @var Category $category */
        foreach ($channel->categories as $category) {
            $insertCategory->execute([
                'podcast' => $podcastGuid,
                'text' => $category->text,
                'sub_text' => $category->subCategory?->text,
                'last_updated' => $now,
            ]);
        }
    }

    private function persistPodcastMetadataCollections(string $podcastGuid, Channel $channel, int $now): void
    {
        $insertFunding = $this->pdo->prepare(
            'INSERT INTO fundings (podcast, url, text, last_updated) VALUES (:podcast, :url, :text, :last_updated)'
        );

        /** @var Funding $funding */
        foreach ($channel->metadata->fundings as $funding) {
            $insertFunding->execute([
                'podcast' => $podcastGuid,
                'url' => $funding->url,
                'text' => $funding->text,
                'last_updated' => $now,
            ]);
        }

        $insertTxt = $this->pdo->prepare(
            'INSERT INTO txts (podcast, content, purpose, last_updated) VALUES (:podcast, :content, :purpose, :last_updated)'
        );

        /** @var Txt $txt */
        foreach ($channel->metadata->txts as $txt) {
            $insertTxt->execute([
                'podcast' => $podcastGuid,
                'content' => $txt->content,
                'purpose' => $txt->purpose,
                'last_updated' => $now,
            ]);
        }
    }

    private function persistEpisodes(string $podcastGuid, iterable $episodes, int $now): void
    {
        $insertEpisode = $this->pdo->prepare(
            <<<SQL
            INSERT INTO episodes (
                guid,
                guid_is_permalink,
                podcast,
                type,
                title,
                link,
                pubdate,
                keywords_raw,
                shownotes,
                description,
                season,
                episode,
                duration,
                enclosure_url,
                enclosure_type,
                enclosure_length,
                image,
                explicit,
                blocked,
                checksum,
                last_updated
            ) VALUES (
                :guid,
                :guid_is_permalink,
                :podcast,
                :type,
                :title,
                :link,
                :pubdate,
                :keywords_raw,
                :shownotes,
                :description,
                :season,
                :episode,
                :duration,
                :enclosure_url,
                :enclosure_type,
                :enclosure_length,
                :image,
                :explicit,
                :blocked,
                :checksum,
                :last_updated
            )
            ON CONFLICT(guid) DO UPDATE SET
                guid_is_permalink = excluded.guid_is_permalink,
                podcast = excluded.podcast,
                type = excluded.type,
                title = excluded.title,
                link = excluded.link,
                pubdate = excluded.pubdate,
                keywords_raw = excluded.keywords_raw,
                shownotes = excluded.shownotes,
                description = excluded.description,
                season = excluded.season,
                episode = excluded.episode,
                duration = excluded.duration,
                enclosure_url = excluded.enclosure_url,
                enclosure_type = excluded.enclosure_type,
                enclosure_length = excluded.enclosure_length,
                image = excluded.image,
                explicit = excluded.explicit,
                blocked = excluded.blocked,
                checksum = excluded.checksum,
                last_updated = excluded.last_updated
            SQL
        );

        /** @var Episode $episode */
        foreach ($episodes as $episode) {
            $payload = $episode->toArray();
            $checksum = $this->hashPayload($payload);
            $keywords = $episode->metadata->keywords->all();
            $keywordsRaw = $keywords !== [] ? implode(', ', $keywords) : null;

            $insertEpisode->execute([
                'guid' => $episode->guid->value,
                'guid_is_permalink' => $episode->guid->isPermaLink ? 1 : 0,
                'podcast' => $podcastGuid,
                'type' => $episode->metadata->type?->value,
                'title' => $episode->title,
                'link' => $episode->metadata->link,
                'pubdate' => $episode->metadata->pubDate?->format(DateTimeInterface::RFC2822),
                'keywords_raw' => $keywordsRaw,
                'shownotes' => $episode->shownotes->content,
                'description' => $episode->metadata->description,
                'season' => $episode->metadata->season,
                'episode' => $episode->metadata->episode,
                'duration' => $episode->metadata->duration,
                'enclosure_url' => $episode->enclosure->url,
                'enclosure_type' => $episode->enclosure->type,
                'enclosure_length' => $episode->enclosure->length,
                'image' => $episode->metadata->image,
                'explicit' => $episode->metadata->explicit === null ? null : ($episode->metadata->explicit ? 1 : 0),
                'blocked' => $episode->metadata->block === null ? null : ($episode->metadata->block ? 1 : 0),
                'checksum' => $checksum,
                'last_updated' => $now,
            ]);

            $this->persistEpisodeCollections($episode, $now);
        }
    }

    private function upsertEpisodeRow(string $podcastGuid, Episode $episode, int $now): void
    {
        $payload = $episode->toArray();
        $checksum = $this->hashPayload($payload);
        $keywords = $episode->metadata->keywords->all();
        $keywordsRaw = $keywords !== [] ? implode(', ', $keywords) : null;

        $this->pdo->prepare(
            <<<SQL
            INSERT INTO episodes (
                guid,
                guid_is_permalink,
                podcast,
                type,
                title,
                link,
                pubdate,
                keywords_raw,
                shownotes,
                description,
                season,
                episode,
                duration,
                enclosure_url,
                enclosure_type,
                enclosure_length,
                image,
                explicit,
                blocked,
                checksum,
                last_updated
            ) VALUES (
                :guid,
                :guid_is_permalink,
                :podcast,
                :type,
                :title,
                :link,
                :pubdate,
                :keywords_raw,
                :shownotes,
                :description,
                :season,
                :episode,
                :duration,
                :enclosure_url,
                :enclosure_type,
                :enclosure_length,
                :image,
                :explicit,
                :blocked,
                :checksum,
                :last_updated
            )
            ON CONFLICT(guid) DO UPDATE SET
                guid_is_permalink = excluded.guid_is_permalink,
                podcast = excluded.podcast,
                type = excluded.type,
                title = excluded.title,
                link = excluded.link,
                pubdate = excluded.pubdate,
                keywords_raw = excluded.keywords_raw,
                shownotes = excluded.shownotes,
                description = excluded.description,
                season = excluded.season,
                episode = excluded.episode,
                duration = excluded.duration,
                enclosure_url = excluded.enclosure_url,
                enclosure_type = excluded.enclosure_type,
                enclosure_length = excluded.enclosure_length,
                image = excluded.image,
                explicit = excluded.explicit,
                blocked = excluded.blocked,
                checksum = excluded.checksum,
                last_updated = excluded.last_updated
            SQL
        )->execute([
            'guid' => $episode->guid->value,
            'guid_is_permalink' => $episode->guid->isPermaLink ? 1 : 0,
            'podcast' => $podcastGuid,
            'type' => $episode->metadata->type?->value,
            'title' => $episode->title,
            'link' => $episode->metadata->link,
            'pubdate' => $episode->metadata->pubDate?->format(DateTimeInterface::RFC2822),
            'keywords_raw' => $keywordsRaw,
            'shownotes' => $episode->shownotes->content,
            'description' => $episode->metadata->description,
            'season' => $episode->metadata->season,
            'episode' => $episode->metadata->episode,
            'duration' => $episode->metadata->duration,
            'enclosure_url' => $episode->enclosure->url,
            'enclosure_type' => $episode->enclosure->type,
            'enclosure_length' => $episode->enclosure->length,
            'image' => $episode->metadata->image,
            'explicit' => $episode->metadata->explicit === null ? null : ($episode->metadata->explicit ? 1 : 0),
            'blocked' => $episode->metadata->block === null ? null : ($episode->metadata->block ? 1 : 0),
            'checksum' => $checksum,
            'last_updated' => $now,
        ]);
    }

    private function persistEpisodeCollections(Episode $episode, int $now): void
    {
        $insertTranscript = $this->pdo->prepare(
            <<<SQL
            INSERT INTO transcripts (episode, url, type, language, rel, content, is_url, last_updated)
            VALUES (:episode, :url, :type, :language, :rel, :content, :is_url, :last_updated)
            SQL
        );

        /** @var Transcript $transcript */
        foreach ($episode->metadata->transcripts as $transcript) {
            $insertTranscript->execute([
                'episode' => $episode->guid->value,
                'url' => $transcript->url,
                'type' => $transcript->type,
                'language' => $transcript->language,
                'rel' => $transcript->rel,
                'content' => null,
                'is_url' => 1,
                'last_updated' => $now,
            ]);
        }

        $insertKeyword = $this->pdo->prepare(
            'INSERT INTO episode_keywords (episode, keyword, last_updated) VALUES (:episode, :keyword, :last_updated)'
        );

        foreach ($episode->metadata->keywords as $keyword) {
            $insertKeyword->execute([
                'episode' => $episode->guid->value,
                'keyword' => $keyword,
                'last_updated' => $now,
            ]);
        }

        $insertChapter = $this->pdo->prepare(
            <<<SQL
            INSERT INTO chapters (episode, position, start, title, url, image, last_updated)
            VALUES (:episode, :position, :start, :title, :url, :image, :last_updated)
            SQL
        );

        /** @var Chapter $chapter */
        foreach ($episode->metadata->chapters as $index => $chapter) {
            $insertChapter->execute([
                'episode' => $episode->guid->value,
                'position' => (int) $index,
                'start' => $chapter->start,
                'title' => $chapter->title,
                'url' => $chapter->url,
                'image' => $chapter->image,
                'last_updated' => $now,
            ]);
        }
    }

    private function fetchByEpisode(string $table, array $episodeGuids): array
    {
        if ($episodeGuids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($episodeGuids), '?'));
        $statement = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE episode IN (' . $placeholders . ')');
        $statement->execute($episodeGuids);
        $rows = $statement->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['episode']][] = $row;
        }

        return $grouped;
    }

    private function hashPayload(array $payload): string
    {
        return hash($this->config->checksumAlgo, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function initializeSchema(): void
    {
        if ($this->requiresRebuild()) {
            $this->rebuildSchema();
            return;
        }

        $this->pdo->exec(
            <<<SQL
            CREATE TABLE IF NOT EXISTS podcasts (
                guid TEXT PRIMARY KEY,
                metadata_guid TEXT,
                feed_url TEXT UNIQUE,
                url TEXT,
                atom_link TEXT,
                title TEXT,
                subtitle TEXT,
                description TEXT,
                link TEXT,
                pubdate TEXT,
                author TEXT,
                contact TEXT,
                type TEXT,
                explicit INTEGER,
                image TEXT,
                locked INTEGER,
                language TEXT,
                generator TEXT,
                copyright TEXT,
                complete INTEGER,
                apple_id TEXT,
                pocket_casts_id TEXT,
                spotify_id TEXT,
                youtube_id TEXT,
                checksum TEXT,
                channel_checksum TEXT,
                xml TEXT,
                fetched_at INTEGER,
                last_updated INTEGER
            );
            CREATE TABLE IF NOT EXISTS podcast_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                podcast TEXT NOT NULL,
                text TEXT NOT NULL,
                sub_text TEXT,
                last_updated INTEGER,
                FOREIGN KEY (podcast) REFERENCES podcasts(guid) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS fundings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                podcast TEXT NOT NULL,
                url TEXT,
                text TEXT,
                last_updated INTEGER,
                FOREIGN KEY (podcast) REFERENCES podcasts(guid) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS txts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                podcast TEXT NOT NULL,
                content TEXT,
                purpose TEXT,
                last_updated INTEGER,
                FOREIGN KEY (podcast) REFERENCES podcasts(guid) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS episodes (
                guid TEXT PRIMARY KEY,
                guid_is_permalink INTEGER,
                podcast TEXT NOT NULL,
                type TEXT,
                title TEXT,
                link TEXT,
                pubdate TEXT,
                keywords_raw TEXT,
                shownotes TEXT,
                description TEXT,
                season INTEGER,
                episode INTEGER,
                duration INTEGER,
                enclosure_url TEXT,
                enclosure_type TEXT,
                enclosure_length INTEGER,
                image TEXT,
                explicit INTEGER,
                blocked INTEGER,
                checksum TEXT,
                last_updated INTEGER,
                FOREIGN KEY (podcast) REFERENCES podcasts(guid) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS transcripts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                episode TEXT NOT NULL,
                url TEXT,
                type TEXT,
                language TEXT,
                rel TEXT,
                content TEXT,
                is_url INTEGER DEFAULT 1,
                last_updated INTEGER,
                FOREIGN KEY (episode) REFERENCES episodes(guid) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS episode_keywords (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                episode TEXT NOT NULL,
                keyword TEXT NOT NULL,
                last_updated INTEGER,
                FOREIGN KEY (episode) REFERENCES episodes(guid) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS chapters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                episode TEXT NOT NULL,
                position INTEGER NOT NULL,
                start TEXT,
                title TEXT,
                url TEXT,
                image TEXT,
                last_updated INTEGER,
                FOREIGN KEY (episode) REFERENCES episodes(guid) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_podcasts_feed_url ON podcasts(feed_url);
            CREATE INDEX IF NOT EXISTS idx_episodes_podcast ON episodes(podcast);
            CREATE INDEX IF NOT EXISTS idx_episodes_guid ON episodes(guid);
            CREATE INDEX IF NOT EXISTS idx_keywords_episode ON episode_keywords(episode);
            CREATE INDEX IF NOT EXISTS idx_transcripts_episode ON transcripts(episode);
            CREATE INDEX IF NOT EXISTS idx_chapters_episode ON chapters(episode);
            SQL
        );
    }

    private function requiresRebuild(): bool
    {
        $podcastsColumns = $this->getTableColumns('podcasts');
        $episodesColumns = $this->getTableColumns('episodes');

        if ($podcastsColumns === [] || $episodesColumns === []) {
            return false;
        }

        return in_array('id', $podcastsColumns, true)
            || in_array('podcast_id', $episodesColumns, true);
    }

    private function rebuildSchema(): void
    {
        $this->pdo->beginTransaction();

        try {
            foreach ([
                'chapters',
                'episode_keywords',
                'transcripts',
                'episodes',
                'txts',
                'fundings',
                'podcast_categories',
                'podcasts',
            ] as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
            }

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $this->initializeSchema();
    }

    private function getTableColumns(string $table): array
    {
        $statement = $this->pdo->prepare('PRAGMA table_info(' . $table . ')');
        $statement->execute();
        $rows = $statement->fetchAll();

        if (!$rows) {
            return [];
        }

        return array_map(static fn (array $row): string => $row['name'], $rows);
    }
}
