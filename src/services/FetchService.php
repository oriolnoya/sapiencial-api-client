<?php

namespace sapiencial\sapiencialapiclient\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use DateTime;
use sapiencial\sapiencialapiclient\jobs\RefreshFetchJob;
use sapiencial\sapiencialapiclient\records\FetchCacheRecord;

class FetchService extends Component
{
    public function fetchForTwig(ElementInterface $element, string $fieldHandle, mixed $fieldValue, array $options = []): ?array
    {
        if (!$fieldValue || !is_object($fieldValue) || !property_exists($fieldValue, 'remoteId') || !property_exists($fieldValue, 'type')) {
            return null;
        }

        $entryId = (int)$element->id;
        $siteId = (int)$element->siteId;
        $remoteType = (string)$fieldValue->type;
        $remoteId = (int)$fieldValue->remoteId;
        $remoteSite = (string)($fieldValue->site ?: (Craft::$app->getSites()->getSiteById($siteId)?->handle ?? ''));

        $record = FetchCacheRecord::findOne([
            'entryId' => $entryId,
            'siteId' => $siteId,
            'fieldHandle' => $fieldHandle,
            'remoteType' => $remoteType,
            'remoteId' => $remoteId,
        ]);

        if ($record === null) {
            $payload = $this->api()->fetchByType($remoteType, $remoteId, $remoteSite);
            $record = $this->storeCache(
                $entryId,
                $siteId,
                $fieldHandle,
                $remoteType,
                $remoteId,
                $payload,
                null
            );
            $this->mirrorIntoEntry($entryId, $siteId, $payload, $options);

            return $payload;
        }

        $this->enqueueRefresh($entryId, $siteId, $fieldHandle, $remoteType, $remoteId, $remoteSite, $options);

        return json_decode((string)$record->payloadJson, true);
    }

    public function refreshCache(
        int $entryId,
        int $siteId,
        string $fieldHandle,
        string $remoteType,
        int $remoteId,
        ?string $remoteSite,
        ?string $mirrorJsonFieldHandle,
        ?string $mirrorUpdatedAtFieldHandle,
    ): void {
        $record = FetchCacheRecord::findOne([
            'entryId' => $entryId,
            'siteId' => $siteId,
            'fieldHandle' => $fieldHandle,
            'remoteType' => $remoteType,
            'remoteId' => $remoteId,
        ]);

        $payload = $this->api()->fetchByType($remoteType, $remoteId, $remoteSite);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($record === null || $record->payloadHash !== $hash) {
            $record = $this->storeCache($entryId, $siteId, $fieldHandle, $remoteType, $remoteId, $payload, $record);
            $this->mirrorIntoEntry($entryId, $siteId, $payload, [
                'mirrorJsonFieldHandle' => $mirrorJsonFieldHandle,
                'mirrorUpdatedAtFieldHandle' => $mirrorUpdatedAtFieldHandle,
            ]);
            Craft::info("[sapiencial-api-client] Cache updated for {$remoteType}#{$remoteId}", __METHOD__);
            return;
        }

        $record->lastCheckedAt = DateTimeHelper::currentUTCDateTime();
        $record->status = 'ok';
        $record->error = null;
        $record->save(false);
    }

    private function enqueueRefresh(int $entryId, int $siteId, string $fieldHandle, string $remoteType, int $remoteId, ?string $remoteSite, array $options): void
    {
        Craft::$app->getQueue()->push(new RefreshFetchJob([
            'entryId' => $entryId,
            'siteId' => $siteId,
            'fieldHandle' => $fieldHandle,
            'remoteType' => $remoteType,
            'remoteId' => $remoteId,
            'remoteSite' => $remoteSite,
            'mirrorJsonFieldHandle' => $options['mirrorJsonFieldHandle'] ?? null,
            'mirrorUpdatedAtFieldHandle' => $options['mirrorUpdatedAtFieldHandle'] ?? null,
        ]));
    }

    private function storeCache(int $entryId, int $siteId, string $fieldHandle, string $remoteType, int $remoteId, array $payload, ?FetchCacheRecord $record): FetchCacheRecord
    {
        $record ??= new FetchCacheRecord();
        $record->entryId = $entryId;
        $record->siteId = $siteId;
        $record->fieldHandle = $fieldHandle;
        $record->remoteType = $remoteType;
        $record->remoteId = $remoteId;
        $record->payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $record->payloadHash = hash('sha256', (string)$record->payloadJson);
        $record->fetchedAt = DateTimeHelper::currentUTCDateTime();
        $record->lastCheckedAt = DateTimeHelper::currentUTCDateTime();
        $record->status = 'ok';
        $record->error = null;
        $record->save(false);

        return $record;
    }

    private function mirrorIntoEntry(int $entryId, int $siteId, array $payload, array $options): void
    {
        $jsonField = $options['mirrorJsonFieldHandle'] ?? null;
        $updatedField = $options['mirrorUpdatedAtFieldHandle'] ?? null;
        if (!$jsonField && !$updatedField) {
            return;
        }

        $entry = Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
        if (!$entry) {
            return;
        }

        if ($jsonField) {
            $entry->setFieldValue($jsonField, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($updatedField) {
            $entry->setFieldValue($updatedField, new DateTime());
        }

        Craft::$app->elements->saveElement($entry, false, false, false);
    }

    private function api(): ApiClient
    {
        return Plugin::$plugin->get('apiClient');
    }
}
