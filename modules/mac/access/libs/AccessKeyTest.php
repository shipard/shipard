<?php

namespace mac\access\libs;

use e10\Utility, \e10\utils, \e10\str, mac\access\TableLog;


/**
 * Class AccessKeyTest
 * @package mac\access\libs
 */
class AccessKeyTest extends Utility
{
	/** @var \e10\persons\TablePersons */
	var $tablePersons;
	/** @var \e10\base\TablePlaces */
	var $tablePlaces;
	/** @var \mac\access\TableLog */
	var $tableLog;

	var $requestParams = NULL;
	var $requestType = NULL;
	var $requestTypeCfg = NULL;
	var $requestParamsValid = FALSE;

	/** @var \DateTime */
	var $now = NULL;
	public $result = ['success' => 0];
	var $logInfo = [];

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
				return TRUE;
			}
		}

		$this->requestParams = $params;

		return FALSE;
	}

	function checkRequestParams()
	{
		// -- key value
		if (!isset($this->requestParams['value']))
			return $this->setResult('Missing `value` param');
		if (!is_string($this->requestParams['value']))
			return $this->setResult('Bad `value` param');
		if ($this->requestParams['value'] === '')
			return $this->setResult('Blank `value` param');

		return TRUE;
	}

	public function check()
	{
		if (!$this->checkRequestParams())
		{
			$this->logInfo['state'] = TableLog::lsBadRequest;
			return FALSE;
		}


		$this->logInfo['state'] = TableLog::lsAccessDenied;

		if (!$this->checkKey())
			return FALSE;

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

		$this->logInfo['state'] = TableLog::lsAccessGranted;
		$this->result ['success'] = 1;

		return TRUE;
	}


	function checkKey()
	{
		$this->logInfo['keyValue'] = str::upToLen($this->requestParams['value'], 40);

		$tagsRows = $this->db()->query(
			'SELECT * FROM [mac_access_tags] WHERE [keyValue] = %s', $this->requestParams['value'],
			' AND [tagType] = %i', 1,
			' AND [docState] = %i', 4000);
		$cntTags = 0;
		foreach ($tagsRows as $tagRow)
		{
			$cntTags++;
			if ($this->tagRecData)
				continue;
			$this->tagRecData = $tagRow->toArray();
		}

		if ($cntTags > 1)
		{
			// todo: log message
		}

		if (!$this->tagRecData)
			return $this->setResult('Invalid param `value`; key1 not found [' . $this->requestParams['value'] . ']');

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

		$this->result['assignType'] = $this->tagAssignmentRecData['assignType'];

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

		$this->result['person'] = $this->personRecData['fullName'];

		$this->logInfo['person'] = $this->personNdx;

		// -- access level
		$q = [];
		$q[] = 'SELECT accessLevels.* FROM [mac_access_personsAccessLevels] AS [accessLevels]';
		array_push($q, ' LEFT JOIN [mac_access_personsAccess] AS [personsAccess] ON [accessLevels].personAccess = [personsAccess].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND personsAccess.person = %i', $this->personNdx);
		array_push($q, ' AND personsAccess.docState = %i', 4000);
		array_push($q, ' AND (accessLevels.validFrom IS NULL OR accessLevels.validFrom < %t)', $this->now);
		array_push($q, ' AND (accessLevels.validTo IS NULL OR accessLevels.validTo > %t)', $this->now);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->personAccessLevels[] = $r['accessLevel'];
			$this->logInfo['personAccess'] = $r['accessLevel'];
		}

		if (!count($this->personAccessLevels))
			return $this->setResult('No access levels for person ['.$this->personNdx.']');

		// -- other keys
		$q = [];
		array_push ($q, 'SELECT assignments.*,');
		array_push ($q, ' tags.keyValue AS keyValue, tags.tagType AS keyType');
		array_push ($q, ' FROM [mac_access_tagsAssignments] AS assignments');
		array_push ($q, ' LEFT JOIN mac_access_tags AS tags ON assignments.tag = tags.ndx');
		array_push ($q, ' WHERE assignments.[person] = %i', $this->personNdx);
		array_push ($q, ' AND assignments.[docState] = %i', 4000);
		array_push ($q, ' AND (assignments.[validFrom] IS NULL OR assignments.[validFrom] <= %t', $this->now, ')');
		array_push ($q, ' AND (assignments.[validTo] IS NULL OR assignments.[validTo] >= %t', $this->now, ')');
		array_push ($q, ' ORDER BY assignments.validFrom DESC');

		$rows = $this->db()->query($q);
		error_log(\dibi::$sql);
		foreach ($rows as $r)
		{
			if ($r['keyType'] == 1)
				continue;
			$tagTypeCfg = $this->app()->cfgItem('mac.access.tagTypes.'.$r['keyType'], NULL);
			$item = [];
			$item['key'] = $r['keyValue'];
			$item['keyType'] = $tagTypeCfg['sc'];
			$this->result['keys'][] = $item;
		}


		// -- gates?

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
		array_push($q, ' LEFT JOIN [mac_access_personsAccess] AS [personsAccess] ON [accessLevels].personAccess = [personsAccess].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [levels].enableRoomAccess = %i', 1);
		array_push($q, ' AND [levels].docState IN %in', [4000, 8000]);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->personAccessLevels[] = $r['ndx'];
		}

		if (!count($this->personAccessLevels))
			return $this->setResult('No access levels for place ['.$this->placeNdx.']');

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
		$logItem = [
			'created' => $this->now,
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

		$this->tableLog = $this->app()->table('mac.access.log');
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->tablePlaces = $this->app()->table('e10.base.places');
	}

	public function run ()
	{
		$this->init();

		$this->setRequestParams(NULL);
		$this->requestParamsValid = $this->checkRequestParams();

		if ($this->requestParamsValid)
			$this->check();

		$this->saveLog();
	}
}
