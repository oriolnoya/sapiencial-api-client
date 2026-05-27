<?php

namespace sapiencial\sapiencialapiclient\fields;

class SapiencialChapterField extends AbstractSapiencialField
{
    public static function displayName(): string
    {
        return 'Sapiencial Chapter';
    }

    protected function referenceType(): string
    {
        return 'chapter';
    }
}
