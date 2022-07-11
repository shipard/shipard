<?php

namespace mac\access\dataView;

use \lib\dataView\DataView, \e10\utils;
use function E10\sortByOneKey;


/**
 * Class TagManager
 * @package mac\access\dataView
 */
class TagManager extends DataView
{
	/** @var \e10\persons\TablePersons */
	var $tablePersons;

	/** @var \mac\access\TableTags */
	var $tableTags;

	var $tagNdx = 0;
	var $tagInfo = [];

	protected function init()
	{
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->tableTags = $this->app()->table('mac.access.tags');

		$this->requestParams['showAs'] = 'webAppWidget';

		parent::init();

		$this->checkUser();

		$this->data['managedTagId'] = $this->app()->testGetParam('managedTagId');

		if ($this->data['managedTagId'] === '')
		{
			$this->data['statusTitle'] = 'Přiložte čip';
			return;
		}

		$actionId = $this->app()->testGetParam('action');
		if ($actionId !== '')
			$this->doAction($actionId);
	}

	protected function loadData()
	{
		if ($this->data['managedTagId'] === '')
			return;

		$this->loadDataTagInfo();
		$this->loadDataAssignPlaces();
		$this->loadDataAssignPersons();
	}

	protected function loadDataTagInfo()
	{
		$tagExist = $this->db()->query('SELECT * FROM [mac_access_tags] WHERE [keyValue] = %s', $this->data['managedTagId'], ' AND [docState] = %i', 4000, ' AND [tagType] = %i', 1)->fetch();
		if (!$tagExist)
		{
			$this->data['tagInfo']['statusTitle'] = 'Tento čip není v evidenci';
			$this->data['tagInfo']['notExist'] = 1;

			return;
		}

		$this->tagNdx = $tagExist['ndx'];

		$tagInfo = new \mac\access\libs\TagInfo($this->app());
		$tagInfo->init();
		$tagInfo->setTag($this->tagNdx);
		$tagInfo->load();

		$this->data['tagInfo'] = $tagInfo->tagInfo;
	}

	protected function loadDataAssignPlaces()
	{
		$this->data['assignPlaces'] = [];

		$useReservationTypes = ($this->app()->dataModel->module('e10pro.booking') !== FALSE) ? 1 : 0;
		$q = [];
		array_push($q, 'SELECT [places].*, [parentPlaces].fullName AS parentPlaceFullName');

		if ($useReservationTypes)
			array_push($q, ', [bt].[shortName] AS btShortName');

		array_push($q, ' FROM [e10_base_places] AS [places]');

		if ($useReservationTypes)
			array_push($q, ' LEFT JOIN [e10pro_booking_types] AS [bt] ON [places].[bookingType] = [bt].ndx');

		array_push($q, ' LEFT JOIN [e10_base_places] AS [parentPlaces] ON [places].[placeParent] = [parentPlaces].ndx');

		array_push($q, ' WHERE 1');
		if ($useReservationTypes)
			array_push($q, ' AND [bt].[assignTags] = %i', 1);

		array_push($q, ' AND [places].[docState] IN %in', [4000, 8000]);

		if ($useReservationTypes)
			array_push($q, ' ORDER BY [bt].[shortName], [parentPlaces].[id], [places].[id], [places].[ndx]');
		else
			array_push($q, ' ORDER BY [parentPlaces].[id], [places].id, [places].[ndx]');

		$rows = $this->db()->query($q);
		$activeGroup = 1;
		$lastParentPlace = -1;
		foreach ($rows as $r)
		{
			$assignPlaceGroup = ($useReservationTypes) ? $r['bookingType'] : 1;

			if (!isset($this->data['assignPlaces'][$assignPlaceGroup]))
			{
				$this->data['assignPlaces'][$assignPlaceGroup] = [
					'title' => $useReservationTypes ? $r['btShortName'] : 'Místa',
					'id' => 'APG-'.$assignPlaceGroup,
					'active' => $activeGroup,
					'places' => [],
				];
			}

			$item = ['ndx' => $r['ndx'], 'title' => $r['shortName'], 'fullTitle' => $r['fullName']];
			if ($lastParentPlace != $r['placeParent'])
			{
				$item['separator'] = $r['parentPlaceFullName'];
			}
			$this->data['assignPlaces'][$assignPlaceGroup]['places'][] = $item;
			$activeGroup = 0;
			$lastParentPlace = $r['placeParent'];
		}

		$this->data['assignPlaces'] = array_values($this->data['assignPlaces']);
	}

	protected function loadDataAssignPersons()
	{
		$displayGroups = [
			'ABC' => ['title' => 'ABC', 'order' => 1],
			'DEF' => ['title' => 'DEF', 'order' => 2],
			'GHI' => ['title' => 'GHChI', 'order' => 3],
			'JKL' => ['title' => 'JKL', 'order' => 4],
			'MNO' => ['title' => 'MNO', 'order' => 5],
			'PQRS' => ['title' => 'PQRS', 'order' => 6],
			'TUV' => ['title' => 'TUV', 'order' => 7],
			'WXYZ' => ['title' => 'WXYZ', 'order' => 8],
			'123' => ['title' => '123', 'order' => 9],
		];

		$displayGroupsLettes = [
			'A' => 'ABC', 'B' => 'ABC', 'C' => 'ABC',
			'D' => 'DEF', 'E' => 'DEF', 'F' => 'DEF',
			'G' => 'GHI', 'H' => 'GHI', 'I' => 'GHI',
			'J' => 'JKL', 'K' => 'JKL', 'L' => 'JKL',
			'M' => 'MNO', 'N' => 'MNO', 'O' => 'MNO',
			'P' => 'PQRS', 'Q' => 'PQRS', 'R' => 'PQRS', 'S' => 'PQRS',
			'T' => 'TUV', 'U' => 'TUV', 'V' => 'TUV',
			'W' => 'WXYZ', 'X' => 'WXYZ', 'Y' => 'WXYZ', 'Z' => 'WXYZ',
			'0' => '123', '1' => '123', '2' => '123', '3' => '123', '4' => '123', '5' => '123', '6' => '123', '7' => '123', '8' => '123', '9' => '123',
		];

		$this->data['assignPersons'] = [];

		$qpg = [];
		array_push($qpg, 'SELECT DISTINCT links.dstRecId FROM [e10_base_doclinks] AS [links]');
		array_push($qpg, ' LEFT JOIN [e10_persons_groups] AS [groups] ON links.dstRecId = [groups].ndx');
		array_push($qpg, ' WHERE srcTableId = %s', 'mac.access.levels', ' AND dstTableId = %s', 'e10.persons.groups');
		array_push($qpg, ' AND [links].linkId = %s', 'mac-acccess-levels-pg');
		array_push($qpg, ' AND [groups].docStateMain <= %i', 2);
		$rowsPG = $this->db()->query($qpg);
		$enabledPersonsGroups = [];
		foreach ($rowsPG as $pg)
		{
			$enabledPersonsGroups[] = $pg['dstRecId'];
		}

		$q = [];
		array_push($q, 'SELECT [persons].*');
		array_push($q, ' FROM [e10_persons_persons] AS [persons]');


		array_push($q, ' WHERE 1');

		array_push ($q, ' AND EXISTS ',
			'(SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person and [group] IN %in)', $enabledPersonsGroups);


		array_push($q, ' ORDER BY [persons].[lastName], [persons].[ndx]');

		$rows = $this->db()->query($q);
		$activeGroup = (count($this->data['assignPlaces'])) ? 0: 1;
		$lastParentPlace = -1;
		foreach ($rows as $r)
		{
			if ($r['fullName'] == '')
				continue;

			if ($r['company'])	
				$asciiFullName = strtoupper(strtr($r['fullName'], utils::$transDiacritic));
			else
				$asciiFullName = strtoupper(strtr($r['lastName'], utils::$transDiacritic));	
			$firstLetter = $asciiFullName[0];

			if (isset($displayGroupsLettes[$firstLetter]))
				$assignPersonGroup = $displayGroupsLettes[$firstLetter];
			else
				$assignPersonGroup = '123';

			if (!isset($this->data['assignPersons'][$assignPersonGroup]))
			{
				$this->data['assignPersons'][$assignPersonGroup] = [
					'title' => $displayGroups[$assignPersonGroup]['title'],
					'order' => $displayGroups[$assignPersonGroup]['order'],
					'id' => 'APSG-'.$assignPersonGroup,
					'active' => $activeGroup,
					'persons' => [],
				];
			}

			$item = ['ndx' => $r['ndx'], 'title' => $r['fullName'], 'fullTitle' => $r['fullName']];
			/*
			if ($lastParentPlace != $r['placeParent'])
			{
				$item['separator'] = $r['parentPlaceFullName'];
			}*/
			$this->data['assignPersons'][$assignPersonGroup]['places'][] = $item;
			$activeGroup = 0;
			//$lastParentPlace = $r['placeParent'];
		}

		$this->data['assignPersons'] = \e10\sortByOneKey($this->data['assignPersons'], 'order');//array_values($this->data['assignPersons']);
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'webAppWidget')
			return $this->renderDataAsWebAppWidget();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsWebAppWidget()
	{
		foreach ($this->data as $key => $value)
			$this->template->data[$key] = $value;

		$c = '';

		$c .= $this->template->renderSubTemplate('mac.access.tagManager');
		//$c .= "<br><br><br>TEST: <pre><code>" . utils::json_lint(/*$this->app()->user()->data*/ json_encode($this->data)) . "</code></pre>!!!";

		return $c;
	}

	protected function doAction($actionId)
	{
		if ($actionId === 'add')
			$this->doAction_Add();
		elseif ($actionId === 'assignToPerson')
			$this->doAction_Assign($actionId);
		elseif ($actionId === 'assignToPlace')
			$this->doAction_Assign($actionId);
		elseif ($actionId === 'unassignFromPerson')
			$this->doAction_Unassign($actionId);
		elseif ($actionId === 'unassignFromPlace')
			$this->doAction_Unassign($actionId);
	}

	protected function doAction_Add()
	{
		$newItem = [
			'tagType' => 1, 'id' => '',
			'keyValue' => $this->data['managedTagId'], 'note' => '',
			'docState' => 4000, 'docStateMain' => 2,
		];

		$newNdx = $this->tableTags->dbInsertRec($newItem);
		$this->tableTags->docsLog($newNdx);

		$this->reloadTo('/nastaveni-cipu?managedTagId='.$this->data['managedTagId']);
	}

	protected function doAction_Assign($actionId)
	{
		$assignToNdx = intval($this->app()->testGetParam('assignNdx'));
		if(!$assignToNdx)
			return;
		//error_log("--ASSIGN TO-- `$actionId` - `{$assignToNdx}`");

		$this->tableTags->assignTag($this->data['managedTagId'], $actionId, $assignToNdx);

		$this->reloadTo('/nastaveni-cipu?managedTagId='.$this->data['managedTagId']);
	}

	protected function doAction_Unassign($actionId)
	{
		$unassignFromNdx = intval($this->app()->testGetParam('assignNdx'));
		if(!$unassignFromNdx)
			return;
		//error_log("--UN-ASSIGN FROM-- `$actionId` - `{$unassignFromNdx}`");


		$this->tableTags->unAssignTag($this->data['managedTagId'], $actionId, $unassignFromNdx);
		$this->reloadTo('/nastaveni-cipu?managedTagId='.$this->data['managedTagId']);
	}
}
