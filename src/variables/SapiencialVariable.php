<?php

namespace sapiencial\sapiencialapiclient\variables;

use craft\base\ElementInterface;
use sapiencial\sapiencialapiclient\Plugin;

class SapiencialVariable
{
    public function fetch(ElementInterface $element, string $fieldHandle, array $options = []): ?array
    {
        $fieldValue = $element->getFieldValue($fieldHandle);
        return Plugin::$plugin->get('fetchService')->fetchForTwig($element, $fieldHandle, $fieldValue, $options);
    }
}
