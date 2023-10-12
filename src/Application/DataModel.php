<?php

namespace Shipard\Application;
use \Shipard\Utils\Utils;


class DataModel
{
	CONST ctString = 1, ctInt = 2, ctDate = 3, ctMoney = 4, ctTimeStamp = 5, ctMemo = 6,
				ctEnumInt = 7, ctEnumString = 8, ctLogical = 9, ctNumber = 10, ctLong = 11, ctCode = 12,
				ctTime = 14, ctSubColumns = 15, ctTimeLen = 16, ctShort = 17;

	CONST coMandatory 				= 0x0001,
				coSaveOnChange 			= 0x0002,
				coEnumMultiple 			= 0x0004,
				coAscii				 			= 0x0008,
				coScanner						= 0x0010,
				coComputed					= 0x0020,
				coUserInput					= 0x0040,
				coCheckOnChange			= 0x0080
				;

	CONST
				toConfigSource 			= 0x0001,
				toTimelineSource			= 0x0002,
				toDisableCopyRecords	= 0x0004,
				toSystemTable				= 0x0008,
				toNotifications			= 0x0010;

	static $ctStringTypes = [
		'string' => DataModel::ctString, 'int' => DataModel::ctInt, 'long' => DataModel::ctLong,
		'money' => DataModel::ctMoney, 'number' => DataModel::ctNumber,
		'date' => DataModel::ctDate, 'timestamp' => DataModel::ctTimeStamp, 'time' => DataModel::ctTime, 'timeLen' => DataModel::ctTimeLen,
		'memo' => DataModel::ctMemo, 'code' => DataModel::ctCode, 'subColumns' => DataModel::ctSubColumns,
		'int_ai' => DataModel::ctInt,
		'enumInt' => DataModel::ctEnumInt, 'enumString' => DataModel::ctEnumString,
		'logical' => DataModel::ctLogical,
		'short' => DataModel::ctShort,
	];


	var $model = array ();

	public function __construct($data = NULL)
	{
		if ($data)
			$this->model = $data;
	}

	public function addModule ($moduleId, $moduleName)
	{
		if (!isset ($this->model ['modules'][$moduleId]))
			$this->model ['modules'][$moduleId] = $moduleName;
	}

	public function addTable ($id, $table)
	{
		$this->model ['tables'][$id] = $table;

		if ($table['ndx'])
			$this->model ['tablesByNdx'][$table['ndx']] = $id;
	}

	public function column ($table, $column)
	{
		if (isset ($this->model ['tables'][$table]['cols'][$column]))
			return $this->model ['tables'][$table]['cols'][$column];
		//error_log ("column '$column' not found in table '$table'");
		return NULL;
	}

	public function columnName ($table, $column)
	{
		return $this->model ['tables'][$table]['cols'][$column]['name'];
	}

	public function columns ($table)
	{
		if (isset ($this->model ['tables'][$table]['cols']))
			return $this->model ['tables'][$table]['cols'];
		return NULL;
	}

	public function formDefinition ($table, $formId)
	{
		if (isset ($this->model ['tables'][$table]['forms'][$formId]))
			return $this->model ['tables'][$table]['forms'][$formId];
		error_log ("form '$formId' not found in table '$table'");
		return NULL;
	}

	public function listDefinition ($table, $listId)
	{
		if (!$listId)
		{ // no listId --> return all lists
			if (isset ($this->model ['tables'][$table]['lists']))
				return $this->model ['tables'][$table]['lists'];
			return NULL;
		}

		if (isset ($this->model ['tables'][$table]['lists'][$listId]))
			return $this->model ['tables'][$table]['lists'][$listId];
		error_log ("list '$listId' not found in table '$table'");
		return NULL;
	}

	public function module ($moduleId)
	{
		if (!isset ($this->model ['modules'][$moduleId]))
			return FALSE;

		return $this->model ['modules'][$moduleId];
	}

	public function tableNdx ($tableId)
	{
		if (isset ($this->model ['tables'][$tableId]))
			return $this->model ['tables'][$tableId]['ndx'];

		return 0;
	}

	public function tableProperty ($table, $property)
	{
		if (isset ($this->model ['tables'][$table->tableId()]))
		{
			$tableDef = $this->model ['tables'][$table->tableId()];
			if (isset ($tableDef [$property]))
				return $tableDef [$property];
		}
		return false;
	}

	public function tables ()
	{
		return $this->model ['tables'];
	}

	public function table ($tableId)
	{
		if (isset ($this->model ['tables'][$tableId]))
			return $this->model ['tables'][$tableId];

		if ($tableId === '_TblAppOptions')
		{
			return [
				'id' => '_TblAppOptions', 'name' => 'Nastavení', 'columns' => [],
  			'views' =>  ['default' => ['id' => 'default', 'title' => 'Nastavení']]
			];
		}

		return FALSE;
	}

	public function viewDefinition ($table, $viewId)
	{
		if (isset ($this->model ['tables'][$table]['views'][$viewId]))
			return $this->model ['tables'][$table]['views'][$viewId];

		$tableDef = $this->table($table);
		if ($tableDef)
		{
			if (isset ($tableDef['views'][$viewId]))
				return $tableDef['views'][$viewId];

			if (isset($tableDef['views']))
			{
				$vd = Utils::searchArray($tableDef['views'], 'class', $viewId);
				if ($vd !== NULL)
					return $vd;
			}
		}

		//error_log ("view '$viewId' not found in table '$table'");
		return NULL;
	}

}
