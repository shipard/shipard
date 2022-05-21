<?php

namespace mac\access\libs;

use e10\Utility, \e10\utils, \e10\str, mac\access\TableLog;


/**
 * Class AccessGateCheck
 * @package mac\lan\libs
 *
 */
class AccessGateCheck extends Utility
{
	/** @var \mac\iot\TableSetups */
	var $tableIotSetups;
	/** @var \mac\iot\TableControls */
	var $tableIotControls;
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \e10\persons\TablePersons */
	var $tablePersons;
	/** @var \e10\base\TablePlaces */
	var $tablePlaces;
	/** @var \mac\access\TableLog */
	var $tableLog;

	var \mac\iot\libs\IotDevicesUtils $iotDevicesUtils;

	var $requestParams = NULL;
	var $requestType = NULL;
	var $requestTypeCfg = NULL;
	var $requestParamsValid = FALSE;
	var $mainKeyType = 0;

	/** @var \DateTime */
	var $now = NULL;
	public $result = ['success' => 0];
	var $logInfo = [];

	var $requestTypes = [
		'vd' => ['tagType' => 3],
		'rfid' => ['tagType' => 1],
		'call' => ['tagType' => 2],
		'device' => ['mainKeyType' => 3],

		'control' => ['mainKeyType' => 2]
	];

	var $setupRecData = NULL;
	var $setupCfg = NULL;
	var $setupRequestCfg = NULL;
	var $cameraRecData = NULL;
	var $tagRecData = NULL;
	var $tagAssignmentRecData = NULL;
	var $personNdx = 0;
	var $personRecData = NULL;
	var $personAccessLevels = [];

	var $placeNdx = 0;
	var $placeRecData = NULL;

	function setResult($msg)
	{
		$this->result ['msg'] = $msg;
		return FALSE;
	}

	public function setRequestParams($params)
	{
		if ($params === NULL)
		{
			$requestParamsStr = $this->app()->postData();
			$requestParams = json_decode ($requestParamsStr, TRUE);
			if ($requestParams)
			{
				$this->requestParams = $requestParams;
				return;
			}
		}

		$this->requestParams = $params;
	}

	function checkRequestParams()
	{
		if (!$this->requestParams)
			return $this->setResult('Missing request params');

		if (!isset($this->requestParams['srcPayload']))
			return $this->setResult('Missing `srcPayload` param');

		if (isset($this->requestParams['srcPayload']['_payload']) && is_string($this->requestParams['srcPayload']['_payload']))
		{
			$this->requestParams['type'] = 'rfid';
			$this->requestParams['value'] = $this->requestParams['srcPayload']['_payload'];
		}
		elseif (isset($this->requestParams['srcPayload']['action']) && $this->requestParams['srcPayload']['action'] === 'call')
		{
			$this->requestParams['type'] = 'call';
			$this->requestParams['value'] = $this->requestParams['srcPayload']['number'] ?? '!!!';
		}
		elseif (isset($this->requestParams['srcTopicInfo']['type']) && $this->requestParams['srcTopicInfo']['type'] === 'device')
		{
			$this->requestParams['type'] = 'device';
			$this->requestParams['value'] = $this->requestParams['srcTopic'];
		}
		elseif (isset($this->requestParams['srcPayload']['action']) && $this->requestParams['srcPayload']['action'] === 'control')
		{
			$this->requestParams['type'] = 'control';
			$this->requestParams['iotControl'] = $this->requestParams['srcPayload']['iotControl'];
			$this->requestParams['value'] = $this->requestParams['srcTopic'];
		}
		elseif (isset($this->requestParams['srcPayload']['action']) && $this->requestParams['srcPayload']['action'] === 'vd')
		{
			$this->requestParams['type'] = 'vd';
			$this->requestParams['cam'] = $this->requestParams['srcPayload']['cam'];
			$this->requestParams['value'] = $this->requestParams['srcPayload']['lp'];
		}

		if (!isset($this->requestTypes[$this->requestParams['type']]))
			return $this->setResult('Wrong `type` param');
		$this->requestType = $this->requestParams['type'];
		$this->requestTypeCfg = $this->requestTypes[$this->requestParams['type']];

		if (isset($this->requestTypeCfg['mainKeyType']))
			$this->mainKeyType = $this->requestTypeCfg['mainKeyType'];

		if (!isset($this->requestParams['setup']))
			return $this->setResult('Missing `setup` param');

		// -- key value
		if ($this->mainKeyType == 0)
		{
			if (!isset($this->requestParams['value']))
				return $this->setResult('Missing `value` param');
			if (!is_string($this->requestParams['value']))
				return $this->setResult('Bad `value` param `'.json_encode($this->requestParams['value']).'`');
			if ($this->requestParams['value'] === '')
				return $this->setResult('Blank `value` param');
		}

		if ($this->requestParams['type'] === 'vd' && !isset($this->requestParams['cam']))
			return $this->setResult('Missing `cam` param');

		if ($this->mainKeyType == 2)
		{ // iotControl
			if (!isset($this->requestParams['iotControl']) || $this->requestParams['iotControl'] === '')
				return $this->setResult('Missing / blank `iotControl` param');
		}
		if ($this->mainKeyType == 3)
		{ // @TODO: device
		}

		return TRUE;
	}

	public function check()
	{
		if (!$this->checkRequestParams())
		{
			$this->logInfo['state'] = TableLog::lsBadRequest;
			return FALSE;
		}

		if (!$this->checkGate1())
		{
			return FALSE;
		}	
		$this->logInfo['state'] = TableLog::lsAccessDenied;

		if ($this->mainKeyType == 0)
		{
			if (!$this->checkKey())
			{
				return FALSE;
			}	

			if ($this->tagAssignmentRecData['assignType'] === 0)
			{
				if (!$this->checkPerson())
					return FALSE;
			}
			elseif ($this->tagAssignmentRecData['assignType'] === 1)
			{
				if (!$this->checkPlace())
					return FALSE;
			}

			if (!$this->checkGate2())
				return FALSE;
		}
		elseif ($this->mainKeyType == 2)
		{ // iotControl
			if (!$this->checkIoTControl())
				return FALSE;
		}
		elseif ($this->mainKeyType == 3)
		{ // device
			$this->logInfo['msg'] = $this->requestParams['srcTopic'] ?? '!';
		}

		$this->logInfo['state'] = TableLog::lsAccessGranted;
		$this->result ['success'] = 1;

		return TRUE;
	}

	function checkGate1()
	{
		// -- gate
		$this->setupRecData = $this->tableIotSetups->loadItem($this->requestParams['setup']);
		if (!$this->setupRecData)
			return $this->setResult('Invalid `setup` id '.$this->requestParams['setup']);
		elseif ($this->setupRecData['docState'] !== 4000 && $this->setupRecData['docState'] !== 8000)
			return $this->setResult('Invalid `setup` state: '.$this->setupRecData['docState']);
		$this->logInfo['gate'] = $this->setupRecData['ndx'];

		$this->setupCfg = $this->app()->cfgItem('mac.iot.setups.types.'.$this->setupRecData['setupType'], NULL);
		$this->setupRequestCfg = $this->setupCfg['requests'][$this->requestParams['request']] ?? NULL;

		// -- camera
		if (isset($this->requestParams['cam']))
		{
			$this->cameraRecData = $this->tableDevices->loadItem($this->requestParams['cam']);
			if (!$this->cameraRecData)
				return $this->setResult('Invalid `cam` id '.$this->requestParams['cam']);
			elseif ($this->cameraRecData['docState'] !== 4000 && $this->cameraRecData['docState'] !== 8000)
				return $this->setResult('Invalid `cam` state: '.$this->cameraRecData['docState']);
			$this->logInfo['keyDevice'] = $this->cameraRecData['ndx'];
		}

		return TRUE;
	}

	function checkKey()
	{
		$this->logInfo['keyValue'] = str::upToLen($this->requestParams['value'], 40);

		$tagsRows = $this->db()->query(
			'SELECT * FROM [mac_access_tags] WHERE [keyValue] = %s', $this->requestParams['value'],
			' AND [tagType] = %i', $this->requestTypeCfg['tagType'],
			' AND [docState] = %i', 4000);
		$cntTags = 0;
		foreach ($tagsRows as $tagRow)
		{
			$cntTags++;
			if ($this->tagRecData)
				continue;
			$this->tagRecData = $tagRow->toArray();
			$this->logInfo['tagType'] = $tagRow['tagType'];
		}

		if ($cntTags > 1)
		{
			// todo: log message
		}

		if (!$this->tagRecData)
			return $this->setResult('Invalid param `value`; key not found [' . $this->requestParams['value'] . ']');

		$this->logInfo['tag'] = $this->tagRecData['ndx'];

		// -- key assignment
		$assignmentRows = $this->db()->query(
			'SELECT * FROM [mac_access_tagsAssignments] WHERE [tag] = %i', $this->tagRecData['ndx'],
			' AND [docState] = %i', 4000);
		$cntAssignments = 0;
		$tagMsg = '';
		foreach ($assignmentRows as $row)
		{
			$cntAssignments++;
			if ($this->tagAssignmentRecData)
				continue;

			$isValid = 1;

			if ($row['validFrom'] && $row['validFrom'] > $this->now)
			{
				$tagMsg = 'Key is not valid at this time';
				$isValid = 0;
			}

			if ($row['validTo'] && $row['validTo'] < $this->now)
			{
				$tagMsg = 'Key is expired';
				$isValid = 0;
			}

			if ($isValid)
				$this->tagAssignmentRecData = $row->toArray();
		}

		if (!$this->tagAssignmentRecData)
			return $this->setResult('Key is not assigned; '.$tagMsg);

		$this->logInfo['tagAssignType'] = $this->tagAssignmentRecData['assignType'];

		if ($this->tagAssignmentRecData['assignType'] === 0)
		{
			if (!$this->tagAssignmentRecData['person'])
				return $this->setResult('Blank key person');
		}
		elseif ($this->tagAssignmentRecData['assignType'] === 1)
		{
			if (!$this->tagAssignmentRecData['place'])
				return $this->setResult('Blank key place/room');
		}

		return TRUE;
	}

	function checkPerson()
	{
		$this->personNdx = $this->tagAssignmentRecData['person'];
		$this->personRecData = $this->tablePersons->loadItem($this->personNdx);
		if (!$this->personRecData)
			return $this->setResult('Invalid person ['.$this->personNdx.']');
		if ($this->personRecData['docState'] !== 4000 && $this->personRecData['docState'] !== 8000)
			return $this->setResult('Invalid person state ['.$this->personNdx.'/'.$this->personRecData['docState'].']');

		$this->logInfo['person'] = $this->personNdx;

		// -- access levels based on users groups
		$usersGroups = $this->db()->query ('SELECT [group] FROM [e10_persons_personsgroups] WHERE [person] = %i', $this->personNdx)->fetchPairs(NULL, 'group');		
		$groupsAccessLevels = [];
		if (count($usersGroups))
		{
			$groupsAccessLevels = $this->db()->query ('SELECT srcRecId FROM [e10_base_doclinks]', 
													' WHERE [linkId] = %s', 'mac-acccess-levels-pg', 
													' AND [srcTableId] = %s', 'mac.access.levels', ' AND [dstTableId] = %s', 'e10.persons.groups',
													' AND [dstRecId] IN %in', $usersGroups)->fetchPairs(NULL, 'srcRecId');
		}										

		// -- access level
		$q[] = 'SELECT accessLevels.* FROM [mac_access_personsAccessLevels] AS [accessLevels]';
		array_push($q, ' LEFT JOIN [mac_access_personsAccess] AS [personsAccess] ON [accessLevels].personAccess = [personsAccess].ndx');
		array_push($q, ' WHERE 1');
		
		array_push($q, ' AND (');
		array_push($q, ' personsAccess.person = %i', $this->personNdx);
		if (count($groupsAccessLevels))
			array_push($q, ' OR accessLevels.ndx IN %in', $groupsAccessLevels);
		array_push($q, ')');

		array_push($q, ' AND personsAccess.docState = %i', 4000);
		array_push($q, ' AND (accessLevels.validFrom IS NULL OR accessLevels.validFrom = %s', '0000-00-00 00:00:00', ' OR accessLevels.validFrom < %t)', $this->now);
		array_push($q, ' AND (accessLevels.validTo IS NULL OR accessLevels.validTo = %s', '0000-00-00 00:00:00', ' OR accessLevels.validTo > %t)', $this->now);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->personAccessLevels[] = $r['accessLevel'];
			$this->logInfo['personAccess'] = $r['accessLevel'];
		}

		if (!count($this->personAccessLevels))
			return $this->setResult('No access levels for person ['.$this->personNdx.'] or user groups '.json_encode($usersGroups));

		return TRUE;
	}

	function checkPlace()
	{
		$this->placeNdx = $this->tagAssignmentRecData['place'];
		$this->placeRecData = $this->tablePlaces->loadItem($this->placeNdx);
		if (!$this->placeRecData)
			return $this->setResult('Invalid place ['.$this->placeNdx.']');
		if ($this->placeRecData['docState'] !== 4000 && $this->placeRecData['docState'] !== 8000)
			return $this->setResult('Invalid place state ['.$this->placeNdx.'/'.$this->placeRecData['docState'].']');

		$this->logInfo['place'] = $this->placeNdx;

		// -- access level
		$q[] = 'SELECT [levels].* FROM [mac_access_levels] AS [levels]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [levels].enableRoomAccess = %i', 1);
		array_push($q, ' AND [levels].docState IN %in', [4000, 8000]);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->personAccessLevels[] = $r['ndx'];
			//$this->logInfo['personAccess'] = $r['accessLevel'];
		}

		if (!count($this->personAccessLevels))
			return $this->setResult('No access levels for place ['.$this->placeNdx.']');

		return TRUE;
	}

	function checkGate2()
	{
		$dow = intval($this->now->format('N'));
		$minutes = intval($this->now->format('H')) * 60 + intval($this->now->format('i'));

		$q[] = 'SELECT levelsCfg.* FROM [mac_access_levelsCfg] AS [levelsCfg]';
		array_push($q, ' LEFT JOIN [mac_access_levels] AS [levels] ON [levelsCfg].accessLevel = [levels].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND levels.docState IN %in', [4000, 8000]);
		array_push($q, ' AND levelsCfg.accessLevel IN %in', $this->personAccessLevels);
		array_push($q, ' AND (levelsCfg.gate = %i', 0, ' OR levelsCfg.gate = %i)', $this->requestParams['setup']);
		array_push($q, ' AND levelsCfg.enableDOW'.$dow.' = %i', 1);
		array_push($q, ' AND (levelsCfg.enabledTimeFromMin = %i', 0, ' OR levelsCfg.enabledTimeFromMin <= %i)', $minutes);
		array_push($q, ' AND (levelsCfg.enabledTimeToMin = %i', 0, ' OR levelsCfg.enabledTimeToMin >= %i)', $minutes);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			return TRUE;
		}

		return $this->setResult('No access level enabled');
	}

	function checkIoTControl()
	{
		// -- iotControl
		$iotControlRecData = $this->tableIotControls->loadRecData('@uid:'.$this->requestParams['iotControl']);
		if (!$iotControlRecData)
			return $this->setResult('Invalid `iotControl` uid AAA'.$this->requestParams['iotControl']);
		elseif ($iotControlRecData['docState'] !== 4000 && $iotControlRecData['docState'] !== 8000)
			return $this->setResult('Invalid `iotControl` state: '.$iotControlRecData['docState']);
		$this->logInfo['iotControl'] = $iotControlRecData['ndx'];

		return TRUE;
	}

	function saveLog()
	{
		if (!isset($this->logInfo['state']))
		{
			$this->logInfo['state'] = TableLog::lsWarning;
		}
		if (isset($this->result ['msg']) && $this->result ['msg'] !== '')
			$this->logInfo['msg'] = $this->result ['msg'];

		$this->saveLogItem($this->logInfo);
	}

	function saveLogItem($info)
	{
		/*
		{"id": "created", "name": "Datum a čas", "type": "timestamp"},
		{"id": "state", "name": "Stav", "type": "enumInt",
			"enumValues": {"0": "OK", "1": "Přístup odepřen", "2": "varování"}, "3": "error"},
		{"id": "mainKeyType", "name": "Typ klíče", "type": "enumInt",
			"enumValues": {"0": "Tag", "1": "Vstupenka"}},
		{"id": "tagType", "name": "Druh klíče", "type": "enumInt",
			"enumCfg": {"cfgItem": "mac.access.tagTypes", "cfgValue": "", "cfgText": "name"}},
		{"id": "gate", "name": "Brána/dveře", "type": "int", "reference": "mac.access.gates"},
		{"id": "personAccess", "name": "Osoba", "type": "int", "reference": "mac.access.personsAccess"},
		{"id": "person", "name": "Osoba", "type": "int", "reference": "e10.persons.persons"},
		{"id": "keyValue", "name": "Klíč", "type": "string", "len": 40},
		{"id": "tag", "name": "Tag", "type": "int", "reference": "mac.access.tags"}

		 */

		$logItem = [
			'created' => $this->now,
			'mainKeyType' => $this->mainKeyType,
		];

		if ($info)
		{
			foreach ($info as $k => $v)
				$logItem[$k] = $v;
		}

		$this->tableLog->dbInsertRec($logItem);
	}

	function init()
	{
		$this->now = new \DateTime();

		$this->tableIotSetups = $this->app()->table('mac.iot.setups');
		$this->tableIotControls = $this->app()->table('mac.iot.controls');
		$this->tableLog = $this->app()->table('mac.access.log');
		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->tablePlaces = $this->app()->table('e10.base.places');

		$this->iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());
	}

	public function run ()
	{
		$this->init();

		if (!$this->requestParams)
			$this->setRequestParams(NULL);

		$this->requestParamsValid = $this->check();

		if ($this->requestParamsValid)
			$this->check();

		if ($this->result['success'])
		{
			$this->result ['callActions'] = [
				['topic' => $this->iotDevicesUtils->iotSetupTopic($this->setupRecData['ndx']), 'payload' => ['action' => $this->setupRequestCfg['onSuccess']]],
			];
		}
		else
		{
			$this->result ['callActions'] = [
				['topic' => $this->iotDevicesUtils->iotSetupTopic($this->setupRecData['ndx']), 'payload' => ['action' => $this->setupRequestCfg['onFail']]],
			];
		}

		$this->saveLog();
	}
}
