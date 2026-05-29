<?php

namespace sapiencial\sapiencialapiclient\models;

use craft\base\Model;

class Settings extends Model
{
    public string $baseUrl = '';
    public string $apiToken = '';
    public string $defaultSite = 'docsES';
    public int $timeoutSeconds = 10;

    public string $sapiencialBooksSectionHandle = 'sapiencialBooks';
    public string $sapiencialChaptersSectionHandle = 'sapiencialChapters';
    public string $sapiencialResourcesSectionHandle = 'sapiencialResources';
    public string $sapiencialPersonsSectionHandle = 'sapiencialPersons';
    public string $sapiencialTopicsSectionHandle = 'sapiencialTopics';

    public bool $enableDryRunByDefault = true;

    public function rules(): array
    {
        return [
            [['baseUrl', 'apiToken', 'defaultSite'], 'string'],
            [['sapiencialBooksSectionHandle', 'sapiencialChaptersSectionHandle', 'sapiencialResourcesSectionHandle', 'sapiencialPersonsSectionHandle', 'sapiencialTopicsSectionHandle'], 'string'],
            [['timeoutSeconds'], 'integer', 'min' => 1, 'max' => 120],
            [['enableDryRunByDefault'], 'boolean'],
            [['baseUrl'], 'url'],
        ];
    }
}
