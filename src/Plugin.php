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
  const enhancePayloadJson = () => {
    document.querySelectorAll('textarea[name^="fields[sapiencialPayloadJson]"]').forEach((textarea) => {
      if (!(textarea instanceof HTMLTextAreaElement)) return;
      const container = textarea.closest('.input');
      if (!container) return;
      if (container.querySelector('.sapiencial-json-viewer')) return;

      let data;
      try {
        data = JSON.parse(textarea.value || '{}');
      } catch (_e) {
        return;
      }

      const wrap = document.createElement('div');
      wrap.className = 'sapiencial-json-wrap';

      const toolbar = document.createElement('div');
      toolbar.className = 'sapiencial-json-toolbar';

      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'btn small';
      toggleBtn.textContent = 'Show raw JSON';

      const viewer = document.createElement('div');
      viewer.className = 'sapiencial-json-viewer';

      const buildNode = (value, key = null) => {
        const valueType = Array.isArray(value) ? 'array' : (value === null ? 'null' : typeof value);
        const row = document.createElement('div');
        row.className = 'sapiencial-json-row';

        if (valueType === 'object' || valueType === 'array') {
          const details = document.createElement('details');
          details.open = key === null;
          const summary = document.createElement('summary');
          const meta = valueType === 'array'
            ? `Array(${value.length})`
            : `Object(${Object.keys(value).length})`;
          summary.innerHTML = `${key !== null ? `<span class="k">${key}</span>: ` : ''}<span class="t">${meta}</span>`;
          details.appendChild(summary);

          const body = document.createElement('div');
          body.className = 'sapiencial-json-children';
          const entries = valueType === 'array'
            ? value.map((v, i) => [String(i), v])
            : Object.entries(value);
          entries.forEach(([k, v]) => body.appendChild(buildNode(v, k)));
          details.appendChild(body);
          row.appendChild(details);
          return row;
        }

        const rendered = value === null ? 'null' : String(value);
        row.innerHTML = `${key !== null ? `<span class="k">${key}</span>: ` : ''}<span class="v ${valueType}">${rendered}</span>`;
        return row;
      };

      viewer.appendChild(buildNode(data));
      toolbar.appendChild(toggleBtn);
      wrap.appendChild(toolbar);
      wrap.appendChild(viewer);

      textarea.style.display = 'none';
      container.appendChild(wrap);

      toggleBtn.addEventListener('click', () => {
        const isHidden = textarea.style.display === 'none';
        textarea.style.display = isHidden ? '' : 'none';
        viewer.style.display = isHidden ? 'none' : '';
        toggleBtn.textContent = isHidden ? 'Show interactive JSON' : 'Show raw JSON';
      });
    });
  };
  lockSapiencialId();
  enhancePayloadJson();
  const observer = new MutationObserver(lockSapiencialId);
  const observer2 = new MutationObserver(enhancePayloadJson);
  observer.observe(document.body, {childList: true, subtree: true});
  observer2.observe(document.body, {childList: true, subtree: true});
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
.sapiencial-json-wrap {
  border: 1px solid #d5dce5;
  border-radius: 6px;
  background: #f8fafc;
  overflow: hidden;
}
.sapiencial-json-toolbar {
  display: flex;
  justify-content: flex-end;
  padding: 8px;
  border-bottom: 1px solid #e5ebf3;
  background: #eef3f9;
}
.sapiencial-json-viewer {
  max-height: 420px;
  overflow: auto;
  padding: 10px 12px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 12px;
  line-height: 1.45;
}
.sapiencial-json-row {
  margin-left: 2px;
  padding: 2px 0;
}
.sapiencial-json-row .k {
  color: #2b4f81;
  font-weight: 600;
}
.sapiencial-json-row .t {
  color: #4b5c70;
}
.sapiencial-json-row .v.string { color: #0b6b2f; }
.sapiencial-json-row .v.number { color: #7a4f01; }
.sapiencial-json-row .v.boolean { color: #6f2dbd; }
.sapiencial-json-row .v.null { color: #6b7280; }
.sapiencial-json-children {
  border-left: 1px dashed #d4dde8;
  margin-left: 8px;
  padding-left: 10px;
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
