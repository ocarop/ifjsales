<?php

namespace MauticPlugin\MauticCrmBundle\Api;

use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticCrmBundle\Integration\CrmAbstractIntegration;
use MauticPlugin\MauticCrmBundle\Integration\SalesforceIntegration;

/**
 * @property SalesforceIntegration $integration
 */
class InfojobsSalesApi extends CrmApi {

    protected $object = 'Lead';
    protected $requestSettings = [
        'encode_parameters' => 'json',
    ];
    protected $apiRequestCounter = 0;

    public function __construct(CrmAbstractIntegration $integration) {
        parent::__construct($integration);

        $this->requestSettings['curl_options'] = [
            CURLOPT_SSLVERSION => defined('CURL_SSLVERSION_TLSv1_1') ? CURL_SSLVERSION_TLSv1_1 : 5,
        ];
    }

    /**
     * @param        $operation
     * @param array  $elementData
     * @param string $method
     * @param bool   $retry
     * @param null   $object
     * @param null   $queryUrl
     *
     * @return mixed|string
     *
     * @throws ApiErrorException
     */
    public function request($operation, $elementData = [], $method = 'GET', $retry = false, $object = null, $queryUrl = null) {
        if (!$object) {
            $object = $this->object;
        }

        $requestUrl = (!$queryUrl) ? sprintf($this->integration->getApiUrl() . '/%s/%s', $object, $operation) : sprintf($queryUrl . '/%s', $operation);
        $settings = $this->requestSettings;
        if ($method == 'PATCH') {
            $settings['headers'] = ['Sforce-Auto-Assign' => 'FALSE'];
        }

        if (isset($queryUrl)) {
            // Query commands can have long wait time while SF builds response as the offset increases
            $settings['request_timeout'] = 300;
        }

        // Wrap in a isAuthorized to refresh token if applicable
        $response = $this->integration->makeRequest($requestUrl, $elementData, $method, $settings);
        ++$this->apiRequestCounter;

        if (!empty($response['errors'])) {
            throw new ApiErrorException(implode(', ', $response['errors']));
        } elseif (is_array($response)) {
            $errors = [];
            foreach ($response as $r) {
                if (is_array($r) && !empty($r['errorCode']) && !empty($r['message'])) {
                    // Check for expired session then retry if we can refresh
                    if ($r['errorCode'] == 'INVALID_SESSION_ID' && !$retry) {
                        $refreshError = $this->integration->authCallback(['use_refresh_token' => true]);

                        if (empty($refreshError)) {
                            return $this->request($operation, $elementData, $method, true, $object, $queryUrl);
                        } else {
                            $errors[] = $refreshError;
                        }
                    }
                    $errors[] = $r['message'];
                }
            }

            if (!empty($errors)) {
                throw new ApiErrorException(implode(', ', $errors));
            }
        }

        return $response;
    }

    /**
     * @param null|string $object
     *
     * @return mixed
     */
    public function getLeadFields($object = null) {
        if ($object == 'company') {
            $object = 'Account'; //salesforce object name
        }

        return $this->request('describe', [], 'GET', false, $object);
    }

    /**
     * @param array $data
     *
     * @return array
     * funcion reescrita para adaptarla a la logica que requiere Infojobs
     */
    public function getPerson(array $data) {
        $config = $this->integration->mergeConfigToFeatureSettings([]);
        $queryUrl = $this->integration->getQueryUrl();
        $sfRecords = [
            'Contact' => [],
            'Lead' => [],
        ];


        $accountsalesforceid = '';

        //buscar todos los contactos del Account


        //TODO: debug en esta linea para ver que tiene $data['Contact']['AccountId']
        if (!empty($data['Contact']['AccountId'])) {
            $this->integration->getLogger()->warn('Contact salesforce account id ' . $data['Contact']['AccountId']);
            $accountsalesforceid = $data['Contact']['AccountId'];
        } else {
            if (!empty($data['Lead']['AccountId'])) {
                $this->integration->getLogger()->warn('Lead salesforce account id ' . $data['Contact']['AccountId']);
                $accountsalesforceid = $data['Lead']['AccountId'];
            }
        }
        $this->integration->getLogger()->warn ('getPerson $accountsalesforceid ' . $accountsalesforceid);
        
        //TODO: repasar esta configuracion
        //if (!is_numeric($accountsalesforceid))
        //    $accountsalesforceid = '';

        if ($accountsalesforceid != '') {
            //Verificar que la cuenta existe
            //$findAccount = 'SELECT Id FROM Account where Id = \''
            //        . $accountsalesforceid . '\'';
            //$response = $this->request('query', ['q' => $findAccount], 'GET', false, null, $queryUrl);
            //$operation, $elementData = [], $method = 'GET', $retry = false, $object = null, $queryUrl = null
            try{
                $response = $this->request($accountsalesforceid, [], 'GET', false, 'Account');
                if (empty($response['Id'])) {
                    $this->integration->getLogger()->warn('AccountId incorrecto' . $accountsalesforceid);
                    $accountsalesforceid = '';
                }
            } catch (\Exception $e) {
                $this->integration->getLogger()->warn('AccountId incorrecto exception ' . $accountsalesforceid);
                $accountsalesforceid = '';
            }
        }

        $this->integration->getLogger()->warn ('getPerson $accountsalesforceid verificado vale: ' . $accountsalesforceid);
        if ($accountsalesforceid != '') {
            $this->integration->getLogger()->warn ('findContact ' . $data['Contact']['FirstName'] . ' ' . $data['Contact']['LastName']);
            $findContact = 'SELECT Id, FirstName, LastName, SegundoApellido__c,Email,'
                    . 'Phone,MobilePhone FROM Contact where Account.Id = \''
                    . $accountsalesforceid . '\''
                    . ' and FirstName = \'' . $data['Contact']['FirstName'] . '\''
                    . ' and LastName = \'' . $data['Contact']['LastName'] . '\'';
            $response = $this->request('query', ['q' => $findContact], 'GET', false, null, $queryUrl);


            $found = false;
            if (!empty($response['records'])) {
                //Recorrer todos los contactos del Account y tratamos de hacer match con Contact
                $this->integration->getLogger()->warn ('findContact encontro resultados');
                foreach ($response['records'] as $record) {
                    //Evaluar los criterios de matching
                    /*
                      • 100% - Contact -> Daremos por match 100% un contacto cuando:
                      ◦ Email + Phone + FirstName + LastName + MobilePhone + SegundoApellido
                      • 90% - Contact -> Daremos por match 90% un contacto cuando:
                      ◦ Email + FirstName + LastName + MobilePhone + Phone
                      • 50% - Contact -> Daremos por match 50% un contacto cuando:
                      ◦ Email + FirstName + LastName
                      • 30% - Contact -> Daremos por match 30% un contacto cuando:
                      ◦ FirstName + LastName + MobilePhone + Phone
                      • 20% - Contact -> Daremos por match 20% un contacto cuando:
                      ◦ FirstName + LastName
                     * 
                     */

                    // 100% - Contact -> Daremos por match 100% un contacto cuando: 
                    // Email + Phone + FirstName + LastName + MobilePhone + SegundoApellido
                    $this->integration->getLogger()->warn ('evaluando reglas de mathc registro de contact' . $record['FirstName']);
                    if ($record['FirstName'] == $data['Contact']['FirstName'] &&
                            $record['LastName'] == $data['Contact']['LastName'] &&
                            $record['Email'] == $data['Contact']['Email'] &&
                            $record['Phone'] == $data['Contact']['Phone'] &&
                            $record['MobilePhone'] == $data['Contact']['MobilePhone'] &&
                            $record['SegundoApellido__c'] == $data['Contact']['SegundoApellido']) {
                        if (!$found) {
                            $this->integration->getLogger()->warn('match 100% Contact');
                            $sfRecords['Contact']['records'] = $record;
                            $found = true;
                        }
                    }
                    // 90% - Contact -> Daremos por match 90% un contacto cuando:
                    // Email + FirstName + LastName + MobilePhone + Phone                    
                    if (!$found) {
                        if ($record['FirstName'] == $data['Contact']['FirstName'] &&
                                $record['LastName'] == $data['Contact']['LastName'] &&
                                $record['Email'] == $data['Contact']['LastName'] &&
                                $record['Phone'] == $data['Contact']['Phone'] &&
                                $record['MobilePhone'] == $data['Contact']['MobilePhone']) {
                            $this->integration->getLogger()->warn('match 90% Contact');
                            $sfRecords['Contact']['records'] = $record;
                            $found = true;
                        }
                    }
                    // 50% - Contact -> Daremos por match 50% un contacto cuando:
                    // Email + FirstName + LastName
                    if (!$found) {
                        if ($record['FirstName'] == $data['Contact']['FirstName'] &&
                                $record['LastName'] == $data['Contact']['LastName'] &&
                                $record['Email'] == $data['Contact']['Email']) {
                            $this->integration->getLogger()->warn('match 50% Contact');
                            $sfRecords['Contact']['records'] = $record;
                            $found = true;
                        }
                    }
                    //30% - Contact -> Daremos por match 30% un contacto cuando:
                    // FirstName + LastName + MobilePhone + Phone
                    if (!$found) {
                        if ($record['FirstName'] == $data['Contact']['FirstName'] &&
                                $record['LastName'] == $data['Contact']['LastName'] &&
                                $record['Phone'] == $data['Contact']['Phone'] &&
                                $record['MobilePhone'] == $data['Contact']['MobilePhone']) {
                            $this->integration->getLogger()->warn('match 30% Contact');
                            $sfRecords['Contact']['records'] = $record;
                            $found = true;
                        }
                    }
                    // Contact -> Daremos por match 20% un contacto cuando:
                    //FirstName + LastName
                    if (!$found) {
                        if ($record['FirstName'] == $data['Contact']['FirstName'] &&
                                $record['LastName'] == $data['Contact']['LastName']
                        ) {
                            $this->integration->getLogger()->warn('match 20% Contact');
                            $sfRecords['Contact']['records'] = $record;
                            $found = true;
                        }
                    }
                }
            }
        }
        else
        {    
            //Si no lo hemos encontrado como contacto de esa Account,
            // buscamos entre todos los lead activos y no convertidos
            // siempre que tenga nombre de company 
            if (!$found && $data['Lead']['Company'] != 'Unknown') {
                $findLead = 'SELECT Id,Company,FirstName,LastName,Email,Phone FROM Lead where IsConverted=false and Status=\'Activo\' and IsDeleted=false'
                        . ' and Company= \'' . $data['Lead']['Company'] . '\'';
                $response = $this->request('query', ['q' => $findLead], 'GET', false, null, $queryUrl);
                $this->integration->getLogger()->warn('Buscando lead ' . $data['Lead']['Company']);
                foreach ($response['records'] as $record) {
                    /* /* TODO: Revisar: Evaluar los criterios de matching
                      Se busca el lead por -> Identificador Fiscal
                     * 
                     */
                    $this->integration->getLogger()->warn ('tratando registro de lead de resultados ' . $record['Company']);
                    if (!$found) {
                        //Si no se encuentra un lead con ese Identificador Fiscal, entonces se busca por: Company + Email + Name + Phone
                        //TODO: comprobar que esta establecido cada campo
                        if (isset($data['Lead']['Company']) &&
                                $record['Company'] == $data['Lead']['Company'] &&
                                $record['FirstName'] == $data['Lead']['FirstName'] &&
                                $record['LastName'] == $data['Lead']['LastName'] &&
                                $record['Email'] == $data['Lead']['Email'] &&
                                $record['Phone'] == $data['Lead']['Phone']
                        ) {
                            $this->integration->getLogger()->warn('match Lead por company+email+name+phone');
                            $sfRecords['Lead']['records'] = $record;
                            $found = true;
                        }
                    }
                    if (!$found) {
                        //Si no se encuentra un lead match 90%, entonces se busca por: Company + Email + Name
                        if (isset($data['Lead']['Company']) &&
                                $record['Company'] == $data['Lead']['Company'] &&
                                $record['FirstName'] == $data['Lead']['FirstName'] &&
                                $record['LastName'] == $data['Lead']['LastName'] &&
                                $record['Email'] == $data['Lead']['Email']
                        ) {
                            $this->integration->getLogger()->warn('match Lead por company+mail+name');
                            $sfRecords['Lead']['records'] = $record;
                            $found = true;
                        }
                    }
                    if (!$found) {
                        //Si no se encuentra un lead match 80%, entonces se busca por: Company + Email
                        if (isset($data['Lead']['Company']) &&
                                $record['Company'] == $data['Lead']['Company'] &&
                                $record['Email'] == $data['Lead']['Email']
                        ) {
                            $this->integration->getLogger()->warn('match Lead por company+email');
                            $sfRecords['Lead']['records'] = $record;
                            $found = true;
                        }
                    }
                    if (!$found) {
                        //Si no se encuentra un lead match 60%, entonces se busca por: Company + Phone
                        if (isset($data['Lead']['Company']) &&
                                $record['Company'] == $data['Lead']['Company'] &&
                                $record['Phone'] == $data['Lead']['Phone']
                        ) {
                            $this->integration->getLogger()->warn('match Lead por company+phone');
                            $sfRecords['Lead']['records'] = $record;
                            $found = true;
                        }
                    }
                    if (!$found) {
                        //Si no se encuentra un lead match 50%, entonces se busca por: Company                   
                        if (isset($data['Lead']['Company']) &&
                                $record['Company'] == $data['Lead']['Company']) {
                            $this->integration->getLogger()->warn('match Lead por company');
                            $sfRecords['Lead']['records'] = $record;
                            $found = true;
                        }
                    }
                }
            }
        }    
        /* try searching for lead as this has been changed before in updated done to the plugin
          if (isset($config['objects']) && false !== array_search('Contact', $config['objects']) && !empty($data['Contact']['Email'])) {
          $fields = $this->integration->getFieldsForQuery('Contact');
          $fields[] = 'Id';
          $fields = implode(', ', array_unique($fields));
          $findContact = 'select ' . $fields . ' from Contact where email = \'' . str_replace("'", "\'", $this->integration->cleanPushData($data['Contact']['Email'])) . '\'';
          $response = $this->request('query', ['q' => $findContact], 'GET', false, null, $queryUrl);

          if (!empty($response['records'])) {
          $sfRecords['Contact'] = $response['records'];
          }
          }

          if (isset($data['Lead']['identificadorFiscal__c'])) {
          $sfObject = 'Lead';
          //$sfRecord['records']['record']['Id']= $data['Lead']['Id'];
          $sfRecords['Lead']['record']['Id'] = $data['Lead']['identificadorFiscal__c'];
          unset($data[$sfObject]['identificadorFiscal__c']);
          unset($data[$sfObject]['Email']);
          } else {
          if (!empty($data['Lead']['Email'])) {
          $fields = $this->integration->getFieldsForQuery('Lead');
          $fields[] = 'Id';
          $fields = implode(', ', array_unique($fields));
          $findLead = 'select ' . $fields . ' from Lead where email = \'' . str_replace("'", "\'", $this->integration->cleanPushData($data['Lead']['Email'])) . '\' and ConvertedContactId = NULL';
          $response = $this->request('queryAll', ['q' => $findLead], 'GET', false, null, $queryUrl);

          if (!empty($response['records'])) {
          $sfRecords['Lead'] = $response['records'];
          }
          }
          }
         */
        $this->integration->getLogger()->warn ('findPerson $found ' . $found);
        return $sfRecords;
    }

    /**
     * @param array $data
     *
     * @return array
     */
    public function getCompany(array $data) {
        $config = $this->integration->mergeConfigToFeatureSettings([]);
        $queryUrl = $this->integration->getQueryUrl();
        $sfRecords = [
            'Account' => [],
        ];

        $appendToQuery = '';

        //try searching for lead as this has been changed before in updated done to the plugin
        if (isset($config['objects']) && false !== array_search('company', $config['objects']) && !empty($data['company']['Name'])) {
            $fields = $this->integration->getFieldsForQuery('Account');

            if (isset($data['company']['BillingCountry']) && !empty($data['company']['BillingCountry'])) {
                $appendToQuery .= ' and BillingCountry =  \'' . str_replace("'", "\'", $this->integration->cleanPushData($data['company']['BillingCountry'])) . '\'';
            }
            if (isset($data['company']['BillingCity']) && !empty($data['company']['BillingCity'])) {
                $appendToQuery .= ' and BillingCity =  \'' . str_replace("'", "\'", $this->integration->cleanPushData($data['company']['BillingCity'])) . '\'';
            }
            if (isset($data['company']['BillingState']) && !empty($data['company']['BillingState'])) {
                $appendToQuery .= ' and BillingState =  \'' . str_replace("'", "\'", $this->integration->cleanPushData($data['company']['BillingState'])) . '\'';
            }

            $fields[] = 'Id';
            $fields = implode(', ', array_unique($fields));
            $findContact = 'select ' . $fields . ' from Account where Name = \'' . str_replace("'", "\'", $this->integration->cleanPushData($data['company']['Name'])) . '\'' . $appendToQuery;
            $response = $this->request('queryAll', ['q' => $findContact], 'GET', false, null, $queryUrl);

            if (!empty($response['records'])) {
                $sfRecords['company'] = $response['records'];
            }
        }

        return $sfRecords;
    }

    /**
     * Creates Salesforce lead.
     *
     * @param array $data
     *
     * @return mixed
     */
    public function createLead(array $data) {
        $createdLeadData = [];

        if (isset($data['Email'])) {
            $createdLeadData = $this->createObject($data, 'Lead');
        }

        return $createdLeadData;
    }

    /**
     * @param array $data
     * @param       $sfObject
     *
     * @return mixed|string
     */
    public function createObject(array $data, $sfObject) {
        $objectData = $this->request('', $data, 'POST', false, $sfObject);
        $this->integration->getLogger()->debug('SALESFORCE: POST createObject ' . $sfObject . ' ' . var_export($data, true) . var_export($objectData, true));

        if (isset($objectData['id'])) {
            // Salesforce is inconsistent it seems
            $objectData['Id'] = $objectData['id'];
        }

        return $objectData;
    }

    /**
     * @param array $data
     * @param       $sfObject
     * @param       $sfObjectId
     *
     * @return array
     */
    public function updateObject(array $data, $sfObject, $sfObjectId) {
        $objectData = $this->request('', $data, 'PATCH', false, $sfObject . '/' . $sfObjectId);
        $this->integration->getLogger()->debug('SALESFORCE: PATCH updateObject ' . $sfObject . ' ' . var_export($data, true) . var_export($objectData, true));

        // Salesforce is inconsistent it seems
        $objectData['Id'] = $objectData['id'] = $sfObjectId;

        return $objectData;
    }

    /**
     * @param array $data
     *
     * @return mixed|string
     */
    public function syncMauticToSalesforce(array $data) {
        $queryUrl = $this->integration->getCompositeUrl();

        return $this->request('composite/', $data, 'POST', false, null, $queryUrl);
    }

    /**
     * @param array $activity
     * @param       $object
     *
     * @return array|mixed|string
     */
    public function createLeadActivity(array $activity, $object, $filters = []) {
        $config = $this->integration->getIntegrationSettings()->getFeatureSettings();
        $namespace = (!empty($config['namespace'])) ? $config['namespace'] . '__' : '';
        $mActivityObjectName = $namespace . 'mautic_timeline__c';
        $activityData = [];

        if (!empty($activity)) {
            foreach ($activity as $sfId => $records) {
                foreach ($records['records'] as $record) {
                    $body = [
                        $namespace . 'ActivityDate__c' => $record['dateAdded']->format('c'),
                        $namespace . 'Description__c' => $record['description'],
                        'Name' => $record['name'],
                        $namespace . 'Mautic_url__c' => $records['leadUrl'],
                        $namespace . 'ReferenceId__c' => $record['id'] . '-' . $sfId,
                    ];

                    if ($object === 'Lead') {
                        $body[$namespace . 'WhoId__c'] = $sfId;
                    } elseif ($object === 'Contact') {
                        $body[$namespace . 'contact_id__c'] = $sfId;
                    }

                    $activityData[] = [
                        'method' => 'POST',
                        'url' => '/services/data/v38.0/sobjects/' . $mActivityObjectName,
                        'referenceId' => $record['id'] . '-' . $sfId,
                        'body' => $body,
                    ];
                }
            }

            if (!empty($activityData)) {
                $request = [];
                $request['allOrNone'] = 'false';
                $chunked = array_chunk($activityData, 25);
                $results = [];
                foreach ($chunked as $chunk) {
                    // We can only submit 25 at a time
                    if ($chunk) {
                        $request['compositeRequest'] = $chunk;
                        $result = $this->syncMauticToSalesforce($request);
                        $results[] = $result;
                        $this->integration->getLogger()->debug('SALESFORCE: Activity response ' . var_export($result, true));
                    }
                }

                return $results;
            }

            return [];
        }
    }

    /**
     * Get Salesforce leads.
     *
     * @param array  $query
     * @param string $object
     *
     * @return mixed
     */
    public function getLeads($query, $object) {
        $organizationCreatedDate = $this->getOrganizationCreatedDate();
        $queryUrl = $this->integration->getQueryUrl();
        $ignoreConvertedLeads = '';
        if (isset($query['start'])) {
            if (strtotime($query['start']) < strtotime($organizationCreatedDate)) {
                $query['start'] = date('c', strtotime($organizationCreatedDate . ' +1 hour'));
            }
        }

        $fields = $this->integration->getFieldsForQuery($object);

        if (!empty($query['nextUrl'])) {
            $query = str_replace('/services/data/v34.0/query', '', $query['nextUrl']);
            $result = $this->request('query' . $query, [], 'GET', false, null, $queryUrl);
        } elseif (!empty($fields) and isset($query['start'])) {
            $fields[] = 'Id';
            $fields = implode(', ', array_unique($fields));

            $config = $this->integration->mergeConfigToFeatureSettings([]);
            if (isset($config['updateOwner']) && isset($config['updateOwner'][0]) && $config['updateOwner'][0] == 'updateOwner') {
                $fields = 'Owner.Name, Owner.Email, ' . $fields;
            }
            if ($object == 'Lead') {
                $ignoreConvertedLeads = ' and ConvertedContactId = NULL';
            }

            $getLeadsQuery = 'SELECT ' . $fields . ' from ' . $object . ' where LastModifiedDate>=' . $query['start'] . ' and LastModifiedDate<=' . $query['end'] . $ignoreConvertedLeads;
            $result = $this->request('queryAll', ['q' => $getLeadsQuery], 'GET', false, null, $queryUrl);
        } else {
            $result = $this->request('queryAll', ['q' => $query], 'GET', false, null, $queryUrl);
        }

        return $result;
    }

    /**
     * @return mixed
     */
    public function getOrganizationCreatedDate() {
        $cache = $this->integration->getCache();

        if (!$organizationCreatedDate = $cache->get('organization.created_date')) {
            $queryUrl = $this->integration->getQueryUrl();
            $organization = $this->request('query', ['q' => 'SELECT CreatedDate from Organization'], 'GET', false, null, $queryUrl);
            $organizationCreatedDate = $organization['records'][0]['CreatedDate'];
            $cache->set('organization.created_date', $organizationCreatedDate);
        }

        return $organizationCreatedDate;
    }

    /**
     * @return mixed|string
     */
    public function getCampaigns() {
        $campaignQuery = 'Select Id, Name from Campaign where isDeleted = false';
        $queryUrl = $this->integration->getQueryUrl();

        $result = $this->request('query', ['q' => $campaignQuery], 'GET', false, null, $queryUrl);

        return $result;
    }

    /**
     * @param $campaignId
     *
     * @return mixed|string
     */
    public function getCampaignMembers($campaignId) {
        $campaignMembersQuery = "Select CampaignId, ContactId, LeadId, isDeleted from CampaignMember where CampaignId = '" . trim($campaignId) . "'";
        $result = $this->request('query', ['q' => $campaignMembersQuery], 'GET', false, null, $this->integration->getQueryUrl());

        return $result;
    }

    /**
     * @param       $campaignId
     * @param       $object
     * @param array $personIds
     *
     * @return array
     */
    public function checkCampaignMembership($campaignId, $object, array $people) {
        $campaignMembers = [];
        if (!empty($people)) {
            $idField = "{$object}Id";
            $query = "Select Id, $idField from CampaignMember where CampaignId = '" . $campaignId
                    . "' and $idField in ('" . implode("','", $people) . "')";

            $foundCampaignMembers = $this->request('query', ['q' => $query], 'GET', false, null, $this->integration->getQueryUrl());
            if (!empty($foundCampaignMembers['records'])) {
                foreach ($foundCampaignMembers['records'] as $member) {
                    $campaignMembers[$member[$idField]] = $member['Id'];
                }
            }
        }

        return $campaignMembers;
    }

    /**
     * @param $campaignId
     *
     * @return mixed|string
     */
    public function getCampaignMemberStatus($campaignId) {
        $campaignQuery = "Select Id, Label from CampaignMemberStatus where isDeleted = false and CampaignId='" . $campaignId . "'";
        $queryUrl = $this->integration->getQueryUrl();

        $result = $this->request('query', ['q' => $campaignQuery], 'GET', false, null, $queryUrl);

        return $result;
    }

    /**
     * @return int
     */
    public function getRequestCounter() {
        $count = $this->apiRequestCounter;
        $this->apiRequestCounter = 0;

        return $count;
    }

}
