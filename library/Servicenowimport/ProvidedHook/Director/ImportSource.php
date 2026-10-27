<?php

namespace Icinga\Module\Servicenowimport\ProvidedHook\Director;

use Icinga\Module\Servicenowimport\Api\Servicenow;

use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Util\Json;

use GuzzleHttp\Exception\GuzzleException;

class ImportSource extends ImportSourceHook
{
    private ?array $objectCache = null;

    private const CLIENT_TIMEOUT = 20;
    private const CLIENT_TLS_VERIFY = true;

    public function getName()
    {
        return "ServiceNow Table API";
    }

    protected static function addAuthOptions(QuickForm $form)
    {
        $method = $form->getSentOrObjectSetting('servicenow_authmethod');

        if ($method === 'BASIC') {
            $form->addElement(
                'text',
                'servicenow_username',
                [
                    'label' => 'API username',
                    'required' => true,
                    'description' => 'Username to authenticate at the ServiceNow API',
                ]
            );

            $form->addElement(
                'password',
                'servicenow_password',
                [
                    'label' => 'API password',
                    'required' => true,
                    'renderPassword' => true,
                    'description' => 'Password to authenticate at the ServiceNow API',
                ]
            );
        }

        if ($method === 'BEARER') {
            $form->addElement(
                'password',
                'servicenow_token',
                [
                    'label' => 'API token',
                    'required' => true,
                    'renderPassword' => true,
                    'description' => 'Token to authenticate at the ServiceNow API',
                ]
            );
        }

        if ($method === 'OAUTH') {
            $form->addElement(
                'text',
                'servicenow_oauth_client_id',
                [
                    'label' => 'OAuth Client ID',
                    'required' => true,
                    'description' => 'Client ID for the credentials',
                ]
            );

            $form->addElement(
                'password',
                'servicenow_oauth_client_secret',
                [
                    'label' => 'OAuth Client Secret',
                    'required' => true,
                    'renderPassword' => true,
                    'description' => 'Credentials for the client ID',
                ]
            );

            $form->addElement(
                'text',
                'servicenow_oauth_scope',
                [
                    'label' => 'OAuth scopes',
                    'required' => false,
                    'description' => 'Scopes for the access token',
                ]
            );
        }
    }

    /**
     * @param  QuickForm $form
     * @return void
     * @throws \Zend_Form_Exception
     */
    public static function addSettingsFormFields(QuickForm $form)
    {
        $form->addElement(
            'text',
            'servicenow_url',
            [
                'label' => 'ServiceNow API URL',
                'placeholder' => 'https://example.service-now.com',
                'required' => true,
                'description' => 'ServiceNow API URL. Full-qualified URL including protocol and domain (e.g. https://example.service-now.com)',
            ]
        );

        $form->addElement(
            'text',
            'servicenow_endpoint',
            [
                'label' => 'ServiceNow CMDB table endpoint',
                'placeholder' => 'api/now/table/cmdb_ci_computer',
                'required' => true,
                'description' => 'API endpoint to fetch objects from (e.g.: api/now/table/cmdb_ci_computer)',
            ]
        );

        $form->addElement(
            'select',
            'servicenow_authmethod',
            [
                'label' => 'API authentication method',
                'description' => 'Authentication method to use for the API',
                'multiOptions' => [
                    'BASIC' => 'Basic Auth',
                    'BEARER' => 'API Token',
                    'OAUTH' => 'ServiceNow OAuth2 Client Credentials',
                ],
                'class' => 'autosubmit',
                'required' => true,
            ]
        );

        static::addAuthOptions($form);

        $form->addElement(
            'text',
            'servicenow_timeout',
            [
                'label' => 'API timeout',
                'placeholder' => '20',
                'required' => false,
                'description' => 'Timeout in seconds used to query data from ServiceNow. Default is 20.',
            ]
        );

        $form->addElement(
            'text',
            'servicenow_columns',
            [
                'label' => 'ServiceNow columns',
                'placeholder' => 'name,ip_address',
                'required' => false,
                'description' => 'Comma separated list of columns to fetch. Leave empty to fetch all columns.',
            ]
        );

        $form->addElement(
            'text',
            'servicenow_query',
            [
                'label' => 'ServiceNow query',
                'required' => false,
                'description' => 'Query to filter records. Leave empty to fetch all records. '
                    . 'The query filter follows the official query syntax from ServiceNow: '
                    . 'https://www.servicenow.com/docs/bundle/yokohama-platform-user-interface/page/use/using-lists/concept/c_EncodedQueryStrings.html',
            ]
        );

        static::addProxy($form);
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function listColumns(): array
    {
        if ($this->objectCache === null) {
            $this->objectCache = $this->getRecordsFromTable();
        }

        $columns = [];

        foreach ($this->objectCache as $object) {
            foreach ($object as $key => $value) {
                $columns[$key] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * @return array
     * @throws GuzzleException
     */
    public function fetchData(): array
    {
        if ($this->objectCache === null) {
            $this->objectCache = $this->getRecordsFromTable();
        }

        return $this->objectCache;
    }

    /**
     * Select all records from table
     *
     * @return array
     * @throws GuzzleException
     */
    private function getRecordsFromTable(): array
    {
        $columns = null;
        $query = null;

        // Get requested columns
        if ($this->getSetting('servicenow_columns') !== "") {
            $columns = str_replace(' ', '', $this->getSetting('servicenow_columns'));
        }

        // Get query parameters
        if ($this->getSetting('servicenow_query') !== "") {
            $query = $this->getSetting('servicenow_query');
        }

        // Set endpoint
        $endpoint = $this->getSetting('servicenow_endpoint');

        $auth = [
            'method' => $this->getSetting('servicenow_authmethod'),
            'token' => $this->getSetting('servicenow_token'),
            'username' => $this->getSetting('servicenow_username'),
            'password' => $this->getSetting('servicenow_password'),
            'token_url' => $this->getSetting('servicenow_oauth_token_url'),
            'client_id' => $this->getSetting('servicenow_oauth_client_id'),
            'client_secret' => $this->getSetting('servicenow_oauth_client_secret'),
            'scope' => $this->getSetting('servicenow_oauth_scope'),
            'proxy_type' => $this->getSetting('proxy_type'),
            'proxy_address' => $this->getSetting('proxy'),
            'proxy_user' => $this->getSetting('proxy_user'),
            'proxy_password' => $this->getSetting('proxy_pass'),
        ];

        $client = new Servicenow(
            $this->getSetting('servicenow_url'),
            self::CLIENT_TLS_VERIFY,
            $this->getSetting('servicenow_timeout') ?: self::CLIENT_TIMEOUT,
            $auth
        );

        $result = $client->request(
            $endpoint,
            [
                'query' => [
                    'sysparm_display_value' => 'true',
                    'sysparm_fields' => $columns,
                    'sysparm_query' => rawurldecode($query),
                ]
            ]
        );

        $data = Json::decode($result, true);

        $result = $this->extractDisplayValues($data['result'] ?? []);

        // The Director expects every row to be an object (e.g. for property modifiers)
        return array_map(function ($row) {
            return (object) $row;
        }, $result);
    }

    /*
     * extractDisplayValues returns a copy of the given array but with
     * the with display_value as the values instead of objects.
     * We use sysparm_display_value the expand the ServiceNow objects.
     * each object will have the actual value in the field display_value.
     */
    protected function extractDisplayValues(array $array): array
    {
        return array_map(function ($value) {
            if (!is_array($value)) {
                return $value;
            }

            return array_key_exists('display_value', $value) ? $value['display_value'] : $this->extractDisplayValues($value);
        }, $array);
    }

    protected static function addProxy(QuickForm $form)
    {
        $form->addElement('select', 'proxy_type', [
            'label' => $form->translate('Proxy'),
            'description' => $form->translate(
                'In case your API is only reachable through a proxy, please'
                . ' choose it\'s protocol right here'
            ),
            'multiOptions' => $form->optionalEnum([
                'HTTP'   => $form->translate('HTTP proxy'),
                'SOCKS5' => $form->translate('SOCKS5 proxy'),
            ]),
            'class' => 'autosubmit'
        ]);

        $proxyType = $form->getSentOrObjectSetting('proxy_type');

        if ($proxyType) {
            $form->addElement('text', 'proxy', [
                'label' => $form->translate('Proxy Address'),
                'description' => $form->translate(
                    'Hostname, IP or <host>:<port>'
                ),
                'required' => true,
            ]);
            if ($proxyType === 'HTTP') {
                $form->addElement('text', 'proxy_user', [
                    'label'       => $form->translate('Proxy Username'),
                    'description' => $form->translate(
                        'In case your proxy requires authentication, please'
                        . ' configure this here'
                    ),
                ]);

                $form->addElement('storedPassword', 'proxy_pass', [
                    'label'    => $form->translate('Proxy Password'),
                    'required' => strlen((string) $form->getSentOrObjectSetting('proxy_user')) > 0
                ]);
            }
        }
    }
}
