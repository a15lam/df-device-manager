<?php

namespace a15lam\DeviceManager;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Utility\Session;
use ServiceManager;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Utility\ResourcesWrapper;

class Device
{
    protected $deviceGroupResource;

    protected $userDeviceGroupResource;

    protected $deviceResource;

    protected $dbService;

    protected $apiKey;

    public function __construct($service, $device, $deviceGroup, $userDeviceGroup, $apiKey = '')
    {
        $this->dbService = $service;
        $this->deviceResource = $device;
        $this->deviceGroupResource = $deviceGroup;
        $this->userDeviceGroupResource = $userDeviceGroup;
        $this->apiKey = $apiKey;
    }

    public function getDeviceByUser($userId = null)
    {
        if(empty($userId)){
            $userId = Session::getCurrentUserId();
        }
        $deviceGroupId = $this->getDeviceGroupIdByUser($userId);
        $devices = $this->getDeviceByGroupId($deviceGroupId);
        $devices = "'". implode("','", $devices) . "'";

        if(!empty($devices)){
            $rs = ServiceManager::handleRequest(
                $this->dbService,
                Verbs::GET,
                $this->deviceResource,
                ['filter' => "mac in (".$devices.")", 'api_key' => $this->apiKey]
            );

            $content = ResourcesWrapper::unwrapResources($rs->getContent());

            if(count($content) > 0){
                return $content;
            }
        }

        throw new NotFoundException('No device(s) found under the user account.');
    }

    public function addDevice($mac, $userId = null)
    {
        $rg = new Registry($this->dbService, $this->deviceResource, $this->apiKey);
        if(!$rg->deviceExists($mac)){
            throw new NotFoundException('No device is registered with mac ' . $mac);
        }

        if(empty($userId)){
            $userId = Session::getCurrentUserId();
        }
        $deviceGroupId = $this->getDeviceGroupIdByUser($userId);

        if(empty($deviceGroupId)){
            $deviceGroupId = $this->getDeviceGroupIdByMac($mac);

            if(empty($deviceGroupId)){
                $deviceGroupId = $this->addDeviceGroup($mac);
            } else {
                $groupUserId = $this->getUserIdByGroupId($deviceGroupId);
                if($groupUserId !== $userId){
                    $deviceGroupId = $this->addDeviceGroup($mac);
                }
            }

            $this->addUserDeviceGroup($userId, $deviceGroupId);

            return ['success' => true];
        } else {
            $devices = $this->getDeviceByGroupId($deviceGroupId);

            if(!empty($devices)){
                if(!in_array($mac, $devices)) {
                    $devices[] = $mac;

                    $this->addDeviceToGroup($devices, $deviceGroupId);

                    return ['success' => true];
                } else {
                    throw new BadRequestException('Device already exists under the user group.');
                }
            }

            throw new InternalServerErrorException('An unexpected error occurred. No device(s) found under existing group id.');
        }

    }

    protected function getUserIdByGroupId($groupId)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::GET,
            $this->userDeviceGroupResource,
            ['filter' => "group_id = ".$groupId, 'api_key' => $this->apiKey]
        );

        $content = ResourcesWrapper::unwrapResources($rs->getContent());

        if(count($content) > 0){
            return array_get($content, '0.user_id');
        }

        return null;
    }

    public function removeDevice($mac)
    {
        $content = $this->getDeviceGroupByMac($mac);
        $devices = [];
        if(count($content) > 0){
            $macs = (array) array_get($content, '0.mac');
            $groupId = array_get($content, '0._id');
            if(count($macs) === 1){
                ServiceManager::handleRequest(
                    $this->dbService,
                    Verbs::DELETE,
                    $this->deviceGroupResource . '/' . $groupId,
                    ['api_key' => $this->apiKey]
                );

                ServiceManager::handleRequest(
                    $this->dbService,
                    Verbs::DELETE,
                    $this->userDeviceGroupResource,
                    ['filter' => 'group_id = ' . $groupId, 'api_key' => $this->apiKey]
                );

            } else {
                foreach ($macs as $m) {
                    if ($m !== $mac) {
                        $devices[] = $m;
                    }
                }
                $this->addDeviceToGroup($devices, $groupId);
            }

            ServiceManager::handleRequest(
                $this->dbService,
                Verbs::DELETE,
                $this->deviceResource,
                ['filter' => "mac = '" .$mac . "'", 'api_key' => $this->apiKey]
            );

            return ['success' => true];
        }

        return ['success' => false];
    }

    protected function addDeviceToGroup($device, $groupId)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::PATCH,
            $this->deviceGroupResource . '/' . $groupId,
            ['api_key' => $this->apiKey],
            [],
            [
                "mac" => $device
            ]
        );

        return $rs->getContent();
    }

    protected function getDeviceByGroupId($groupId)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::GET,
            $this->deviceGroupResource . '/' . $groupId,
            ['api_key' => $this->apiKey]
        );

        $content = $rs->getContent();

        if(!empty($content)){
            return (array) array_get($content, 'mac');
        }

        return null;
    }

    protected function addUserDeviceGroup($userId, $groupId)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::POST,
            $this->userDeviceGroupResource,
            ['api_key' => $this->apiKey],
            [],
            [
                "resource"=> [
                    [
                        "user_id" => $userId,
                        "group_id" => $groupId
                    ]
                ]
            ]
        );

        $content = ResourcesWrapper::unwrapResources($rs->getContent());

        if(count($content) > 0){
            return array_get($content, '0._id');
        }

        throw new InternalServerErrorException('Failed to create user device group for user id ' . $userId . ' and group id ' . $groupId);
    }

    protected function addDeviceGroup($mac)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::POST,
            $this->deviceGroupResource,
            ['api_key' => $this->apiKey],
            [],
            [
                "resource"=> [
                    "mac" => [$mac]
                ]
            ]
        );

        $content = ResourcesWrapper::unwrapResources($rs->getContent());

        if(count($content) > 0){
            return array_get($content, '0._id');
        }

        throw new InternalServerErrorException('Failed to create device group with mac ' . $mac);
    }

    protected function getDeviceGroupIdByMac($mac)
    {
        $content = $this->getDeviceGroupByMac($mac);

        if(count($content) > 0){
            return array_get($content, '0._id');
        }

        return null;
    }

    protected function getDeviceGroupByMac($mac)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::GET,
            $this->deviceGroupResource,
            ['filter' => "mac in ('".$mac."')", 'api_key' => $this->apiKey]
        );

        return ResourcesWrapper::unwrapResources($rs->getContent());
    }

    protected function getDeviceGroupIdByUser($userId)
    {
        $rs = ServiceManager::handleRequest(
            $this->dbService,
            Verbs::GET,
            $this->userDeviceGroupResource,
            ['filter' => "user_id = ".$userId, 'api_key' => $this->apiKey]
        );

        $content = ResourcesWrapper::unwrapResources($rs->getContent());

        if(count($content) > 0){
            return array_get($content, '0.group_id');
        }

        return null;
    }
}