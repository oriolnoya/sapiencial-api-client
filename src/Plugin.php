<?php

namespace sapiencial\sapiencialapiclient;

use Craft;
use craft\base\Plugin as CraftPlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use sapiencial\sapiencialapiclient\models\Settings;
use sapiencial\sapiencialapiclient\services\ApiClient;
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
        ]);

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['sapiencial-api-client'] = 'sapiencial-api-client/items/index';
                $event->rules['sapiencial-api-client/books'] = 'sapiencial-api-client/items/books';
                $event->rules['sapiencial-api-client/chapters'] = 'sapiencial-api-client/items/chapters';
                $event->rules['sapiencial-api-client/resources'] = 'sapiencial-api-client/items/resources';
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
        ];

        return $item;
    }
}
