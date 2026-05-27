<?php

namespace sapiencial\sapiencialapiclient\fields;

class SapiencialBookField extends AbstractSapiencialField
{
    public static function displayName(): string
    {
        return 'Sapiencial Book';
    }

    protected function referenceType(): string
    {
        return 'book';
    }
}
