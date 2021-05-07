<?php

namespace lib\database;

use E10\DataModel, E10\utils;


/**
 * Class Database
 * @package lib\database
 */
class Database
{
	var $db;
	var $dbName;
	var $model = NULL;

	var $dbInfo = ['tables' => [], 'database' => []];
	var $repairCommands = [];


	public function addCommand($cmd)
	{
		$this->repairCommands[] = $cmd;
	}

	public function setDb ($db, $dbName)
	{
		$this->db = $db;
		$this->dbName = $dbName;
	}

	public function setModel ($model)
	{
		$this->model = $model;
	}

	public function repairModel($run = FALSE)
	{
		if (!$this->model)
			return;

		$this->checkDb();

		foreach ($this->model as $table)
		{
			$this->checkTable($table ['sql']);

			forEach ($table ['columns'] as $col)
			{
				$this->checkTableModelColumn($table ['sql'], $col);
			}
		}

		$this->runCommands($run);
	}

	public function optimizeModel($run = FALSE)
	{
		if (!$this->model)
			return;

		foreach ($this->model as $table)
		{
			$this->optimizeTable($table ['sql']);
		}

		$this->runCommands($run);
	}


	protected function runCommands($run = FALSE)
	{
		if (!count($this->repairCommands))
			return;

		echo "COMMANDS: #".count($this->repairCommands).":\n";
		foreach ($this->repairCommands as $cmd)
		{
			if ($run)
			{
				echo ".";
				$this->db->query ($cmd);
			}
			else
			{
				echo $cmd."\n";
			}
		}
		echo "\n";
	}

	public function checkDb() {}
	public function checkTable($tableName) {}
	public function checkTableModelColumn($tableName, $col) {}
	function defaultCharset() { return ''; }
	function defaultCollation() { return ''; }
	public function loadStructure() {}
	public function optimizeTable($tableName) {}
	public function repairDatabase () {}
}
