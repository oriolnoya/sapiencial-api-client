<?php

namespace sapiencial\sapiencialapiclient\jobs;

use craft\queue\BaseJob;
use sapiencial\sapiencialapiclient\Plugin;

class RefreshFetchJob extends BaseJob
{
    public int $entryId;
    public int $siteId;
    public string $fieldHandle;
    public string $remoteType;
    public int $remoteId;
    public ?string $remoteSite = null;
    public ?string $mirrorJsonFieldHandle = null;
    public ?string $mirrorUpdatedAtFieldHandle = null;

    public function execute($queue): void
    {
        Plugin::$plugin->get('fetchService')->refreshCache(
            $this->entryId,
            $this->siteId,
            $this->fieldHandle,
            $this->remoteType,
            $this->remoteId,
            $this->remoteSite,
            $this->mirrorJsonFieldHandle,
            $this->mirrorUpdatedAtFieldHandle,
        );
    }

    protected function defaultDescription(): ?string
    {
        return 'Refreshing Sapiencial API cache';
    }

    public function getTtr(): int
    {
        return 120;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }
}
