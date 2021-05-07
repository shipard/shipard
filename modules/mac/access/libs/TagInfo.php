<?php

namespace mac\access\libs;

use e10\Utility, \e10\utils, \e10\str, mac\access\TableLog;


/**
 * Class TagInfo
 * @package mac\access\libs
 */
class TagInfo extends Utility
{
	var $tagNdx = 0;
	var $tagInfo = [];
	var $now;

	var $personNdx;
	var $personRecData = NULL;


	/** @var \e10\persons\TablePersons */
	var $tablePersons;

	/** @var \mac\access\TableTags */
	var $tableTags;

	public function init()
	{
		$this->now = new \DateTime();
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->tableTags = $this->app()->table('mac.access.tags');
	}

	public function setTag($tagNdx)
	{
		$this->tagNdx = $tagNdx;
	}

	public function load()
	{
		$this->load_Tag();
		$this->load_Assignment();
		$this->load_Person();
	}

	protected function load_Tag()
	{
		$this->tagInfo['recData'] = $this->tableTags->loadItem($this->tagNdx);
	}

	function load_Assignment()
	{
		$q = [];
		array_push ($q, 'SELECT assignments.*,');
		array_push ($q, ' persons.fullName AS personFullName, persons.[id] AS personId,');
		array_push ($q, ' places.fullName as placeName, places.[id] AS placeId');
		array_push ($q, ' FROM [mac_access_tagsAssignments] AS assignments');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON assignments.person = persons.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON assignments.place = places.ndx');
		array_push ($q, ' WHERE assignments.[tag] = %i', $this->tagNdx);
		array_push ($q, ' AND assignments.[docState] = %i', 4000);
		array_push ($q, ' ORDER BY assignments.validFrom DESC');
		//array_push ($q, '');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$isValid = 1;

			if ($r['validFrom'] && $r['validFrom'] > $this->now)
				$isValid = 0;

			if ($r['validTo'] && $r['validTo'] < $this->now)
				$isValid = 0;

			if (!isset($this->tagInfo['currentAssignment']) && $isValid)
			{
				$this->tagInfo['currentAssignment'] = ['assignType' => $r['assignType']];
				$this->tagInfo['currentAssignment']['validFrom'] = utils::datef($r['validFrom'], '%d %T');
				$this->tagInfo['currentAssignment']['validTo'] = utils::datef($r['validTo'], '%d %T');

				if ($r['assignType'] === 0)
				{ // person
					$this->tagInfo['currentAssignment']['personNdx'] = $r['person'];
					$this->tagInfo['currentAssignment']['personId'] = $r['personId'];
					$this->tagInfo['currentAssignment']['personFullName'] = $r['personFullName'];

					$this->tagInfo['assigned'] = 1;
					$this->tagInfo['assignedToPerson'] = 1;
					$this->tagInfo['statusTitle'] = 'Čip je přiřazen osobě';
					$this->personNdx = $r['person'];
				}
				elseif ($r['assignType'] === 1)
				{ // place
					$this->tagInfo['currentAssignment']['placeNdx'] = $r['place'];
					$this->tagInfo['currentAssignment']['placeId'] = $r['placeId'];
					$this->tagInfo['currentAssignment']['placeFullName'] = $r['placeName'];

					$this->tagInfo['assigned'] = 1;
					$this->tagInfo['assignedToPlace'] = 1;
					$this->tagInfo['statusTitle'] = 'Čip je přiřazen k místu';
				}
			}
			$item = [
				'validFrom' => utils::datef($r['validFrom'], '%d %T'),
				'validTo' => utils::datef($r['validTo'], '%d %T'),
				'valid' => $isValid,
			];

			if ($r['assignType'] === 0)
			{
				$item['assigned'] = $r['personFullName'];
			}
			elseif ($r['assignType'] === 1)
			{
				$item['assigned'] = $r['placeName'];
			}
			$this->tagInfo['assignmentHistory'][] = $item;
		}

		if (!isset($this->tagInfo['currentAssignment']))
		{
			$this->tagInfo['statusTitle'] = 'Tento čip není nikam přiřazen';
			$this->tagInfo['assigned'] = 0;
			$this->tagInfo['unassigned'] = 1;
		}
	}

	function load_Person()
	{
		if (!$this->personNdx)
			return;

		$this->personRecData = $this->tablePersons->loadItem($this->personNdx);
		if (!$this->personRecData)
			return;

		$this->tagInfo['person'] = $this->personRecData;

		if ($this->personRecData['docState'] !== 4000 && $this->personRecData['docState'] !== 8000)
		{

		}

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
			$this->tagInfo['personAccessLevels'][] = $r['accessLevel'];
		}

		//if (!count($this->personAccessLevels))
		//	return;

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
		foreach ($rows as $r)
		{
			if ($r['keyType'] == 1)
				continue;
			$tagTypeCfg = $this->app()->cfgItem('mac.access.tagTypes.'.$r['keyType'], NULL);
			$item = [];
			$item['key'] = $r['keyValue'];
			$item['keyType'] = $tagTypeCfg['sc'];
			$item['keyIcon'] = $tagTypeCfg['icon'];
			$this->tagInfo['otherKeys'][] = $item;
		}

		// -- contacts
		$properties = $this->tablePersons->loadProperties ($this->personNdx);
		if (isset ($properties[$this->personNdx]['contacts']))
		{
			foreach ($properties[$this->personNdx]['contacts'] as &$p)
			{
				$p['class'] = 'label label-default';
			}

			$this->tagInfo['personContacts'] = $properties[$this->personNdx]['contacts'];
		}
	}
}

