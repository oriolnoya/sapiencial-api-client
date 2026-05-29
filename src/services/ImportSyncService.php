<?php

namespace sapiencial\sapiencialapiclient\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\models\Section;
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

        $started = microtime(true);
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
        $errors = [];

        try {
            $graph = $this->fetchBookGraph($remoteBookId, $site);
            $upserted = [];

            if (!$dryRun) {
                $bookEntryId = $this->upsertEntity('book', $graph['book'], $site, null, $counts);
                $upserted['book'][] = $graph['book']['id'];

                $chapterEntryIds = [];
                foreach ($graph['chapters'] as $chapter) {
                    $chapterEntryId = $this->upsertEntity('chapter', $chapter, $site, $bookEntryId, $counts);
                    $chapterEntryIds[$chapter['id']] = $chapterEntryId;
                    $upserted['chapter'][] = $chapter['id'];
                }

                foreach ($graph['resourcesByChapter'] as $chapterRemoteId => $resources) {
                    $parentChapterEntryId = $chapterEntryIds[(int)$chapterRemoteId] ?? null;
                    foreach ($resources as $resource) {
                        $this->upsertEntity('resource', $resource, $site, $parentChapterEntryId, $counts);
                        $upserted['resource'][] = $resource['id'];
                    }
                }

                foreach ($graph['persons'] as $person) {
                    $this->upsertEntity('person', $person, $site, $bookEntryId, $counts);
                    $upserted['person'][] = $person['id'];
                }

                $this->syncRelations($site, $graph, $bookEntryId, $chapterEntryIds);
                $counts['deleted'] += $this->deleteMissingDescendants($site, $bookEntryId, $upserted);
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $status = empty($errors) ? 'ok' : 'error';
        $this->logOperation($mode, $remoteBookId, $site, $status, $counts, $errors, $durationMs, $dryRun);

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
        $resourcesByChapter = [];
        $chapterIds = array_map(static fn(array $c): int => (int)($c['id'] ?? 0), $book['chapters'] ?? []);

        foreach (array_filter($chapterIds) as $chapterId) {
            $chapter = $api->fetchByType('chapter', $chapterId, $site);
            $chapters[] = $chapter;

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

        return [
            'book' => $book,
            'chapters' => $chapters,
            'resourcesByChapter' => $resourcesByChapter,
            'persons' => array_values($persons),
        ];
    }

    private function upsertEntity(string $remoteType, array $payload, string $sourceSite, ?int $parentEntryId, array &$counts): int
    {
        $remoteId = (int)($payload['id'] ?? 0);
        if ($remoteId < 1) {
            throw new Exception(sprintf('Invalid %s payload id', $remoteType));
        }

        $map = Plugin::$plugin->get('mapping')->getMap($remoteType, $remoteId, $sourceSite);
        $entry = null;

        if ($map) {
            $entry = Entry::find()->id((int)$map->entryId)->status(null)->site($sourceSite)->one();
        }

        $isNew = $entry === null;
        if ($isNew) {
            $entry = new Entry();
            $section = $this->sectionForType($remoteType);
            $entryType = Craft::$app->entries->getEntryTypesBySectionId((int)$section->id)[0] ?? null;
            if ($entryType === null) {
                throw new Exception(sprintf('No entry type found for section %s', $section->handle));
            }

            $site = Craft::$app->sites->getSiteByHandle($sourceSite);
            if ($site === null) {
                throw new Exception('Site handle not found: ' . $sourceSite);
            }

            $entry->sectionId = (int)$section->id;
            $entry->typeId = (int)$entryType->id;
            $entry->siteId = (int)$site->id;
            $entry->enabled = true;
            $entry->enabledForSite = true;
        }

        $title = trim((string)($payload['title'] ?? $payload['name'] ?? sprintf('%s #%d', ucfirst($remoteType), $remoteId)));
        $slug = trim((string)($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = StringHelper::toKebabCase($title . '-' . $remoteType . '-' . $remoteId);
        }

        $entry->title = $title;
        $entry->slug = $slug;
        $entry->setFieldValue(
            ContentModelService::PAYLOAD_JSON_FIELD_HANDLE,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $entry->setFieldValue(
            ContentModelService::REFRESHED_AT_FIELD_HANDLE,
            new DateTime()
        );

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
        $book = Entry::find()->id($bookEntryId)->site($site)->status(null)->one();
        if (!$book) {
            return;
        }

        $chapterIds = array_values($chapterEntryIds);
        if ($book->getFieldLayout()?->getFieldByHandle('sapiencialChapters') !== null) {
            $book->setFieldValue('sapiencialChapters', $chapterIds);
        }

        $personEntryIds = [];
        foreach ($graph['persons'] as $person) {
            $map = Plugin::$plugin->get('mapping')->getMap('person', (int)$person['id'], $site);
            if ($map) {
                $personEntryIds[] = (int)$map->entryId;
            }
        }
        if ($book->getFieldLayout()?->getFieldByHandle('sapiencialPersons') !== null) {
            $book->setFieldValue('sapiencialPersons', $personEntryIds);
        }

        Craft::$app->elements->saveElement($book, false, false, false);

        foreach ($graph['resourcesByChapter'] as $chapterRemoteId => $resources) {
            $chapterEntryId = $chapterEntryIds[(int)$chapterRemoteId] ?? null;
            if (!$chapterEntryId) {
                continue;
            }

            $chapter = Entry::find()->id($chapterEntryId)->site($site)->status(null)->one();
            if (!$chapter) {
                continue;
            }

            $resourceEntryIds = [];
            foreach ($resources as $resource) {
                $map = Plugin::$plugin->get('mapping')->getMap('resource', (int)$resource['id'], $site);
                if ($map) {
                    $resourceEntryIds[] = (int)$map->entryId;
                }
            }

            if ($chapter->getFieldLayout()?->getFieldByHandle('sapiencialResources') !== null) {
                $chapter->setFieldValue('sapiencialResources', $resourceEntryIds);
            }

            Craft::$app->elements->saveElement($chapter, false, false, false);
        }
    }

    private function deleteMissingDescendants(string $site, int $bookEntryId, array $upserted): int
    {
        $deleted = 0;
        $allowedTypes = ['chapter', 'resource', 'person'];

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

                $entry = Entry::find()->id((int)$map->entryId)->site($site)->status(null)->one();
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
            default => throw new Exception('Unsupported remote type for section mapping: ' . $remoteType),
        };

        $section = Craft::$app->entries->getSectionByHandle($handle);
        if (!$section) {
            throw new Exception(sprintf('Missing section "%s" for remote type "%s". Create it first.', $handle, $remoteType));
        }

        return $section;
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
