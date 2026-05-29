<?php

namespace sapiencial\sapiencialapiclient\jobs;

use Craft;
use craft\queue\BaseJob;
use sapiencial\sapiencialapiclient\Plugin;
use Throwable;
use yii\queue\Queue;

class ImportSyncJob extends BaseJob
{
    public string $mode = 'import';
    public int $remoteBookId = 0;
    public string $site = '';
    public bool $dryRun = false;

    public function execute($queue): void
    {
        if ($this->remoteBookId < 1) {
            throw new \RuntimeException('Invalid remoteBookId for import/sync job.');
        }

        if (!in_array($this->mode, ['import', 'sync'], true)) {
            throw new \RuntimeException('Invalid mode for import/sync job.');
        }

        $this->setProgress($queue, 0.05, 'Preparing import/sync.');

        try {
            $service = Plugin::$plugin->get('importSync');
            $progress = function(float $fraction, string $label) use ($queue): void {
                $this->setProgress($queue, max(0.05, min(0.99, $fraction)), $label);
            };
            $result = $this->mode === 'import'
                ? $service->importBook($this->remoteBookId, $this->site, $this->dryRun, $progress)
                : $service->syncBook($this->remoteBookId, $this->site, $this->dryRun, $progress);

            if (($result['success'] ?? false) !== true) {
                $errors = $result['errors'] ?? ['Unknown error'];
                throw new \RuntimeException('Import/sync failed: ' . implode(' | ', $errors));
            }
        } catch (Throwable $e) {
            Craft::error('[sapiencial-api-client] Job failed: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }

        $this->setProgress($queue, 1, 'Completed import/sync.');
    }

    protected function defaultDescription(): ?string
    {
        return sprintf(
            'Sapiencial %s book #%d (%s)',
            $this->mode,
            $this->remoteBookId,
            $this->dryRun ? 'dry-run' : 'apply'
        );
    }
}
