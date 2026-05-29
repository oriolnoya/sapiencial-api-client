<?php

namespace sapiencial\sapiencialapiclient;

use Craft;
use craft\base\Plugin as CraftPlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\View;
use sapiencial\sapiencialapiclient\models\Settings;
use sapiencial\sapiencialapiclient\services\ApiClient;
use sapiencial\sapiencialapiclient\services\ContentModelService;
use sapiencial\sapiencialapiclient\services\ImportSyncService;
use sapiencial\sapiencialapiclient\services\MappingService;
use sapiencial\sapiencialapiclient\services\RemoteCatalogService;
use yii\base\Event;

class Plugin extends CraftPlugin
{
    public static Plugin $plugin;

    public string $schemaVersion = '2.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'apiClient' => ApiClient::class,
            'remoteCatalog' => RemoteCatalogService::class,
            'mapping' => MappingService::class,
            'importSync' => ImportSyncService::class,
            'contentModel' => ContentModelService::class,
        ]);

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            try {
                /** @var ContentModelService $contentModel */
                $contentModel = $this->get('contentModel');
                $contentModel->ensureContentModel();
            } catch (\Throwable $e) {
                Craft::warning('[sapiencial-api-client] Unable to auto-bootstrap content model: ' . $e->getMessage(), __METHOD__);
            }

            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
                static function(): void {
                    Craft::$app->getView()->registerJs(<<<'JS'
(() => {
  const lockSapiencialId = () => {
    document.querySelectorAll('input[name^="fields[sapiencialId]"]').forEach((input) => {
      if (!(input instanceof HTMLInputElement)) return;
      input.setAttribute('step', '1');
      input.setAttribute('inputmode', 'numeric');
      input.setAttribute('readonly', 'readonly');
      input.type = 'hidden';

      const container = input.closest('.input');
      if (!container) return;

      let badge = container.querySelector('.sapiencial-id-badge');
      if (!badge) {
        badge = document.createElement('div');
        badge.className = 'sapiencial-id-badge';
        container.appendChild(badge);
      }

      const raw = (input.value || '').trim();
      badge.textContent = raw !== '' ? raw : '—';
      badge.setAttribute('title', 'Managed by Sapiencial sync.');
    });
  };
  lockSapiencialId();
  const observer = new MutationObserver(lockSapiencialId);
  observer.observe(document.body, {childList: true, subtree: true});
})();
JS);
                    Craft::$app->getView()->registerCss(<<<'CSS'
.sapiencial-id-badge {
  display: inline-flex;
  align-items: center;
  min-height: 34px;
  padding: 0 12px;
  border: 1px solid #d5dce5;
  border-radius: 6px;
  background: #f4f7fb;
  color: #3d4b5c;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.01em;
}
CSS);
                }
            );
        }

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['sapiencial-api-client'] = 'sapiencial-api-client/items/index';
                $event->rules['sapiencial-api-client/books'] = 'sapiencial-api-client/items/books';
                $event->rules['sapiencial-api-client/chapters'] = 'sapiencial-api-client/items/chapters';
                $event->rules['sapiencial-api-client/resources'] = 'sapiencial-api-client/items/resources';
                $event->rules['sapiencial-api-client/operations'] = 'sapiencial-api-client/items/operations';
                $event->rules['sapiencial-api-client/import'] = 'sapiencial-api-client/items/import';
                $event->rules['sapiencial-api-client/sync'] = 'sapiencial-api-client/items/sync';
            }
        );
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('sapiencial-api-client/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }

        $item['subnav'] = [
            'books' => ['label' => 'Llibres', 'url' => 'sapiencial-api-client/books'],
            'chapters' => ['label' => 'Capítols', 'url' => 'sapiencial-api-client/chapters'],
            'resources' => ['label' => 'Recursos', 'url' => 'sapiencial-api-client/resources'],
            'operations' => ['label' => 'Operations', 'url' => 'sapiencial-api-client/operations'],
        ];

        return $item;
    }
}
