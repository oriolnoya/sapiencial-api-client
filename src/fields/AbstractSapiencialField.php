<?php

namespace sapiencial\sapiencialapiclient\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use sapiencial\sapiencialapiclient\models\RemoteReference;
use sapiencial\sapiencialapiclient\Plugin;

abstract class AbstractSapiencialField extends Field
{
    public static function icon(): string
    {
        return 'book';
    }

    abstract protected function referenceType(): string;

    public static function dbType(): array|string|null
    {
        return 'text';
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof RemoteReference) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return null;
        }

        $model = new RemoteReference($value);
        if ($model->type === '') {
            $model->type = $this->referenceType();
        }

        return $model->validate() ? $model : null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof RemoteReference) {
            return json_encode($value->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return null;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        $id = Html::id($this->handle);
        $namespacedId = Craft::$app->view->namespaceInputId($id);

        $html = Cp::editableTextFieldHtml([
            'id' => $id . '-search',
            'class' => 'text fullwidth',
            'name' => null,
            'placeholder' => 'Busca ' . $this->referenceType() . '...',
        ]);

        $hidden = Html::hiddenInput($this->handle, $value ? json_encode($value->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        $preview = '<div id="' . $namespacedId . '-preview" class="meta" style="margin-top:8px;">' . ($value ? Html::encode($value->title . ' (#' . $value->remoteId . ')') : 'Sense selecció') . '</div>';
        $list = '<div id="' . $namespacedId . '-results" class="zilch" style="margin-top:8px;"></div>';

        $actionUrl = UrlHelper::actionUrl('sapiencial-api-client/search/search');
        $payload = [
            'fieldId' => $namespacedId,
            'hiddenInputSelector' => '#' . $namespacedId,
            'searchInputSelector' => '#' . $namespacedId . '-search',
            'resultsSelector' => '#' . $namespacedId . '-results',
            'previewSelector' => '#' . $namespacedId . '-preview',
            'type' => $this->referenceType(),
            'actionUrl' => $actionUrl,
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => Craft::$app->getRequest()->getCsrfToken(),
            'site' => Craft::$app->getSites()->getCurrentSite()->handle,
        ];

        Craft::$app->view->registerJs('window.SapiencialField && window.SapiencialField.init(' . json_encode($payload) . ');');
        Craft::$app->view->registerAssetBundle(\sapiencial\sapiencialapiclient\assets\FieldAssetBundle::class);

        return $hidden . $html . $preview . $list;
    }

    public function getElementValidationRules(): array
    {
        return [[$this, 'validateReference']];
    }

    public function validateReference(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);
        if (!$value instanceof RemoteReference) {
            return;
        }

        if ($value->type !== $this->referenceType()) {
            $element->addError($this->handle, 'Tipus de referència invàlid.');
            return;
        }

        try {
            Plugin::$plugin->get('apiClient')->fetchByType($value->type, $value->remoteId, $value->site);
        } catch (\Throwable) {
            $element->addError($this->handle, 'No s\'ha pogut validar la referència remota.');
        }
    }
}
