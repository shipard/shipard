#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once __APP_DIR__ . '/e10-modules/e10/server/php/e10-cli.php';
use \E10\CLI\Application, \e10\str;


/**
 * Class ImportFdbApp
 */
class ImportFdbApp extends Application
{
	var $db = NULL;
	var $fileName;

	function connectDb($dbId = NULL)
	{
		$dbPassword = 'masterkey';
		$dbUser = 'sysdba';

		$dboptions = [
			'driver'   => 'firebird',
			'username' => $dbUser,
			'password' => $dbPassword,
			'database' => 'localhost:/home/sebik/ispop/test.fdb',
			'charset'  => 'UTF-8',
			'resultDetectTypes' => TRUE
		];

		try
		{
			$this->db = new \DibiConnection ($dboptions);
		}
		catch (DibiException $e)
		{
			$this->err (get_class ($e) . ': ' . $e->getMessage());
			return FALSE;
		}

		return TRUE;
	}

	function createFile ()
	{
		$this->fileName = __APP_DIR__.'/res.sql';
		file_put_contents($this->fileName, '');
	}

	function valueStr ($v, $first = FALSE, $maxLen = FALSE)
	{
		if ($maxLen !== FALSE)
			$v = str::upToLen($v, $maxLen);

		$val = ($first) ? '' : ', ';
		if ($v)
		{
			$v = str_replace("\\", "\\\\", trim($v));
			$val .= "'" . str_replace("'", "\\'", $v) . "'";
		}
		else
			$val .= "''";

		return $val;
	}

	function valueDate ($v, $first = FALSE)
	{
		$val = ($first) ? '' : ',';
		if ($v)
			$val .= "'".$v->format('Y-m-d')."'";
		else
			$val .= 'NULL';
		return $val;
	}

	function import_res ()
	{
		$q = 'SELECT r.ICO, r.ZUJ, r.NAZEV, r.ULICE, r.OBEC, r.PSC, r.FORMA, r.OKEC6A, r.DATZAN, r.DATVZN, r.SPECENO FROM RES r';
		$rows = $this->db->query ($q);

		$insert = 'INSERT INTO `services_subjregs_cz_res_plus_res` VALUES ';
		$buf = $insert;

		$bs = 5000;
		$cnt = 0;
		$totalCnt = 0;
		foreach ($rows as $r)
		{
			if ($cnt)
				$buf .= ",\n";

			$totalCnt++;
			$buf .= '(';
			$buf .= $totalCnt;
			$buf .= $this->valueStr($r['ICO']);
			$buf .= $this->valueStr($r['ZUJ']);
			$buf .= $this->valueStr($r['NAZEV'], FALSE, 240);
			$buf .= $this->valueStr($r['ULICE']);
			$buf .= $this->valueStr($r['OBEC']);
			$buf .= $this->valueStr($r['PSC']);
			$buf .= $this->valueStr($r['FORMA']);
			$buf .= $this->valueStr($r['OKEC6A']);
			$buf .= $this->valueDate($r['DATZAN']);
			$buf .= $this->valueDate($r['DATVZN']);
			$buf .= ', '.$r['SPECENO'];
			$buf .= ')';

			$cnt++;
			if ($cnt > $bs)
			{
				$buf .= ";\n";
				file_put_contents($this->fileName, $buf, FILE_APPEND);
				$buf = $insert;
				$cnt = 0;
				echo '.';
				//break;
			}
		}

		if ($cnt)
		{
			$buf .= ";\n";
			file_put_contents($this->fileName, $buf, FILE_APPEND);
		}

		echo "\nRES TOTAL: $totalCnt\n";
	}


	function import_rzpProv ()
	{
		$q = 'SELECT r.ID_PROVOZ, r.ICO, r.ICP, r.ULICE, r.OBEC, r.PSC, r.ZUJ FROM RZP_PROVOZ r';
		$rows = $this->db->query ($q);

		$insert = 'INSERT INTO `services_subjregs_cz_res_plus_rzpProvoz` VALUES ';
		$buf = $insert;

		$bs = 5000;
		$cnt = 0;
		$totalCnt = 0;
		foreach ($rows as $r)
		{
			if ($cnt)
				$buf .= ",\n";

			$totalCnt++;
			$buf .= '(';
			$buf .= $totalCnt;
			$buf .= $this->valueStr($r['ID_PROVOZ']);
			$buf .= $this->valueStr($r['ICO']);
			$buf .= $this->valueStr($r['ICP']);
			$buf .= $this->valueStr($r['ULICE']);
			$buf .= $this->valueStr($r['OBEC']);
			$buf .= $this->valueStr($r['PSC']);
			$buf .= $this->valueStr($r['ZUJ']);
			$buf .= ')';

			$cnt++;
			if ($cnt > $bs)
			{
				$buf .= ";\n";
				file_put_contents($this->fileName, $buf, FILE_APPEND);
				$buf = $insert;
				$cnt = 0;
				echo '.';
				//break;
			}
		}

		if ($cnt)
		{
			$buf .= ";\n";
			file_put_contents($this->fileName, $buf, FILE_APPEND);
		}

		echo "\nRZP_PROVOZ TOTAL: $totalCnt\n";
	}

	function import_rzp_subjekt ()
	{
		$q = 'SELECT r.ICO, r.NAZEV, r.SIDLO_ULICE, r.SIDLO_OBEC, r.SIDLO_PSC FROM RZP_SUBJEKT r';
		$rows = $this->db->query ($q);

		$insert = 'INSERT INTO `services_subjregs_cz_res_plus_rzpSubj` VALUES ';
		$buf = $insert;

		$bs = 5000;
		$cnt = 0;
		$totalCnt = 0;
		$maxLen = 0;
		$maxLenName = '';
		foreach ($rows as $r)
		{
			$len = mb_strlen($r['NAZEV'], 'UTF-8');
			if ($len > $maxLen)
			{
				$maxLen = $len;
				$maxLenName = $r['NAZEV'];
			}

			if ($cnt)
				$buf .= ",\n";

			$totalCnt++;
			$buf .= '(';
			$buf .= $totalCnt;
			$buf .= $this->valueStr($r['ICO']);
			$buf .= $this->valueStr($r['NAZEV']);
			$buf .= $this->valueStr($r['SIDLO_ULICE']);
			$buf .= $this->valueStr($r['SIDLO_OBEC']);
			$buf .= $this->valueStr($r['SIDLO_PSC']);
			$buf .= ')';

			$cnt++;
			if ($cnt > $bs)
			{
				$buf .= ";\n";
				file_put_contents($this->fileName, $buf, FILE_APPEND);
				$buf = $insert;
				$cnt = 0;
				echo '.';
			}
		}

		if ($cnt)
		{
			$buf .= ";\n";
			file_put_contents($this->fileName, $buf, FILE_APPEND);
		}

		echo "\nRES TOTAL: $totalCnt; maxLen = $maxLen\n";
	}


	function run ()
	{
		if (!$this->connectDb())
			return;

		$this->createFile();
		$this->import_res();
		$this->import_rzpProv();
		$this->import_rzp_subjekt();
	}
}


$app = new ImportFdbApp($argv);
$app->run();

