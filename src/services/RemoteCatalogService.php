<?php

namespace sapiencial\sapiencialapiclient\services;

use craft\base\Component;
use sapiencial\sapiencialapiclient\Plugin;

class RemoteCatalogService extends Component
{
    public function listRemoteBooks(string $query = '', ?string $site = null, int $limit = 200): array
    {
        $result = Plugin::$plugin->get('apiClient')->search('book', $query, $site, $limit, 1);
        return $result['items'] ?? [];
    }
}
