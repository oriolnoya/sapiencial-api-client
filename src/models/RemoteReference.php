<?php

namespace sapiencial\sapiencialapiclient\models;

use craft\base\Model;

class RemoteReference extends Model
{
    public string $type = '';
    public int $remoteId = 0;
    public string $slug = '';
    public string $title = '';
    public string $site = '';

    public function rules(): array
    {
        return [
            [['type', 'slug', 'title', 'site'], 'string'],
            [['remoteId'], 'integer', 'min' => 1],
            [['type'], 'in', 'range' => ['book', 'chapter', 'resource']],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'type' => $this->type,
            'remoteId' => $this->remoteId,
            'slug' => $this->slug,
            'title' => $this->title,
            'site' => $this->site,
        ];
    }
}
