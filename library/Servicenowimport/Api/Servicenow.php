<?php

namespace Icinga\Module\Servicenowimport\Api;

use Icinga\Util\Json;

use GuzzleHttp\Client;
use RuntimeException;

/*
 * ServiceNow API Client
 */
class Servicenow
{
    protected $client = null;

    // $auth = [
    //     'method' => 'BASIC',
    //     'username' => 'user1',
    //     'password' => '123',
    //     'token' => '',
    // ];
    public function __construct(string $baseUri, bool $tlsVerify = true, int $timeout = 10, array $auth = [])
    {
        $c = [
            'base_uri' => $baseUri,
            'timeout' => $timeout,
            'verify' => $tlsVerify,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        $method = $auth['method'] ?? 'UNKNOWN';
        // Director does not store empty settings, so an unconfigured proxy arrives as null
        $proxyType = (string) ($auth['proxy_type'] ?? '');

        try {
            if ($method === 'BASIC') {
                $c['auth'] = [$auth['username'], $auth['password']];
            }

            if ($method === 'BEARER') {
                $c['headers']['x-sn-apikey'] = $auth['token'];
            }

            if ($method === 'OAUTH') {
                // Note: We currently don't store the token between requests
                $accessToken = $this->fetchAccessToken($baseUri, $auth);
                $c['headers']['Authorization'] = 'Bearer ' . $accessToken;
            }
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf("Failed to set authentication method %s", $e->getMessage()));
        }

        if (in_array($proxyType, ['HTTP', 'SOCKS5'], true)) {
            try {
                $rawProxy = trim((string) ($auth['proxy_address'] ?? ''));
                if ($rawProxy === '') {
                    throw new RuntimeException('no proxy address configured');
                }

                // parse_url() only detects a host if a scheme or a port is given,
                // so "proxy.example.com" alone would end up in 'path'
                $proxyAddress = parse_url(strpos($rawProxy, '://') === false ? '//' . $rawProxy : $rawProxy);
                if (! is_array($proxyAddress) || empty($proxyAddress['host'])) {
                    throw new RuntimeException(sprintf('invalid proxy address "%s"', $rawProxy));
                }

                $proxyHost = $proxyAddress['host'];
                $proxyScheme = 'https';
                $proxyPort = 443;

                if ($proxyType === 'HTTP') {
                    $proxyScheme = $proxyAddress['scheme'] ?? 'https';
                    $defaultPort = $proxyScheme === 'http' ? 80 : 443;
                    $proxyPort = $proxyAddress['port'] ?? $defaultPort;
                } elseif ($proxyType === 'SOCKS5') {
                    $proxyScheme = 'socks5';
                    $proxyPort = $proxyAddress['port'] ?? 1028;
                }

                $proxyCredentials = '';
                if (($auth['proxy_user'] ?? '') !== '') {
                    $proxyCredentials = sprintf(
                        '%s:%s@',
                        rawurlencode($auth['proxy_user']),
                        rawurlencode($auth['proxy_password'] ?? '')
                    );
                }

                $c['proxy'] = sprintf('%s://%s%s:%d', $proxyScheme, $proxyCredentials, $proxyHost, $proxyPort);
            } catch (\Exception $e) {
                throw new RuntimeException(sprintf("Failed to set proxy method %s", $e->getMessage()));
            }
        }

        $this->client = new Client($c);
    }

    /**
     * @param  string $endpoint
     * @param  array  $params
     * @param  string $method
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request(string $endpoint, array $params = [], string $method = "GET")
    {
        try {
            $response = $this->client->request($method, $endpoint, $params);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf("Request failed: %s", $e->getMessage()));
        }
    }

    /**
     * fetchAccessToken gets a new access token from the authorization server.
     */
    private function fetchAccessToken(string $baseUri, array $oauthConfig): string
    {
        $c = new Client(['base_uri' => $baseUri]);

        $params = [
            'grant_type' => 'client_credentials',
            'client_id' => $oauthConfig['client_id'],
            'client_secret' => $oauthConfig['client_secret'],
        ];

        if (array_key_exists('scope', $oauthConfig)) {
            $params['scope'] = $oauthConfig['scope'];
        }

        try {
            $response = $c->post('/oauth_token.do', ['form_params' => $params]);
            $data = Json::decode($response->getBody(), true);
        } catch (\Exception $e) {
            throw new RuntimeException(sprintf("Request failed: %s", $e->getMessage()));
        }

        if (array_key_exists('access_token', $data)) {
            return $data['access_token'];
        }

        throw new RuntimeException(sprintf("No access token in response", $data));
    }
}
