<?php

namespace sapiencial\sapiencialapiclient\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use sapiencial\sapiencialapiclient\Plugin;
use sapiencial\sapiencialapiclient\services\ContentModelService;
use sapiencial\sapiencialapiclient\records\EntityMapRecord;
use yii\web\Response;

class ItemsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        return $this->redirect('sapiencial-api-client/books');
    }

    public function actionBooks(): Response
    {
        $q = trim((string)Craft::$app->getRequest()->getQueryParam('q', ''));
        $site = (string)Plugin::$plugin->getSettings()->defaultSite;

        $remoteItems = Plugin::$plugin->get('remoteCatalog')->listRemoteBooks($q, $site, 200);

        $importedIds = [];
        foreach (EntityMapRecord::find()->where(['remoteType' => 'book', 'sourceSite' => $site])->all() as $row) {
            $importedIds[(int)$row->remoteId] = (int)$row->entryId;
        }

        $bookRows = [];
        foreach ($remoteItems as $item) {
            $remoteId = (int)($item['id'] ?? 0);
            if ($remoteId < 1) {
                continue;
            }

            $row = [
                'title' => (string)($item['title'] ?? '—'),
                'id' => $remoteId,
                'status' => 'not imported',
                'lastRefreshed' => null,
                'isImported' => false,
            ];

            $entryId = $importedIds[$remoteId] ?? null;
            if ($entryId) {
                $row['isImported'] = true;
                $entry = Entry::find()->id((int)$entryId)->site('*')->status(null)->one();
                if ($entry) {
                    $refreshedAt = $entry->getFieldValue(ContentModelService::REFRESHED_AT_FIELD_HANDLE);
                    $row['lastRefreshed'] = $refreshedAt;

                    $localPayload = (string)($entry->getFieldValue(ContentModelService::PAYLOAD_JSON_FIELD_HANDLE) ?? '');
                    if ($localPayload === '') {
                        $row['status'] = 'needs to sync';
                    } else {
                        try {
                            $remoteFull = Plugin::$plugin->get('apiClient')->fetchByType('book', $remoteId, $site);
                            $localHash = hash('sha256', $localPayload);
                            $remoteHash = hash('sha256', json_encode($remoteFull, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                            $row['status'] = $localHash === $remoteHash ? 'synced' : 'needs to sync';
                        } catch (\Throwable) {
                            $row['status'] = 'needs to sync';
                        }
                    }
                } else {
                    $row['status'] = 'needs to sync';
                }
            }

            $bookRows[] = $row;
        }

        return $this->renderTemplate('sapiencial-api-client/items/index', [
            'selectedTab' => 'books',
            'searchQuery' => $q,
            'bookRows' => $bookRows,
            'importedIds' => $importedIds,
            'rows' => [],
        ]);
    }

    public function actionChapters(): Response
    {
        return $this->renderImportedType('chapter');
    }

    public function actionResources(): Response
    {
        return $this->renderImportedType('resource');
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();
        $remoteBookId = (int)Craft::$app->getRequest()->getRequiredBodyParam('remoteBookId');
        $site = (string)Craft::$app->getRequest()->getBodyParam('site', Plugin::$plugin->getSettings()->defaultSite);
        $dryRun = (bool)Craft::$app->getRequest()->getBodyParam('dryRun', Plugin::$plugin->getSettings()->enableDryRunByDefault);

        $result = Plugin::$plugin->get('importSync')->importBook($remoteBookId, $site, $dryRun);
        $this->setNoticeOrError($result);

        return $this->redirect('sapiencial-api-client/books');
    }

    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $remoteBookId = (int)Craft::$app->getRequest()->getRequiredBodyParam('remoteBookId');
        $site = (string)Craft::$app->getRequest()->getBodyParam('site', Plugin::$plugin->getSettings()->defaultSite);
        $dryRun = (bool)Craft::$app->getRequest()->getBodyParam('dryRun', Plugin::$plugin->getSettings()->enableDryRunByDefault);

        $result = Plugin::$plugin->get('importSync')->syncBook($remoteBookId, $site, $dryRun);
        $this->setNoticeOrError($result);

        return $this->redirect('sapiencial-api-client/books');
    }

    private function renderImportedType(string $remoteType): Response
    {
        $q = trim((string)Craft::$app->getRequest()->getQueryParam('q', ''));
        $site = (string)Plugin::$plugin->getSettings()->defaultSite;

        $maps = EntityMapRecord::find()
            ->where(['remoteType' => $remoteType, 'sourceSite' => $site])
            ->orderBy(['titleSnapshot' => SORT_ASC])
            ->all();

        $rows = [];
        foreach ($maps as $map) {
            $title = (string)$map->titleSnapshot;
            if ($q !== '' && mb_stripos($title, $q) === false && mb_stripos((string)$map->remoteId, $q) === false) {
                continue;
            }

            $entry = Entry::find()->id((int)$map->entryId)->site($site)->status(null)->one();
            if (!$entry) {
                continue;
            }

            $rows[] = [
                'title' => $entry->title,
                'id' => (int)$map->remoteId,
            ];
        }

        return $this->renderTemplate('sapiencial-api-client/items/index', [
            'selectedTab' => $remoteType === 'chapter' ? 'chapters' : 'resources',
            'searchQuery' => $q,
            'rows' => $rows,
            'remoteItems' => [],
            'importedIds' => [],
        ]);
    }

    private function setNoticeOrError(array $result): void
    {
        if (($result['success'] ?? false) === true) {
            $counts = $result['counts'] ?? [];
            Craft::$app->getSession()->setNotice(sprintf(
                'Completed (%s). Created: %d, Updated: %d, Deleted: %d, Dry-run: %s',
                (string)($result['mode'] ?? 'sync'),
                (int)($counts['created'] ?? 0),
                (int)($counts['updated'] ?? 0),
                (int)($counts['deleted'] ?? 0),
                ($result['dryRun'] ?? false) ? 'yes' : 'no'
            ));
            return;
        }

        $errors = $result['errors'] ?? ['Unknown error'];
        Craft::$app->getSession()->setError('Sync failed: ' . implode(' | ', $errors));
    }
}
