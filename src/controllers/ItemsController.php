<?php

namespace sapiencial\sapiencialapiclient\controllers;

use Craft;
use craft\web\Controller;
use sapiencial\sapiencialapiclient\Plugin;
use Throwable;
use yii\web\Response;

class ItemsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        return $this->renderTab('books');
    }

    public function actionBooks(): Response
    {
        return $this->renderTab('books');
    }

    public function actionChapters(): Response
    {
        return $this->renderTab('chapters');
    }

    public function actionResources(): Response
    {
        return $this->renderTab('resources');
    }

    private function renderTab(string $tab): Response
    {
        $q = trim((string)Craft::$app->getRequest()->getQueryParam('q', ''));
        $site = (string)(Plugin::$plugin->getSettings()->defaultSite ?? '');

        $error = null;
        $items = [];
        try {
            $result = Plugin::$plugin->get('apiClient')->search(
                rtrim($tab, 's'),
                $q,
                $site,
                500,
                1
            );
            $items = $result['items'] ?? [];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Craft::error('[sapiencial-api-client] Items list failed: ' . $e->getMessage(), __METHOD__);
        }

        return $this->renderTemplate('sapiencial-api-client/items/index', [
            'selectedTab' => $tab,
            'searchQuery' => $q,
            'items' => $items,
            'errorMessage' => $error,
        ]);
    }
}
