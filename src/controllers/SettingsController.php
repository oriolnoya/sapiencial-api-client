<?php

namespace sapiencial\sapiencialapiclient\controllers;

use Craft;
use craft\web\Controller;
use sapiencial\sapiencialapiclient\Plugin;
use Throwable;
use yii\web\Response;

class SettingsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $site = trim((string)$request->getBodyParam('site', ''));
        $effectiveSite = $site !== '' ? $site : Plugin::$plugin->getSettings()->defaultSite;

        $log = [];
        $log[] = sprintf('[%s] Starting API connection test', gmdate('c'));
        $log[] = sprintf('[%s] Using site: %s', gmdate('c'), $effectiveSite !== '' ? $effectiveSite : '(empty)');

        try {
            $startedAt = microtime(true);
            $result = Plugin::$plugin->get('apiClient')->testConnection($site !== '' ? $site : null);
            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

            $items = $result['items'] ?? null;
            $itemCount = is_array($items) ? count($items) : null;

            $log[] = sprintf('[%s] Request completed in %d ms', gmdate('c'), $elapsedMs);
            $log[] = sprintf('[%s] Response keys: %s', gmdate('c'), implode(', ', array_keys($result)));
            if ($itemCount !== null) {
                $log[] = sprintf('[%s] Items returned: %d', gmdate('c'), $itemCount);
            }

            Craft::info(
                sprintf('[sapiencial-api-client] Connection test OK (%d ms, site=%s)', $elapsedMs, $effectiveSite),
                __METHOD__
            );

            return $this->asJson([
                'success' => true,
                'log' => $log,
                'meta' => [
                    'durationMs' => $elapsedMs,
                    'site' => $effectiveSite,
                    'responseKeys' => array_keys($result),
                    'itemCount' => $itemCount,
                ],
            ]);
        } catch (Throwable $e) {
            $log[] = sprintf('[%s] Error: %s', gmdate('c'), $e->getMessage());

            Craft::error('[sapiencial-api-client] Connection test failed: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'log' => $log,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
