<?php

namespace demo\documents\libs;
use \e10\utils;


/**
 * Class Document
 * @package lib\demo
 */
class Document extends \demo\core\libs\Task
{
	var $tableId = '';
	var $defaultValues = [];
	var $data = ['rec' => []];
	var $newNdx = 0;
	var $pkg;


	public function init ($taskDef, $taskTypeDef)
	{
		parent::init($taskDef, $taskTypeDef);
	}

	protected function applyTaskQuery ($columnId, &$q, $src = NULL)
	{
		$key = $columnId;
		$qryDef = NULL;
		if ($src)
		{
			if (isset($src[$key]))
				$qryDef = $src[$key];
		}
		else
		{
			if (isset ($this->taskDef[$key]))
				$qryDef = $this->taskDef[$key];
			elseif (isset ($this->taskTypeDef[$key]))
				$qryDef = $this->taskTypeDef[$key];
		}
		if (!$qryDef)
			return;

		foreach ($qryDef as $qryColumnId => $qryColumnValue)
		{
			if (is_array($qryColumnValue))
				array_push($q, ' AND ['.$qryColumnId.'] IN %in', $qryColumnValue);
			elseif (is_string($qryColumnValue))
				array_push($q, ' AND ['.$qryColumnId.'] = %s', $qryColumnValue);
			else
				array_push($q, ' AND ['.$qryColumnId.'] = %i', $qryColumnValue);
		}
	}

	protected function cntMinMax ($key)
	{
		$v = 0;

		$valueDef = NULL;
		if (isset ($this->taskDef[$key]))
			$valueDef = $this->taskDef[$key];
		elseif (isset ($this->taskTypeDef[$key]))
			$valueDef = $this->taskTypeDef[$key];

		if (!$valueDef)
			return $v;

		if (is_int($valueDef))
			return $valueDef;

		if (is_array($valueDef) && isset($valueDef['min']) && isset($valueDef['max']))
			$v = mt_rand($valueDef['min'], $valueDef['max']);

		return $v;
	}


	public function create()
	{
	}

	public function save()
	{
		$ds = ['name' => '', 'table' => $this->tableId, 'defaultValues' => $this->defaultValues, 'data' => [$this->data]];
		$this->pkg = ['name' => 'test1', 'datasets' => [$ds]];


		$installer = new \lib\DataPackageInstaller ($this->app());
		$installer->installPackage($this->pkg);
		$this->newNdx = $installer->datasetPrimaryKeys[0];
	}

	public function run()
	{
		return FALSE;
	}
}
