<?php

namespace sapiencial\sapiencialapiclient\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
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
        $configuredDefaultSite = (string)(Plugin::$plugin->getSettings()->defaultSite ?? '');
        $site = $value?->site ?: ($configuredDefaultSite !== '' ? $configuredDefaultSite : Craft::$app->getSites()->getCurrentSite()->handle);

        $idInput = Cp::textFieldHtml([
            'id' => Html::id($this->handle . '-remote-id'),
            'class' => 'text fullwidth',
            'name' => $this->handle . '[remoteId]',
            'type' => 'number',
            'min' => 1,
            'placeholder' => 'Sapiencial ' . $this->referenceType() . ' ID',
            'value' => $value?->remoteId ?: '',
        ]);

        $hiddens = Html::hiddenInput($this->handle . '[type]', $this->referenceType());
        $hiddens .= Html::hiddenInput($this->handle . '[site]', $site);
        $hiddens .= Html::hiddenInput($this->handle . '[slug]', $value?->slug ?? '');
        $hiddens .= Html::hiddenInput($this->handle . '[title]', $value?->title ?? '');

        return $idInput . $hiddens;
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
