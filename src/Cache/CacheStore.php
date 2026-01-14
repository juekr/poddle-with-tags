<?php

namespace PhanAn\Poddle\Cache;

use PDO;
use PDOException;
use PhanAn\Poddle\Values\Category;
use PhanAn\Poddle\Values\Channel;
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
        $fetchedAt = time();
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO podcasts (feed_url, xml, checksum, fetched_at, refreshed_at)
                VALUES (:feed_url, :xml, :checksum, :fetched_at, :fetched_at)
                ON CONFLICT(feed_url) DO UPDATE SET
                    xml = excluded.xml,
                    checksum = excluded.checksum,
                    fetched_at = excluded.fetched_at,
                    refreshed_at = excluded.refreshed_at
                SQL
            )->execute([
                'feed_url' => $feedUrl,
                'xml' => $xml,
                'checksum' => $checksum,
                'fetched_at' => $fetchedAt,
            ]);

            $podcastId = $this->getPodcastId($feedUrl);

            $this->persistChannel($podcastId, $channel);
            $this->persistCollections($podcastId, $channel);
            $this->persistEpisodes($podcastId, $episodes);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function deletePodcast(string $feedUrl): void
    {
        $podcastId = $this->getPodcastId($feedUrl);

        if ($podcastId === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            foreach (['categories', 'fundings', 'txts', 'episodes', 'transcripts', 'keywords', 'channels', 'podcasts'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE podcast_id = :podcast_id")->execute([
                    'podcast_id' => $podcastId,
                ]);
            }

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function deleteEpisode(string $feedUrl, string $guid): void
    {
        $podcastId = $this->getPodcastId($feedUrl);

        if ($podcastId === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM episodes WHERE podcast_id = :podcast_id AND guid = :guid')->execute([
                'podcast_id' => $podcastId,
                'guid' => $guid,
            ]);

            $this->pdo->prepare('DELETE FROM transcripts WHERE podcast_id = :podcast_id AND episode_guid = :guid')->execute([
                'podcast_id' => $podcastId,
                'guid' => $guid,
            ]);

            $this->pdo->prepare('DELETE FROM keywords WHERE podcast_id = :podcast_id AND episode_guid = :guid')->execute([
                'podcast_id' => $podcastId,
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
        $podcastId = $this->getPodcastId($feedUrl);

        if ($podcastId === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO episodes (podcast_id, guid, data, checksum, updated_at)
                VALUES (:podcast_id, :guid, :data, :checksum, :updated_at)
                ON CONFLICT(podcast_id, guid) DO UPDATE SET
                    data = excluded.data,
                    checksum = excluded.checksum,
                    updated_at = excluded.updated_at
                SQL
            )->execute([
                'podcast_id' => $podcastId,
                'guid' => $episode->guid->value,
                'data' => json_encode($episode->toArray(), JSON_THROW_ON_ERROR),
                'checksum' => hash($this->config->checksumAlgo, json_encode($episode->toArray(), JSON_THROW_ON_ERROR)),
                'updated_at' => time(),
            ]);

            $this->pdo->prepare('DELETE FROM transcripts WHERE podcast_id = :podcast_id AND episode_guid = :guid')->execute([
                'podcast_id' => $podcastId,
                'guid' => $episode->guid->value,
            ]);

            $this->pdo->prepare('DELETE FROM keywords WHERE podcast_id = :podcast_id AND episode_guid = :guid')->execute([
                'podcast_id' => $podcastId,
                'guid' => $episode->guid->value,
            ]);

            $this->persistEpisodeCollections($podcastId, $episode);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateChannel(string $feedUrl, Channel $channel): void
    {
        $podcastId = $this->getPodcastId($feedUrl);

        if ($podcastId === null) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $this->persistChannel($podcastId, $channel);
            $this->persistCollections($podcastId, $channel);

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

        $channelRow = $this->pdo->prepare('SELECT * FROM channels WHERE podcast_id = :podcast_id');
        $channelRow->execute(['podcast_id' => $podcast['id']]);
        $channel = $channelRow->fetch();

        if (!$channel) {
            return null;
        }

        $episodes = $this->pdo->prepare('SELECT * FROM episodes WHERE podcast_id = :podcast_id ORDER BY id');
        $episodes->execute(['podcast_id' => $podcast['id']]);
        $episodeRows = $episodes->fetchAll();

        return new CachedPodcast(
            feedUrl: $feedUrl,
            xml: $podcast['xml'],
            checksum: $podcast['checksum'],
            fetchedAt: (int) $podcast['fetched_at'],
            channel: Channel::fromArray(json_decode($channel['data'], true)),
            episodes: array_map(
                static fn (array $row): Episode => Episode::fromArray(json_decode($row['data'], true)),
                $episodeRows
            )
        );
    }

    private function getPodcastId(string $feedUrl): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM podcasts WHERE feed_url = :feed_url');
        $statement->execute(['feed_url' => $feedUrl]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function persistChannel(int $podcastId, Channel $channel): void
    {
        $this->pdo->prepare(
            <<<SQL
            INSERT INTO channels (podcast_id, data, checksum, updated_at)
            VALUES (:podcast_id, :data, :checksum, :updated_at)
            ON CONFLICT(podcast_id) DO UPDATE SET
                data = excluded.data,
                checksum = excluded.checksum,
                updated_at = excluded.updated_at
            SQL
        )->execute([
            'podcast_id' => $podcastId,
            'data' => json_encode($channel->toArray(), JSON_THROW_ON_ERROR),
            'checksum' => hash($this->config->checksumAlgo, json_encode($channel->toArray(), JSON_THROW_ON_ERROR)),
            'updated_at' => time(),
        ]);
    }

    private function persistCollections(int $podcastId, Channel $channel): void
    {
        foreach (['categories', 'fundings', 'txts'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE podcast_id = :podcast_id")->execute([
                'podcast_id' => $podcastId,
            ]);
        }

        $insertCategory = $this->pdo->prepare(
            'INSERT INTO categories (podcast_id, text, sub_text) VALUES (:podcast_id, :text, :sub_text)'
        );

        /** @var Category $category */
        foreach ($channel->categories as $category) {
            $insertCategory->execute([
                'podcast_id' => $podcastId,
                'text' => $category->text,
                'sub_text' => $category->subCategory?->text,
            ]);
        }

        $insertFunding = $this->pdo->prepare(
            'INSERT INTO fundings (podcast_id, url, text) VALUES (:podcast_id, :url, :text)'
        );

        /** @var Funding $funding */
        foreach ($channel->metadata->fundings as $funding) {
            $insertFunding->execute([
                'podcast_id' => $podcastId,
                'url' => $funding->url,
                'text' => $funding->text,
            ]);
        }

        $insertTxt = $this->pdo->prepare(
            'INSERT INTO txts (podcast_id, content, purpose) VALUES (:podcast_id, :content, :purpose)'
        );

        /** @var Txt $txt */
        foreach ($channel->metadata->txts as $txt) {
            $insertTxt->execute([
                'podcast_id' => $podcastId,
                'content' => $txt->content,
                'purpose' => $txt->purpose,
            ]);
        }
    }

    private function persistEpisodes(int $podcastId, iterable $episodes): void
    {
        foreach (['episodes', 'transcripts', 'keywords'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE podcast_id = :podcast_id")->execute([
                'podcast_id' => $podcastId,
            ]);
        }

        $insertEpisode = $this->pdo->prepare(
            <<<SQL
            INSERT INTO episodes (podcast_id, guid, data, checksum, updated_at)
            VALUES (:podcast_id, :guid, :data, :checksum, :updated_at)
            SQL
        );

        /** @var Episode $episode */
        foreach ($episodes as $episode) {
            $payload = json_encode($episode->toArray(), JSON_THROW_ON_ERROR);

            $insertEpisode->execute([
                'podcast_id' => $podcastId,
                'guid' => $episode->guid->value,
                'data' => $payload,
                'checksum' => hash($this->config->checksumAlgo, $payload),
                'updated_at' => time(),
            ]);

            $this->persistEpisodeCollections($podcastId, $episode);
        }
    }

    private function persistEpisodeCollections(int $podcastId, Episode $episode): void
    {
        $insertTranscript = $this->pdo->prepare(
            <<<SQL
            INSERT INTO transcripts (podcast_id, episode_guid, url, type, language, rel)
            VALUES (:podcast_id, :episode_guid, :url, :type, :language, :rel)
            SQL
        );

        /** @var Transcript $transcript */
        foreach ($episode->metadata->transcripts as $transcript) {
            $insertTranscript->execute([
                'podcast_id' => $podcastId,
                'episode_guid' => $episode->guid->value,
                'url' => $transcript->url,
                'type' => $transcript->type,
                'language' => $transcript->language,
                'rel' => $transcript->rel,
            ]);
        }

        $insertKeyword = $this->pdo->prepare(
            'INSERT INTO keywords (podcast_id, episode_guid, keyword) VALUES (:podcast_id, :episode_guid, :keyword)'
        );

        foreach ($episode->metadata->keywords as $keyword) {
            $insertKeyword->execute([
                'podcast_id' => $podcastId,
                'episode_guid' => $episode->guid->value,
                'keyword' => $keyword,
            ]);
        }
    }

    private function initializeSchema(): void
    {
        $this->pdo->exec(
            <<<SQL
            CREATE TABLE IF NOT EXISTS podcasts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                feed_url TEXT UNIQUE NOT NULL,
                xml TEXT NOT NULL,
                checksum TEXT NOT NULL,
                fetched_at INTEGER NOT NULL,
                refreshed_at INTEGER
            );
            CREATE TABLE IF NOT EXISTS channels (
                podcast_id INTEGER PRIMARY KEY,
                data TEXT NOT NULL,
                checksum TEXT NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (podcast_id) REFERENCES podcasts(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS categories (
                podcast_id INTEGER NOT NULL,
                text TEXT NOT NULL,
                sub_text TEXT,
                FOREIGN KEY (podcast_id) REFERENCES podcasts(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS fundings (
                podcast_id INTEGER NOT NULL,
                url TEXT NOT NULL,
                text TEXT NOT NULL,
                FOREIGN KEY (podcast_id) REFERENCES podcasts(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS txts (
                podcast_id INTEGER NOT NULL,
                content TEXT NOT NULL,
                purpose TEXT,
                FOREIGN KEY (podcast_id) REFERENCES podcasts(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS episodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                podcast_id INTEGER NOT NULL,
                guid TEXT NOT NULL,
                data TEXT NOT NULL,
                checksum TEXT NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE (podcast_id, guid),
                FOREIGN KEY (podcast_id) REFERENCES podcasts(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS transcripts (
                podcast_id INTEGER NOT NULL,
                episode_guid TEXT NOT NULL,
                url TEXT NOT NULL,
                type TEXT NOT NULL,
                language TEXT,
                rel TEXT,
                FOREIGN KEY (podcast_id) REFERENCES podcasts(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS keywords (
                podcast_id INTEGER NOT NULL,
                episode_guid TEXT NOT NULL,
                keyword TEXT NOT NULL,
                FOREIGN KEY (podcast_id) REFERENCES podcasts(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_podcasts_feed_url ON podcasts(feed_url);
            CREATE INDEX IF NOT EXISTS idx_episodes_guid ON episodes(guid);
            CREATE INDEX IF NOT EXISTS idx_keywords_episode ON keywords(episode_guid);
            CREATE INDEX IF NOT EXISTS idx_transcripts_episode ON transcripts(episode_guid);
            SQL
        );
    }
}
