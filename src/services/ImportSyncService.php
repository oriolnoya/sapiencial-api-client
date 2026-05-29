<?php

namespace sapiencial\sapiencialapiclient\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\models\Section;
use craft\models\Site;
use DateTime;
use sapiencial\sapiencialapiclient\Plugin;
use sapiencial\sapiencialapiclient\records\EntityMapRecord;
use sapiencial\sapiencialapiclient\records\ImportLogRecord;
use Throwable;
use yii\base\Exception;

class ImportSyncService extends Component
{
    public function importBook(int $remoteBookId, string $site, bool $dryRun = false): array
    {
        return $this->runBookSync('import', $remoteBookId, $site, $dryRun);
    }

    public function syncBook(int $remoteBookId, string $site, bool $dryRun = false): array
    {
        return $this->runBookSync('sync', $remoteBookId, $site, $dryRun);
    }

    private function runBookSync(string $mode, int $remoteBookId, string $site, bool $dryRun): array
    {
        Plugin::$plugin->get('contentModel')->ensureContentModel();
        $siteKey = mb_strtolower(trim($site));

        $started = microtime(true);
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
        $errors = [];

        try {
            $graph = $this->fetchBookGraph($remoteBookId, $site);
            $upserted = [];

            if (!$dryRun) {
                $bookEntryId = $this->upsertEntity('book', $graph['book'], $siteKey, null, $counts);
                $upserted['book'][] = $graph['book']['id'];

                $chapterEntryIds = [];
                foreach ($graph['chapters'] as $chapter) {
                    $chapterEntryId = $this->upsertEntity('chapter', $chapter, $siteKey, $bookEntryId, $counts);
                    $chapterEntryIds[$chapter['id']] = $chapterEntryId;
                    $upserted['chapter'][] = $chapter['id'];
                }

                foreach ($graph['resourcesByChapter'] as $chapterRemoteId => $resources) {
                    $parentChapterEntryId = $chapterEntryIds[(int)$chapterRemoteId] ?? null;
                    foreach ($resources as $resource) {
                        $this->upsertEntity('resource', $resource, $siteKey, $parentChapterEntryId, $counts);
                        $upserted['resource'][] = $resource['id'];
                    }
                }

                foreach ($graph['persons'] as $person) {
                    $this->upsertEntity('person', $person, $siteKey, $bookEntryId, $counts);
                    $upserted['person'][] = $person['id'];
                }

                foreach ($graph['topics'] as $topic) {
                    $this->upsertEntity('topic', $topic, $siteKey, $bookEntryId, $counts);
                    $upserted['topic'][] = $topic['id'];
                }

                $this->syncRelations($siteKey, $graph, $bookEntryId, $chapterEntryIds);
                $counts['deleted'] += $this->deleteMissingDescendants($siteKey, $bookEntryId, $upserted);
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $status = empty($errors) ? 'ok' : 'error';
        $this->logOperation($mode, $remoteBookId, $siteKey, $status, $counts, $errors, $durationMs, $dryRun);

        return [
            'success' => empty($errors),
            'mode' => $mode,
            'counts' => $counts,
            'errors' => $errors,
            'durationMs' => $durationMs,
            'dryRun' => $dryRun,
        ];
    }

    private function fetchBookGraph(int $remoteBookId, string $site): array
    {
        $api = Plugin::$plugin->get('apiClient');

        $book = $api->fetchByType('book', $remoteBookId, $site);
        if (!isset($book['id'])) {
            throw new Exception('Book payload invàlid: missing id');
        }

        $chapters = [];
        $chaptersById = [];
        $resourcesByChapter = [];
        $chapterIds = array_map(static fn(array $c): int => (int)($c['id'] ?? 0), $book['chapters'] ?? []);

        foreach (array_filter($chapterIds) as $chapterId) {
            $chapter = $api->fetchByType('chapter', $chapterId, $site);
            $chapters[] = $chapter;
            $chaptersById[(int)$chapterId] = $chapter;

            $flattened = [];
            $resourceGroups = $chapter['resources'] ?? [];
            foreach ($resourceGroups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                foreach ($group as $resource) {
                    if (!is_array($resource) || !isset($resource['id'])) {
                        continue;
                    }
                    $flattened[(int)$resource['id']] = $resource;
                }
            }

            $resourcesByChapter[$chapterId] = array_values($flattened);
        }

        $persons = [];
        foreach (($book['writers'] ?? []) as $writer) {
            if (is_array($writer) && isset($writer['id'])) {
                $persons[(int)$writer['id']] = $writer;
            }
        }

        $topics = [];
        foreach (($book['topics'] ?? []) as $topic) {
            if (is_array($topic) && isset($topic['id'])) {
                $topics[(int)$topic['id']] = $topic;
            }
        }
        foreach ($chapters as $chapter) {
            foreach (($chapter['topics'] ?? []) as $topic) {
                if (is_array($topic) && isset($topic['id'])) {
                    $topics[(int)$topic['id']] = $topic;
                }
            }
        }

        return [
            'book' => $book,
            'chapters' => $chapters,
            'chaptersById' => $chaptersById,
            'resourcesByChapter' => $resourcesByChapter,
            'persons' => array_values($persons),
            'topics' => array_values($topics),
        ];
    }

    private function upsertEntity(string $remoteType, array $payload, string $sourceSite, ?int $parentEntryId, array &$counts): int
    {
        $remoteId = (int)($payload['id'] ?? 0);
        if ($remoteId < 1) {
            throw new Exception(sprintf('Invalid %s payload id', $remoteType));
        }

        $section = $this->sectionForType($remoteType);
        $map = Plugin::$plugin->get('mapping')->getMap($remoteType, $remoteId, $sourceSite);
        $entry = null;

        if ($map) {
            $entry = Entry::find()->id((int)$map->entryId)->status(null)->site('*')->one();
        }

        if ($entry === null) {
            $entry = $this->findExistingEntryByRemoteId($section, $remoteId);
        }

        $isNew = $entry === null;
        if ($isNew) {
            $entry = new Entry();
            $entryType = Craft::$app->entries->getEntryTypesBySectionId((int)$section->id)[0] ?? null;
            if ($entryType === null) {
                throw new Exception(sprintf('No entry type found for section %s', $section->handle));
            }

            $site = $this->localSite();

            $entry->sectionId = (int)$section->id;
            $entry->typeId = (int)$entryType->id;
            $entry->siteId = (int)$site->id;
            $entry->enabled = true;
            $entry->enabledForSite = true;
        }

        $title = trim((string)($payload['title'] ?? $payload['name'] ?? sprintf('%s #%d', ucfirst($remoteType), $remoteId)));
        $slug = trim((string)($payload['slug'] ?? ''));
        // Keep deterministic slugs to avoid duplicate entries on re-import.
        $slug = StringHelper::toKebabCase($remoteType . '-' . $remoteId);

        $entry->title = $title;
        $entry->slug = $slug;
        $prettyPayloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($prettyPayloadJson)) {
            $prettyPayloadJson = '{}';
        }
        $entry->setFieldValue(
            ContentModelService::PAYLOAD_JSON_FIELD_HANDLE,
            $prettyPayloadJson
        );
        $entry->setFieldValue(
            ContentModelService::REFRESHED_AT_FIELD_HANDLE,
            new DateTime()
        );
        $entry->setFieldValue(ContentModelService::SAPIENCIAL_ID_FIELD_HANDLE, $remoteId);

        if (!Craft::$app->elements->saveElement($entry, true, true, false)) {
            $err = implode(' | ', array_map(static fn(array $e): string => implode(', ', $e), $entry->getErrors()));
            throw new Exception(sprintf('Failed to save %s #%d: %s', $remoteType, $remoteId, $err));
        }

        Plugin::$plugin->get('mapping')->upsertMap($remoteType, $remoteId, $sourceSite, (int)$entry->id, $parentEntryId, $title);

        if ($isNew) {
            $counts['created']++;
        } else {
            $counts['updated']++;
        }

        return (int)$entry->id;
    }

    private function syncRelations(string $site, array $graph, int $bookEntryId, array $chapterEntryIds): void
    {
        $localSite = $this->localSite();
        $book = Entry::find()->id($bookEntryId)->siteId((int)$localSite->id)->status(null)->one();
        if (!$book) {
            return;
        }

        $linkedChapterEntryIds = [];
        $linkedPersonEntryIds = [];
        $topicToBook = [];
        foreach (($graph['book']['topics'] ?? []) as $topic) {
            $topicId = (int)($topic['id'] ?? 0);
            if ($topicId > 0) {
                $topicToBook[$topicId] = true;
            }
        }
        $topicToChapterIds = [];
        $linkedResourceByChapter = [];

        // Chapter -> parent Book and Resource -> parent Chapter
        foreach ($graph['resourcesByChapter'] as $chapterRemoteId => $resources) {
            $chapterEntryId = $chapterEntryIds[(int)$chapterRemoteId] ?? null;
            if (!$chapterEntryId) {
                continue;
            }
            $linkedChapterEntryIds[] = $chapterEntryId;

            $chapter = Entry::find()->id($chapterEntryId)->siteId((int)$localSite->id)->status(null)->one();
            if (!$chapter) {
                continue;
            }

            if ($chapter->getFieldLayout()?->getFieldByHandle(ContentModelService::CHAPTER_PARENT_BOOK_FIELD_HANDLE) !== null) {
                $chapter->setFieldValue(ContentModelService::CHAPTER_PARENT_BOOK_FIELD_HANDLE, [$bookEntryId]);
            }
            $this->saveElementOrFail($chapter, 'chapter parent relation');

            $chapterTopicRemoteIds = [];
            foreach (($graph['chaptersById'][(int)$chapterRemoteId]['topics'] ?? []) as $topic) {
                $topicRemoteId = (int)($topic['id'] ?? 0);
                if ($topicRemoteId > 0) {
                    $chapterTopicRemoteIds[] = $topicRemoteId;
                    $topicToChapterIds[$topicRemoteId][] = $chapterEntryId;
                }
            }
            $chapterTopicRemoteIds = array_values(array_unique($chapterTopicRemoteIds));

            foreach ($resources as $resource) {
                $map = Plugin::$plugin->get('mapping')->getMap('resource', (int)$resource['id'], $site);
                if (!$map) {
                    continue;
                }
                $resourceEntry = Entry::find()->id((int)$map->entryId)->siteId((int)$localSite->id)->status(null)->one();
                if (!$resourceEntry) {
                    continue;
                }
                if ($resourceEntry->getFieldLayout()?->getFieldByHandle(ContentModelService::RESOURCE_PARENT_CHAPTER_FIELD_HANDLE) !== null) {
                    $resourceEntry->setFieldValue(ContentModelService::RESOURCE_PARENT_CHAPTER_FIELD_HANDLE, [$chapterEntryId]);
                }
                $this->saveElementOrFail($resourceEntry, 'resource parent relation');
                $linkedResourceByChapter[$chapterEntryId][] = (int)$resourceEntry->id;
            }
        }

        // Person -> parent Book
        foreach ($graph['persons'] as $person) {
            $map = Plugin::$plugin->get('mapping')->getMap('person', (int)($person['id'] ?? 0), $site);
            if (!$map) {
                continue;
            }
            $personEntry = Entry::find()->id((int)$map->entryId)->siteId((int)$localSite->id)->status(null)->one();
            if (!$personEntry) {
                continue;
            }
            if ($personEntry->getFieldLayout()?->getFieldByHandle(ContentModelService::PERSON_PARENT_BOOK_FIELD_HANDLE) !== null) {
                $personEntry->setFieldValue(ContentModelService::PERSON_PARENT_BOOK_FIELD_HANDLE, [$bookEntryId]);
            }
            $this->saveElementOrFail($personEntry, 'person parent relation');
            $linkedPersonEntryIds[] = (int)$personEntry->id;
        }

        // Topic -> parent Books and parent Chapters
        $linkedBookTopicEntryIds = [];
        $linkedChapterTopicEntryIds = [];
        foreach ($graph['topics'] as $topic) {
            $topicRemoteId = (int)($topic['id'] ?? 0);
            if ($topicRemoteId < 1) {
                continue;
            }
            $map = Plugin::$plugin->get('mapping')->getMap('topic', $topicRemoteId, $site);
            if (!$map) {
                continue;
            }
            $topicEntry = Entry::find()->id((int)$map->entryId)->siteId((int)$localSite->id)->status(null)->one();
            if (!$topicEntry) {
                continue;
            }

            $bookIds = !empty($topicToBook[$topicRemoteId]) ? [$bookEntryId] : [];
            $chapterIds = array_values(array_unique($topicToChapterIds[$topicRemoteId] ?? []));

            if ($topicEntry->getFieldLayout()?->getFieldByHandle(ContentModelService::TOPIC_BOOKS_FIELD_HANDLE) !== null) {
                $topicEntry->setFieldValue(ContentModelService::TOPIC_BOOKS_FIELD_HANDLE, $bookIds);
            }
            if ($topicEntry->getFieldLayout()?->getFieldByHandle(ContentModelService::TOPIC_CHAPTERS_FIELD_HANDLE) !== null) {
                $topicEntry->setFieldValue(ContentModelService::TOPIC_CHAPTERS_FIELD_HANDLE, $chapterIds);
            }
            $this->saveElementOrFail($topicEntry, 'topic parent relations');

            if (!empty($bookIds)) {
                $linkedBookTopicEntryIds[] = (int)$topicEntry->id;
            }
            foreach ($chapterIds as $chapterId) {
                $linkedChapterTopicEntryIds[$chapterId][] = (int)$topicEntry->id;
            }
        }

        // Reverse relations on parents (auto-maintained from child-owned links).
        if ($book->getFieldLayout()?->getFieldByHandle(ContentModelService::BOOK_LINKED_CHAPTERS_FIELD_HANDLE) !== null) {
            $book->setFieldValue(ContentModelService::BOOK_LINKED_CHAPTERS_FIELD_HANDLE, array_values(array_unique($linkedChapterEntryIds)));
        }
        if ($book->getFieldLayout()?->getFieldByHandle(ContentModelService::BOOK_LINKED_PERSONS_FIELD_HANDLE) !== null) {
            $book->setFieldValue(ContentModelService::BOOK_LINKED_PERSONS_FIELD_HANDLE, array_values(array_unique($linkedPersonEntryIds)));
        }
        if ($book->getFieldLayout()?->getFieldByHandle(ContentModelService::BOOK_LINKED_TOPICS_FIELD_HANDLE) !== null) {
            $book->setFieldValue(ContentModelService::BOOK_LINKED_TOPICS_FIELD_HANDLE, array_values(array_unique($linkedBookTopicEntryIds)));
        }
        $this->saveElementOrFail($book, 'book reverse relations');

        foreach ($linkedChapterEntryIds as $chapterEntryId) {
            $chapter = Entry::find()->id((int)$chapterEntryId)->siteId((int)$localSite->id)->status(null)->one();
            if (!$chapter) {
                continue;
            }
            if ($chapter->getFieldLayout()?->getFieldByHandle(ContentModelService::CHAPTER_LINKED_RESOURCES_FIELD_HANDLE) !== null) {
                $chapter->setFieldValue(
                    ContentModelService::CHAPTER_LINKED_RESOURCES_FIELD_HANDLE,
                    array_values(array_unique($linkedResourceByChapter[$chapterEntryId] ?? []))
                );
            }
            if ($chapter->getFieldLayout()?->getFieldByHandle(ContentModelService::CHAPTER_LINKED_TOPICS_FIELD_HANDLE) !== null) {
                $chapter->setFieldValue(
                    ContentModelService::CHAPTER_LINKED_TOPICS_FIELD_HANDLE,
                    array_values(array_unique($linkedChapterTopicEntryIds[$chapterEntryId] ?? []))
                );
            }
            $this->saveElementOrFail($chapter, 'chapter reverse relations');
        }
    }

    private function saveElementOrFail(Entry $entry, string $context): void
    {
        if (Craft::$app->elements->saveElement($entry, false, true, false)) {
            return;
        }

        $errorChunks = [];
        foreach ($entry->getErrors() as $attr => $messages) {
            $errorChunks[] = $attr . ': ' . implode(', ', (array)$messages);
        }
        $errorText = empty($errorChunks) ? 'unknown validation error' : implode(' | ', $errorChunks);
        throw new Exception(sprintf(
            'Failed saving %s for entry #%d (%s): %s',
            $context,
            (int)$entry->id,
            (string)$entry->title,
            $errorText
        ));
    }

    private function deleteMissingDescendants(string $site, int $bookEntryId, array $upserted): int
    {
        $deleted = 0;
        $allowedTypes = ['chapter', 'resource', 'person', 'topic'];

        foreach ($allowedTypes as $type) {
            $keepIds = array_map('intval', array_unique($upserted[$type] ?? []));
            $query = EntityMapRecord::find()
                ->where([
                    'sourceSite' => $site,
                    'remoteType' => $type,
                    'parentEntryId' => $bookEntryId,
                ]);

            foreach ($query->all() as $map) {
                if (in_array((int)$map->remoteId, $keepIds, true)) {
                    continue;
                }

                $entry = Entry::find()->id((int)$map->entryId)->site('*')->status(null)->one();
                if ($entry) {
                    Craft::$app->elements->deleteElement($entry, true);
                    $deleted++;
                }
                $map->delete();
            }
        }

        return $deleted;
    }

    private function sectionForType(string $remoteType): Section
    {
        $settings = Plugin::$plugin->getSettings();
        $handle = match ($remoteType) {
            'book' => $settings->sapiencialBooksSectionHandle,
            'chapter' => $settings->sapiencialChaptersSectionHandle,
            'resource' => $settings->sapiencialResourcesSectionHandle,
            'person' => $settings->sapiencialPersonsSectionHandle,
            'topic' => $settings->sapiencialTopicsSectionHandle,
            default => throw new Exception('Unsupported remote type for section mapping: ' . $remoteType),
        };

        $section = Craft::$app->entries->getSectionByHandle($handle);
        if (!$section) {
            throw new Exception(sprintf('Missing section "%s" for remote type "%s". Create it first.', $handle, $remoteType));
        }

        return $section;
    }

    private function localSite(): Site
    {
        return Craft::$app->getSites()->getPrimarySite();
    }

    private function findExistingEntryByRemoteId(Section $section, int $remoteId): ?Entry
    {
        // Fast path: deterministic slug introduced in sync v2.
        $typeFromSection = match ($section->handle) {
            Plugin::$plugin->getSettings()->sapiencialBooksSectionHandle => 'book',
            Plugin::$plugin->getSettings()->sapiencialChaptersSectionHandle => 'chapter',
            Plugin::$plugin->getSettings()->sapiencialResourcesSectionHandle => 'resource',
            Plugin::$plugin->getSettings()->sapiencialPersonsSectionHandle => 'person',
            Plugin::$plugin->getSettings()->sapiencialTopicsSectionHandle => 'topic',
            default => 'entry',
        };
        $slug = StringHelper::toKebabCase($typeFromSection . '-' . $remoteId);
        $bySlug = Entry::find()
            ->sectionId((int)$section->id)
            ->site('*')
            ->status(null)
            ->slug($slug)
            ->one();
        if ($bySlug) {
            return $bySlug;
        }

        // Backfill path: detect legacy entries by payload JSON id.
        $entries = Entry::find()
            ->sectionId((int)$section->id)
            ->site('*')
            ->status(null)
            ->limit(null)
            ->all();

        foreach ($entries as $candidate) {
            $raw = (string)$candidate->getFieldValue(ContentModelService::PAYLOAD_JSON_FIELD_HANDLE);
            if ($raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded) && (int)($decoded['id'] ?? 0) === $remoteId) {
                return $candidate;
            }
        }

        return null;
    }

    private function logOperation(string $mode, int $remoteBookId, string $site, string $status, array $counts, array $errors, int $durationMs, bool $dryRun): void
    {
        $log = new ImportLogRecord();
        $log->mode = $mode;
        $log->remoteBookId = $remoteBookId;
        $log->sourceSite = $site;
        $log->status = $status;
        $log->countsJson = json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $log->errorsJson = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $log->durationMs = $durationMs;
        $log->dryRun = $dryRun ? 1 : 0;
        $log->createdAt = DateTimeHelper::toDateTime(new DateTime('now', new \DateTimeZone('UTC')));
        $log->save(false);
    }
}
