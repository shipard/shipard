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
		$this->checkIotValuesKinds();
		$this->checkIotThingsKinds();
		$this->checkIotUsers();
	}

	function checkIotValuesKinds()
	{
		/** @var \mac\iot\TableValuesKinds $tableValuesKinds */
		$tableValuesKinds = $this->app->table('mac.iot.valuesKinds');
		$allValuesTypes = $this->app->cfgItem('mac.iot.values.types');
		foreach ($allValuesTypes as $valueTypeNdx => $valueType)
		{
			$exist = $this->app->db()->query('SELECT ndx FROM [mac_iot_valuesKinds] WHERE [valueType] = %i', $valueTypeNdx)->fetch();
			if ($exist)
				continue;

			$newItem = [
				'valueType' => $valueTypeNdx,
				'fullName' => $valueType['fullName'], 'shortName' => $valueType['shortName'],
				'id' => $valueType['id'], 'topicType' => $valueType['topicType'],
				'docState' => 4000, 'docStateMain' => 2,
			];

			$tableValuesKinds->dbInsertRec($newItem);
		}
	}

	function checkIotThingsKinds()
	{
		/** @var \mac\iot\TableThingsKinds $tableThingsKinds */
		$tableThingsKinds = $this->app->table('mac.iot.thingsKinds');
		$allThingsTypes = $this->app->cfgItem('mac.iot.things.types');
		foreach ($allThingsTypes as $thingTypeNdx => $thingType)
		{
			if (!intval($thingTypeNdx))
				continue;

			$exist = $this->app->db()->query('SELECT ndx FROM [mac_iot_thingsKinds] WHERE [thingType] = %i', $thingTypeNdx)->fetch();
			if ($exist)
				continue;

			$newItem = [
				'thingType' => $thingTypeNdx,
				'fullName' => $thingType['fullName'], 'shortName' => $thingType['shortName'],
				'id' => $thingType['id'],
				'docState' => 4000, 'docStateMain' => 2,
			];

			$tableThingsKinds->dbInsertRec($newItem);
		}
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
}
