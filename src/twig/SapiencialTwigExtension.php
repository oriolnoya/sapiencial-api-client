<?php

namespace sapiencial\sapiencialapiclient\twig;

use craft\base\ElementInterface;
use sapiencial\sapiencialapiclient\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SapiencialTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sapiencial_fetch', [$this, 'fetch']),
        ];
    }

    public function fetch(ElementInterface $element, string $fieldHandle, array $options = []): ?array
    {
        $fieldValue = $element->getFieldValue($fieldHandle);
        return Plugin::$plugin->get('fetchService')->fetchForTwig($element, $fieldHandle, $fieldValue, $options);
    }
}
