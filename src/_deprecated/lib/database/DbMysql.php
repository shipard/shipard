<?php

namespace lib\database;

use E10\DataModel, E10\utils;


/**
 * Class Database
 * @package lib\database
 */
class DbMysql extends \lib\database\Database
{
	public function loadStructure()
	{
		$this->loadStructure_Tables();
		$this->loadStructure_DbInfo();
	}

	public function loadStructure_Tables()
	{
		$q[] = 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS';
		array_push($q, 'WHERE TABLE_SCHEMA = %s', $this->dbName);

		$rows = $this->db->query ($q);
		foreach ($rows as $r)
		{
			$tableName = $r['TABLE_NAME'];
			$columnName = $r['COLUMN_NAME'];

			if (!isset($this->dbInfo['tables'][$tableName]))
			{
				$this->dbInfo['tables'][$tableName] = ['columns' => []];
			}
			$column = [
					'name' => $columnName, 'type' => $r['DATA_TYPE'], 'len' => $r['CHARACTER_MAXIMUM_LENGTH'],
					'numberPrecision' => $r['NUMERIC_PRECISION'], 'numberDecimals' => $r['NUMERIC_SCALE'],
					'charset' => $r['CHARACTER_SET_NAME'], 'collation' => $r['COLLATION_NAME'],
					'default' => $r['COLUMN_DEFAULT']
			];
			$this->dbInfo['tables'][$tableName]['columns'][$columnName] = $column;
		}

		$rows = $this->db->query ('show table status');
		foreach ($rows as $r)
		{
			$tableName = $r['Name'];
			$info = [
				'collation' => $r['Collation'],
			];
			$this->dbInfo['tables'][$tableName]['info'] = $info;
		}
	}

	public function loadStructure_DbInfo()
	{
		$q[] = 'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME';
		array_push($q, ' FROM INFORMATION_SCHEMA.SCHEMATA ');
		array_push($q, ' WHERE SCHEMA_NAME = %s', $this->dbName);

		$info = $this->db->query ($q)->fetch();

		if ($info)
		{
			$this->dbInfo['database']['charset'] = $info['DEFAULT_CHARACTER_SET_NAME'];
			$this->dbInfo['database']['collation'] = $info['DEFAULT_COLLATION_NAME'];
		}
	}

	function defaultCharset()
	{
		return 'utf8mb4';
	}

	function defaultCollation()
	{
		return 'utf8mb4_general_ci';
	}

	public function checkDb()
	{
		if ($this->dbInfo['database']['charset'] != $this->defaultCharset())
		{
			$cmd = "ALTER DATABASE `".$this->dbName."` CHARACTER SET = ".$this->defaultCharset()." COLLATE = ".$this->defaultCollation();
			$this->addCommand($cmd);
		}
	}

	public function checkTable($tableName)
	{
		if (!isset($this->dbInfo['tables'][$tableName]))
			return;

		if ($this->dbInfo['tables'][$tableName]['info']['collation'] != $this->defaultCollation())
		{
			$hasString = FALSE;
			foreach ($this->dbInfo['tables'][$tableName]['columns'] as $colName => $colInfo)
			{
				if ($colInfo['type'] === 'char')
				{
					$hasString = TRUE;
					break;
				}
			}
			$cmd = 'ALTER TABLE `'.$tableName.'`';
			if ($hasString)
				$cmd .= ' CONVERT TO CHARACTER SET '.$this->defaultCharset();
			$cmd .= ' COLLATE '.$this->defaultCollation();
			$this->addCommand($cmd);
		}
	}

	public function checkTableModelColumn($tableName, $col)
	{
		$columnName = (isset ($col['sql']) ? $col['sql'] : $col['id']);

		$mci = $this->modelColumnInfo($col);
		$dci = $this->dbInfo['tables'][$tableName]['columns'][$columnName];


		if (isset($mci['charset']) && isset($mci['collation']))
		{
			if ($mci['charset'] != $dci['charset'] || $mci['collation'] != $dci['collation'])
			{
				$cmd = 'ALTER TABLE `'.$tableName.'` CHANGE `'.$columnName.'` `'.$columnName.'` '.$mci['sqlColType'].
						' CHARACTER SET '.$mci['charset'].' COLLATE '.$mci['collation'];
				if (array_key_exists('default', $mci))
				{
					if (is_string($mci['default']))
						$cmd .= ' DEFAULT ""';
					else
						$cmd .= 'DEFAULT ' . $mci['default'];
				}

				$this->addCommand($cmd);
			}
		}

		if (array_key_exists('default', $dci) && array_key_exists('default', $mci))
		{
			if (
					$mci['default'] != $dci['default'] ||
					($dci['default'] === NULL && $mci['default'] !== $dci['default'])
			)
			{
				$cmd = 'ALTER TABLE `' . $tableName . '` CHANGE `' . $columnName . '` `' . $columnName . '` ' . $mci['sqlColType'];
				if (isset($mci['charset']))
					$cmd .= ' CHARACTER SET '.$mci['charset'].' COLLATE '.$mci['collation'];
				if (is_string($mci['default']))
					$cmd .= ' DEFAULT ""';
				else
					$cmd .= 'DEFAULT '.$mci['default'];

				$this->addCommand($cmd);
			}
		}
	}

	function modelColumnInfo ($col)
	{
		$info = [];

		$sqlColName = (isset ($col['sql']) ? $col['sql'] : $col['id']);

		$ascii = FALSE;
		if (isset($col['options']) && in_array('ascii', $col['options']))
			$ascii = TRUE;

		// -- charset & collation
		if ($col['type'] === 'string')
		{
			if ($ascii)
			{
				$info['charset'] = 'ascii';
				$info['collation'] = 'ascii_bin';
			}
			else
			{
				$info['charset'] = $this->defaultCharset();
				$info['collation'] = $this->defaultCollation();
			}
		}
		elseif ($col['type'] === 'enumString' || $col['type'] === 'time')
		{
			$info['charset'] = 'ascii';
			$info['collation'] = 'ascii_bin';
		}

		// -- columnType
		$enumIntType = 'TINYINT DEFAULT 0';
		if (isset($col['len']) && $col['len'] === 2)
			$enumIntType = 'SMALLINT DEFAULT 0';
		elseif (isset($col['len']) && $col['len'] === 4)
			$enumIntType = 'INT DEFAULT 0';
		$colTypes = [
				"string" => 'CHAR', "short" => 'SMALLINT UNSIGNED DEFAULT 0', "int" => 'INT DEFAULT 0', "long" => 'BIGINT DEFAULT 0',
				"money" => "NUMERIC", "number" => "NUMERIC",
				"date" => 'DATE', "timestamp" => 'DATETIME', 'time' => 'CHAR', 'timeLen' => 'INT DEFAULT 0',
				'memo' => 'MEDIUMTEXT', 'code' => 'MEDIUMTEXT', 'subColumns' => 'MEDIUMTEXT',
				"int_ai" => "INT AUTO_INCREMENT NOT NULL PRIMARY KEY",
				"enumInt" => $enumIntType,
				"enumString" => 'CHAR', "logical" => 'TINYINT DEFAULT 0'
		];

		$sqlColName = (isset ($col['sql']) ? $col['sql'] : $col['id']);

		$sqlColType = '';
		//$sqlColType .= '`'.$sqlColName.'` ';
		$sqlColType .= $colTypes [$col['type']];

		switch ($col['type'])
		{
			case 'string': $sqlColType .= "({$col['len']}) "; $info['default'] = ''; break;
			case 'enumString': $sqlColType .= "({$col['len']}) "; $info['default'] = ''; break;
			case 'money': $sqlColType .= '(12, 2) DEFAULT 0.0'; $info['default'] = 0.0; break;
			case 'number': $sqlColType .= "(12, {$col['dec']}) DEFAULT 0.0"; $info['default'] = 0.0; break;
			case 'time': $sqlColType .= "(5)"; $info['default'] = ''; break;
		}

		$info['sqlColType'] = $sqlColType;

		return $info;
	}

	public function optimizeTable($tableName)
	{
		$cmd = 'SET @innodb_defragment := 1';
		$this->addCommand($cmd);
		$cmd = 'OPTIMIZE TABLE `'.$tableName.'`';
		$this->addCommand($cmd);
		$this->addCommand('FLUSH TABLES');
	}
}
