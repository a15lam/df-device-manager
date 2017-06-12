<?php

namespace a15lam\DeviceManager;

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Utility\ResourcesWrapper;
use ServiceManager;

class Registry
{
    protected $dbService;

    protected $dbResource;

    protected $apiKey;

    protected $payload;

    public function __construct($service, $resource, $apiKey)
    {
        $this->dbService = $service;
        $this->dbResource = $resource;
        $this->apiKey = $apiKey;
    }

    public function register($payload)
    {
        $mac = array_get($payload, 'mac');

        if(!$id = $this->deviceExists($mac)){
            $payload = ResourcesWrapper::wrapResources($payload);
            $rs = ServiceManager::handleRequest(
                $this->dbService,
                Verbs::POST,
                $this->dbResource,
                ['api_key' => $this->apiKey],
                [],
                $payload
            );

            return $rs->getContent();
        } else {
            $rs = ServiceManager::handleRequest(
                $this->dbService,
                Verbs::PATCH,
                $this->dbResource.'/'.$id,
                ['api_key' => $this->apiKey],
                [],
                $payload
            );

            return $rs->getContent();
        }
    }

    protected function deviceExists($mac)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::GET,
            $this->dbResource,
            ['filter' => "mac = '".$mac."'", 'api_key' => $this->apiKey]
        );

        $content = ResourcesWrapper::unwrapResources($rs->getContent());

        return (count($content) > 0)? $content[0]['_id'] : false;
    }
}