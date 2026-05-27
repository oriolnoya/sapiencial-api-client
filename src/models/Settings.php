<?php

namespace sapiencial\sapiencialapiclient\models;

use craft\base\Model;

class Settings extends Model
{
    public string $baseUrl = '';
    public string $apiToken = '';
    public string $defaultSite = 'docsES';
    public int $timeoutSeconds = 10;

    public function rules(): array
    {
        return [
            [['baseUrl', 'apiToken', 'defaultSite'], 'string'],
            [['timeoutSeconds'], 'integer', 'min' => 1, 'max' => 120],
            [['baseUrl'], 'url'],
        ];
    }
}
