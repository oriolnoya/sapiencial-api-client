<?php

namespace sapiencial\sapiencialapiclient;

use Craft;
use craft\base\Plugin as CraftPlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use sapiencial\sapiencialapiclient\fields\SapiencialBookField;
use sapiencial\sapiencialapiclient\fields\SapiencialChapterField;
use sapiencial\sapiencialapiclient\fields\SapiencialResourceField;
use sapiencial\sapiencialapiclient\models\Settings;
use sapiencial\sapiencialapiclient\services\ApiClient;
use sapiencial\sapiencialapiclient\services\FetchService;
use sapiencial\sapiencialapiclient\twig\SapiencialTwigExtension;
use sapiencial\sapiencialapiclient\variables\SapiencialVariable;
use yii\base\Event;

class Plugin extends CraftPlugin
{
    public static Plugin $plugin;
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'apiClient' => ApiClient::class,
            'fetchService' => FetchService::class,
        ]);

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = SapiencialBookField::class;
                $event->types[] = SapiencialChapterField::class;
                $event->types[] = SapiencialResourceField::class;
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('sapiencial', SapiencialVariable::class);
                Craft::$app->view->registerTwigExtension(new SapiencialTwigExtension());
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['sapiencial-api-client'] = 'sapiencial-api-client/items/index';
                $event->rules['sapiencial-api-client/books'] = 'sapiencial-api-client/items/books';
                $event->rules['sapiencial-api-client/chapters'] = 'sapiencial-api-client/items/chapters';
                $event->rules['sapiencial-api-client/resources'] = 'sapiencial-api-client/items/resources';
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
}
