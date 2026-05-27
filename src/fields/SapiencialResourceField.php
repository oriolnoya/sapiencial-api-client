<?php

namespace sapiencial\sapiencialapiclient\fields;

class SapiencialResourceField extends AbstractSapiencialField
{
    public static function displayName(): string
    {
        return 'Sapiencial Resource';
    }

    protected function referenceType(): string
    {
        return 'resource';
    }
}
