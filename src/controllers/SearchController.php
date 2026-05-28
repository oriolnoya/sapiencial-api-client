<?php

namespace sapiencial\sapiencialapiclient\controllers;

use Craft;
use craft\web\Controller;
use sapiencial\sapiencialapiclient\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SearchController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionSearch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $type = (string)$request->getBodyParam('type');
        $q = trim((string)$request->getBodyParam('q', ''));
        $site = (string)$request->getBodyParam('site', '');
        $limit = max(1, min(500, (int)$request->getBodyParam('limit', 200)));

        if (!in_array($type, ['book', 'chapter', 'resource'], true)) {
            throw new BadRequestHttpException('Invalid type');
        }

        try {
            $result = Plugin::$plugin->get('apiClient')->search($type, $q, $site, $limit, 1);
        } catch (Throwable $e) {
            Craft::error('[sapiencial-api-client] Search failed: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getResponse()->setStatusCode(400);
            return $this->asJson([
                'items' => [],
                'error' => $e->getMessage(),
            ]);
        }

        return $this->asJson([
            'items' => $result['items'] ?? [],
        ]);
    }
}
