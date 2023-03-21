<?php

namespace mac\iot;
use \e10\utils;


/**
 * Class ModuleServices
 * @package mac\iot
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$this->checkIotUsers();
	}

	function checkIotUsers()
	{
		/** @var \mac\iot\Tableusers $tableUsers */
		$tableUsers = $this->app->table('mac.iot.users');
		$allUsersTypes = $this->app->cfgItem('mac.iot.usersTypes');

		foreach ($allUsersTypes as $userTypeId => $userType)
		{
			$exist = $this->app->db()->query('SELECT ndx FROM [mac_iot_users] WHERE [userType] = %s', $userTypeId)->fetch();
			if ($exist)
				continue;

			$newItem = [
				'userType' => $userTypeId,
				'name' => $userType['name'].' User',
				'login' => $userTypeId.'-'.utils::createToken(5),
				'password' => utils::createToken(rand(16, 20), TRUE),
				'docState' => 4000, 'docStateMain' => 2,
			];

			$tableUsers->dbInsertRec($newItem);
		}
	}

	protected function iotDeviceRefreshDM()
	{
		/** @var \mac\iot\TableDevices $tableDevices */
		$tableDevices = $this->app->table('mac.iot.devices');
		$tableDevices->refreshDataModels();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'iot-devices-refresh-dm': return $this->iotDeviceRefreshDM();
		}

		parent::onCliAction($actionId);
	}

}
