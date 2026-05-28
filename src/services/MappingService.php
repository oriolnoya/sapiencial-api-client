<?php

namespace sapiencial\sapiencialapiclient\services;

use craft\base\Component;
use sapiencial\sapiencialapiclient\records\EntityMapRecord;

class MappingService extends Component
{
    public function getMap(string $remoteType, int $remoteId, string $sourceSite): ?EntityMapRecord
    {
        return EntityMapRecord::findOne([
            'remoteType' => $remoteType,
            'remoteId' => $remoteId,
            'sourceSite' => $sourceSite,
        ]);
    }

    public function upsertMap(string $remoteType, int $remoteId, string $sourceSite, int $entryId, ?int $parentEntryId = null, ?string $titleSnapshot = null): EntityMapRecord
    {
        $record = $this->getMap($remoteType, $remoteId, $sourceSite) ?? new EntityMapRecord();
        $record->remoteType = $remoteType;
        $record->remoteId = $remoteId;
        $record->sourceSite = $sourceSite;
        $record->entryId = $entryId;
        $record->parentEntryId = $parentEntryId;
        $record->titleSnapshot = $titleSnapshot;
        $record->save(false);

        return $record;
    }

    public function listImportedByType(string $remoteType, string $query = ''): array
    {
        $q = EntityMapRecord::find()->where(['remoteType' => $remoteType]);
        if ($query !== '') {
            $q->andWhere(['like', 'titleSnapshot', $query]);
        }

        return $q->orderBy(['titleSnapshot' => SORT_ASC])->all();
    }
}
