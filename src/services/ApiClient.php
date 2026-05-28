<?php

namespace sapiencial\sapiencialapiclient\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use sapiencial\sapiencialapiclient\Plugin;
use yii\base\InvalidConfigException;

class ApiClient extends Component
{
    private ?Client $client = null;

    /**
     * Performs a lightweight request to validate API connectivity and auth.
     */
    public function testConnection(?string $site = null): array
    {
        $effectiveSite = $site ?: $this->settings()->defaultSite;
        return $this->get('books', [
            'site' => $effectiveSite,
            'limit' => 1,
            'page' => 1,
        ]);
    }

    public function fetchByType(string $type, int $id, ?string $site = null): array
    {
        $endpoint = match ($type) {
            'book' => 'books/' . $id,
            'chapter' => 'chapters/' . $id,
            'resource' => 'resources/' . $id,
            default => throw new InvalidConfigException('Unsupported type: ' . $type),
        };

        return $this->get($endpoint, ['site' => $site ?: $this->settings()->defaultSite]);
    }

    public function search(string $type, string $query, string $site, int $limit = 15, int $page = 1): array
    {
        $endpoint = match ($type) {
            'book' => 'books',
            'chapter' => 'chapters',
            'resource' => 'resources',
            default => throw new InvalidConfigException('Unsupported type: ' . $type),
        };

        return $this->get($endpoint, [
            'q' => $query,
            'site' => $site ?: $this->settings()->defaultSite,
            'limit' => $limit,
            'page' => $page,
        ]);
    }

    private function get(string $path, array $query = []): array
    {
        $settings = $this->settings();
        $baseUrl = rtrim(App::parseEnv($settings->baseUrl), '/');
        $token = App::parseEnv($settings->apiToken);

        if ($baseUrl === '' || $token === '') {
            throw new InvalidConfigException('Sapiencial API settings incomplets (baseUrl/apiToken).');
        }

        $normalizedPath = ltrim($path, '/');
        $baseHasApiSuffix = (bool)preg_match('#/api$#i', $baseUrl);
        $fullPath = $baseHasApiSuffix ? $normalizedPath : 'api/' . $normalizedPath;
        $url = $baseUrl . '/' . $fullPath;

        try {
            $response = $this->client($baseUrl, $token, $settings->timeoutSeconds)->request('GET', $url, [
                'query' => $query,
            ]);
        } catch (ConnectException $e) {
            $error = sprintf(
                'Connection error to %s: %s',
                $url,
                $e->getMessage()
            );
            Craft::error('[sapiencial-api-client] ' . $error, __METHOD__);
            throw new InvalidConfigException($error, 0, $e);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $reason = $e->hasResponse() ? $e->getResponse()->getReasonPhrase() : 'No HTTP response';
            $body = $e->hasResponse() ? trim((string)$e->getResponse()->getBody()) : '';
            $body = mb_substr($body, 0, 600);

            $error = sprintf(
                'HTTP %d %s on %s%s',
                $status,
                $reason,
                $url,
                $body !== '' ? ' | Body: ' . $body : ''
            );
            Craft::error('[sapiencial-api-client] ' . $error, __METHOD__);
            throw new InvalidConfigException($error, 0, $e);
        } catch (GuzzleException $e) {
            $error = sprintf('Request error on %s: %s', $url, $e->getMessage());
            Craft::error('[sapiencial-api-client] ' . $error, __METHOD__);
            throw new InvalidConfigException($error, 0, $e);
        }

        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw new InvalidConfigException('Resposta API invàlida.');
        }

        return $decoded;
    }

    private function client(string $baseUrl, string $token, int $timeout): Client
    {
        if ($this->client === null) {
            $this->client = Craft::createGuzzleClient([
                'base_uri' => $baseUrl,
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->client;
    }

    private function settings(): \sapiencial\sapiencialapiclient\models\Settings
    {
        return Plugin::$plugin->getSettings();
    }
}
