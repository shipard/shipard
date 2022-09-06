<?php

namespace lib\core\logs;

use e10\DbTable, e10\Utility;
use function E10\searchArray;


/**
 * Class DocumentDiff
 * @package lib\core\logs
 */
class DocumentDiff extends Utility
{
	var $oldDocData = NULL;
	var $newDocData = NULL;
	var $diffData = [];
	var $diffContent = [];

	/** @var DbTable */
	var $mainTable = NULL;

	function prepareArray(&$data)
	{
		if (!is_array($data))
			return;
		foreach ($data as $k => $v)
		{
			if (is_array($v) && isset($v['timezone']))
				$data[$k] = new \DateTime($v['date']);
		}
	}

	function arrayDiff ($oldArray, $newArray, &$dst)
	{
		$usedKeys = [];
		if ($oldArray)
		{
			foreach ($oldArray as $oldKey => $oldValue)
			{
				$usedKeys[] = $oldKey;
				if (is_array($oldValue))
					continue;
				if (isset($newArray[$oldKey]))
				{
					if ($oldValue != $newArray[$oldKey])
					{
						$dst[$oldKey] = ['oldValue' => $oldValue, 'newValue' => $newArray[$oldKey]];
					}
				}
			}
		}

		foreach ($newArray as $newKey => $newValue)
		{
			if (in_array($newKey, $usedKeys))
				continue;
			if (is_array($newValue))
				continue;
			$dst[$newKey] = ['newValue' => $newArray[$newKey]];
		}
	}

	public function setData ($tableId, $oldDocData, $newDocData)
	{
		$this->oldDocData = $oldDocData;
		$this->newDocData = $newDocData;

		$this->mainTable = $this->app()->table($tableId);
	}

	function diffRecData()
	{
		$ignoredColumns = ['docState', 'docStateMain'];

		$this->prepareArray($this->oldDocData['recData']);
		$this->prepareArray($this->newDocData['recData']);

		$this->diffData['recData'] = [];
		$this->arrayDiff($this->oldDocData['recData'], $this->newDocData['recData'], $this->diffData['recData']);

		if (count($this->diffData['recData']))
		{
			$t = [];
			$h = ['c' => 'Údaj', 'v' => 'Hodnota'];

			foreach ($this->diffData['recData'] as $columnId => $columnData)
			{
				if (in_array($columnId, $ignoredColumns))
					continue;

				$oldRow = ['c' => $this->mainTable->columnName($columnId), 'v' => $columnData['oldValue'], '_options' => ['cellClasses' => ['v' => 'e10-row-minus'], 'rowSpan' => ['c' => 2]]];
				$newRow = ['c' => '', 'v' => $columnData['newValue'], '_options' => ['cellClasses' => ['v' => 'e10-row-plus']]];
				$t[] = $oldRow;
				$t[] = $newRow;
			}
		}

		if (is_array($t) && count($t))
			$this->diffContent[] = ['table' => $t, 'header' => $h];
	}

	function diffProperties()
	{
		$cntOld = isset($this->oldDocData['lists']['properties']) ? count($this->oldDocData['lists']['properties']) : 0;
		$cntNew = isset($this->newDocData['lists']['properties']) ? count($this->newDocData['lists']['properties']) : 0;
		if (!$cntOld && !$cntNew)
			return;

		$t = [];
		$h = ['c' => 'Vlastnost', 'v' => 'Hodnota', 'n' => 'Pozn.'];

		$usedOld = [];

		// -- changed
		foreach ($this->oldDocData['lists']['properties'] as $row)
		{
			$oldRowNdx = $row['ndx'];
			$usedOld[] = $oldRowNdx;

			if (is_array($row['value']))
				continue;

			$newRow = searchArray($this->newDocData['lists']['properties'], 'ndx', $oldRowNdx);
			if ($newRow)
			{
				if ($row['value'] === $newRow['value'] && $row['note'] === $newRow['note'])
				{
					continue;
				}

				$oldRow = ['c' => $row['name'], 'v' => $row['value'], 'n' => $row['note'], '_options' => ['cellClasses' => ['v' => 'e10-row-minus'], 'rowSpan' => ['columnId' => 2]]];
				$newRow = ['c' => '', 'v' => $newRow['value'], 'n' => $newRow['note'], '_options' => ['cellClasses' => ['v' => 'e10-row-plus']]];
				$t[] = $oldRow;
				$t[] = $newRow;
			}
			else
			{
				$newRow = ['c' => $row['name'], 'v' => $row['value'], 'n' => $row['note'], '_options' => ['class' => 'e10-row-minus']];
				$t[] = $newRow;
			}
		}

		// -- new
		foreach ($this->newDocData['lists']['properties'] as $newRow)
		{
			$newRowNdx = $newRow['ndx'];
			if (in_array($newRowNdx, $usedOld))
				continue;

			if (is_array($newRow['value']))
				continue;


			$row = ['c' => $newRow['name'], 'v' => $newRow['value'], 'n' => $newRow['note'], '_options' => ['class' => 'e10-row-plus']];
			$t[] = $row;
		}

		if (count($t))
			$this->diffContent[] = ['table' => $t, 'header' => $h, 'title' => ['text' => 'Vlastnosti', 'class' => 'h2 pt1 block']];
	}

	function diffRecord($table, $oldRecord, $newRecord)
	{
		$ignoredColumns = ['docState', 'docStateMain'];

		$this->prepareArray($oldRecord);
		$this->prepareArray($newRecord);

		$diffData = [];
		$this->arrayDiff($oldRecord, $newRecord, $diffData);
		$t = [];

		if (count($diffData))
		{
			//$h = ['c' => 'Údaj', 'v' => 'Hodnota'];

			foreach ($diffData as $columnId => $columnData)
			{
				if (in_array($columnId, $ignoredColumns))
					continue;

				$colName = $columnId;
				if ($table)
					$colName = $table->columnName($columnId);

				if (isset($columnData['oldValue']))
				{
					$oldRow = ['c' => $colName, 'v' => $columnData['oldValue'], '_options' => ['cellClasses' => ['v' => 'e10-row-minus'], 'rowSpan' => ['c' => 2]]];
					$t[] = $oldRow;
				}
				$newRow = ['c' => $colName, 'v' => $columnData['newValue'], '_options' => ['cellClasses' => ['v' => 'e10-row-plus']]];
				$t[] = $newRow;
			}
		}

		if (count($t))
			return $t;
		return NULL;
	}

	function diffList ($listId)
	{
		$cntOld = isset($this->oldDocData['lists'][$listId]) ? count($this->oldDocData['lists'][$listId]) : 0;
		$cntNew = isset($this->newDocData['lists'][$listId]) ? count($this->newDocData['lists'][$listId]) : 0;
		if (!$cntOld && !$cntNew)
			return;

		$listDefinition = $this->mainTable->listDefinition ($listId);
		$listTable = (isset($listDefinition['table'])) ? $this->app()->table($listDefinition['table']) : NULL;

		$t = [];
		$h = ['c' => 'Údaj', 'v' => 'Hodnota'];
		$usedOld = [];

		// -- changed
		foreach ($this->oldDocData['lists'][$listId] ?? [] as $row)
		{
			$oldRowNdx = $row['ndx'];
			$usedOld[] = $oldRowNdx;

			if (isset($row['value']) && is_array($row['value']))
				continue;

			$newRow = searchArray($this->newDocData['lists'][$listId], 'ndx', $oldRowNdx);
			if ($newRow)
			{
				$diffRow = $this->diffRecord($listTable, $row, $newRow);
				if (!$diffRow)
				{
					continue;
				}

				$t[] = ['c' => '#'.$row['ndx'], '_options' => ['class' => 'e10-bold e10-bg-t9', 'colSpan' => ['c' => 2]]];
				$t = array_merge($t, $diffRow);
			}
			else
			{
				//$newRow = ['c' => $row['name'], 'v' => $row['value'], 'n' => $row['note'], '_options' => ['class' => 'e10-row-minus']];
				//$t[] = $newRow;

				/*
				$diffRow = $this->diffRecord(NULL, [], $row);
				if (!$diffRow)
					continue;
				$t = array_merge($t, $diffRow);
				*/
			}
		}

		// -- new
		foreach ($this->newDocData['lists'][$listId] ?? [] as $newRow)
		{
			$newRowNdx = $newRow['ndx'];
			if (in_array($newRowNdx, $usedOld))
				continue;

		//	if (is_array($newRow['value']))
		//		continue;


			//$row = ['c' => $newRow['name'], 'v' => $newRow['value'], 'n' => $newRow['note'], '_options' => ['class' => 'e10-row-plus']];
			//$t[] = $row;

			$diffRow = $this->diffRecord($listTable, NULL, $newRow);
			if (!$diffRow)
				continue;
			$t[] = ['c' => '#'.$newRow['ndx'], '_options' => ['class' => 'e10-bold e10-row-plus', 'colSpan' => ['c' => 2]]];
			$t = array_merge($t, $diffRow);
		}

		if (count($t))
		{
			$this->diffContent[] = ['table' => $t, 'header' => $h, 'title' => ['text' => $listDefinition['name'], 'class' => 'h2 pt1 block']];
		}
	}

	function diffLists()
	{
		if (!isset($this->oldDocData['lists']) || !count($this->oldDocData['lists']))
			return;

		foreach ($this->oldDocData['lists'] as $listId => $listContent)
		{
			if ($listId === 'properties')
			{
				$this->diffProperties();
				continue;
			}
			if ($listId === 'clsf')
			{
				//$this->diffProperties();
				continue;
			}
			$this->diffList($listId);
		}
	}

	public function run()
	{
		$this->diffRecData();
		$this->diffLists();
	}
}

/*
{
	"recData": {
		"ndx": 16720,
		"company": 0,
		"beforeName": "",
		"firstName": "Luk\u00e1\u0161",
		"middleName": "",
		"lastName": "Adamec",
		"afterName": "",
		"gender": 0,
		"fullName": "Adamec Luk\u00e1\u0161",
		"accountType": 0,
		"accountState": 0,
		"login": "",
		"loginHash": "",
		"roles": "",
		"docState": 4000,
		"docStateMain": 2,
		"complicatedName": 0,
		"id": "16720",
		"personType": 1,
		"language": "",
		"gid": 0
	},
	"lists": {
		"address": [{
			"ndx": 347977,
			"tableid": "e10.persons.persons",
			"recid": 16720,
			"specification": "",
			"street": "N\u00e1m\u011bst\u00ed m\u00edru 12",
			"city": "Zl\u00edn",
			"zipcode": "76001",
			"country": "cz",
			"type": 0,
			"lat": 49.226854,
			"lon": 17.6666883,
			"locState": 1,
			"locTime": {
				"date": "2015-12-19 08:19:54.000000",
				"timezone_type": 3,
				"timezone": "Europe\/Prague"
			},
			"locHash": "2b8a2ace1f3f6fbdad0c7ecd62ae3bf8"
		}],
		"groups": [2, 5, 6],
		"properties": [{
			"rowNumber": 0,
			"ndx": 31001,
			"property": "idcn",
			"name": "\u010c\u00edslo OP",
			"group": "ids",
			"subtype": "",
			"note": "",
			"value": "201725670"
		}, {
			"rowNumber": 1,
			"ndx": 31002,
			"property": "birthdate",
			"name": "Datum narozen\u00ed",
			"group": "ids",
			"subtype": "",
			"note": "",
			"value": {
				"date": "1989-09-22 00:00:00.000000",
				"timezone_type": 3,
				"timezone": "Europe\/Prague"
			}
		}, {
			"rowNumber": 2,
			"ndx": 71376,
			"property": "email",
			"name": "Email",
			"group": "contacts",
			"subtype": "",
			"note": "",
			"value": "eritreas@seznam.cz"
		}, {
			"rowNumber": 3,
			"ndx": 71377,
			"property": "phone",
			"name": "Telefon",
			"group": "contacts",
			"subtype": "",
			"note": "",
			"value": "733662712"
		}, {
			"rowNumber": 4,
			"ndx": 71378,
			"property": "bankaccount",
			"name": "\u010c\u00edslo \u00fa\u010dtu",
			"group": "payments",
			"subtype": "",
			"note": "",
			"value": "2101339694\/2010"
		}],
		"clsf": {
			"places": [],
			"others": [],
			"attTags": [],
			"webParts": [],
			"webSections": [],
			"wkfMessagesTags": [],
			"wkfDocumentsTags": [],
			"wkfProjectsTags": [],
			"witemsTags": [],
			"kbTextsTags": [],
			"documentsTags": [],
			"propertyTags": [],
			"lanDevicesTags": [],
			"lanSwLicensesTags": [],
			"custPresents": []
		},
		"doclinks": [],
		"connections": []
	}
}
 */
