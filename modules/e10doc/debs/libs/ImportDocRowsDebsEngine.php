<?php

namespace e10doc\debs\libs;
use \e10\Utility, e10\DataModel, e10\utils;


/**
 * Class ImportDocRowsDebsEngine
 * @package e10doc\debs\libs
 */
class ImportDocRowsDebsEngine extends Utility
{
	var $dstDocNdx = 0;
	var $dstDocRecData = NULL;

	/** @var  \e10\DbTable */
	var $tableHeads;
	/** @var  \e10\DbTable */
	var $tableRows;

	var $fileNames = NULL;

	var $srcText = '';
	var $srcFileName = '';
	var $srcDataHeader;
	var $srcDataColumnsInfo;
	var $srcDataRows;

	var $dstDataRows =[];


	var $colDelimiter = ',';

	var $cacheColValues = [];

	var $previewTableHeader = [
		'#' => '#', 'item' => 'Položka', 'text' => 'Text', 'debit' => ' MD', 'credit' => ' DAL',
		'person' => 'Osoba', 'dateDue' => 'Splatnost', 'currency' => 'Měna',
	];

	function init()
	{
		$this->tableHeads = $this->app->table ('e10doc.core.heads');
		$this->tableRows = $this->app()->table('e10doc.core.rows');
	}

	function setDstDocNdx($dstDocNdx)
	{
		$this->dstDocNdx = intval($dstDocNdx);
		if (!$this->dstDocNdx)
		{

			return;
		}

		$this->dstDocRecData = $this->tableHeads->loadItem ($this->dstDocNdx);
	}

	function setFileNames($fileNames)
	{
		$this->fileNames = $fileNames;
	}

	public function parse()
	{
		foreach ($this->fileNames as $oneFile)
		{
			$this->srcFileName = $oneFile;
			$fn = __APP_DIR__ .'/'.$oneFile;
			$this->srcText = file_get_contents($fn);
			if (!$this->srcText || $this->srcText === '')
			{

				continue;
			}

			$this->parseSrcText();
		}
	}

	function parseSrcText()
	{
		$this->srcDataRows = preg_split("/\\r\\n|\\r|\\n/", $this->srcText);

		$row = array_shift ($this->srcDataRows);
		$this->srcDataHeader = str_getcsv($row, $this->colDelimiter);

		$this->checkColumnsIds();

		while (1)
		{
			$row = array_shift ($this->srcDataRows);
			if ($row === NULL)
				break;
			if ($row === '')
				continue;

			$cols = str_getcsv($row, $this->colDelimiter);

			$dataItem = [];

			foreach ($cols as $colNdx => $colValue)
			{
				if (!$this->srcDataColumnsInfo[$colNdx])
					continue;

				$this->finalColumnValue($colNdx, $colValue, $dataItem);
			}

			if (count($dataItem))
			{
				$this->dstDataRows[$this->srcFileName][] = $dataItem;
			}
		}
	}

	function checkColumnsIds()
	{
		$humanNames = [
			'VS' => 'symbol1', 'SS' => 'symbol2', 'KS' => 'symbol3',
			'MD' => 'debit', 'DAL' => 'credit',
			'Měna' => 'currency',
			'Účet' => 'item',
			'Text' => 'text',
			'Splatnost' => 'dateDue',

			'IČ' => ['id' => 'person', 'property' => 'oid'],
			'E-mail' => ['id' => 'person', 'property' => 'email'],
		];

		foreach ($this->srcDataHeader as $colNdx => $colName)
		{
			$colId = $colName;
			$colSearchInfo = NULL;

			if (isset($humanNames[$colName]))
			{
				if (is_string($humanNames[$colName]))
					$colId = $humanNames[$colName];
				else
				{
					$colId = $humanNames[$colName]['id'];
					$colSearchInfo = $humanNames[$colName];
				}
			}

			$colInfo = $this->tableRows->column($colId);
			if ($colInfo)
			{
				$colInfo['id'] = $colId;
				if ($colSearchInfo !== NULL)
					$colInfo['searchInfo'] = $colSearchInfo;
				$this->srcDataColumnsInfo[$colNdx] = $colInfo;
				continue;
			}

			$this->srcDataColumnsInfo[$colNdx] = NULL;
		}
	}

	function finalColumnValue($colNdx, $colValue, &$dataItem)
	{
		$colInfo = $this->srcDataColumnsInfo[$colNdx];
		if (!$colInfo)
			return;

		switch ($colInfo['id'])
		{
			case 'item': $this->finalColumnValue_Item($colInfo, $colValue,$dataItem); return;
			case 'person': $this->finalColumnValue_Person($colInfo, $colValue,$dataItem); return;
			case 'currency': $this->finalColumnValue_Currency($colInfo, $colValue,$dataItem); return;
		}

		$this->columnValueFromString($colInfo, $colValue,$dataItem);
	}

	function finalColumnValue_Person($colInfo, $colValue, &$dataItem)
	{
		if (isset($colInfo['searchInfo']))
		{
			if (isset($dataItem['person']) && $dataItem['person'] != 0)
				return;

			if (trim($colValue) === '')
				return;

			$propertyId = $colInfo['searchInfo']['property'];

			$q[] = 'SELECT props.recid FROM e10_base_properties AS props';
			array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON props.recid = persons.ndx AND props.tableid = %s', 'e10.persons.persons');
			array_push ($q, ' WHERE 1');
			array_push ($q, ' AND [property] = %s', $propertyId);
			array_push ($q, ' AND [valueString] = %s', $colValue);
			array_push ($q, ' AND persons.[docState] != %i', 9800);

			$exist = $this->db()->query($q)->fetch();
			//error_log (\dibi::$sql);
			if ($exist)
			{
				$dataItem[$colInfo['id']] = $exist['recid'];
			}
		}
	}

	function finalColumnValue_Item($colInfo, $colValue, &$dataItem)
	{
		$exist = $this->db()->query('SELECT * FROM [e10_witems_items] WHERE [id] = %s', $colValue, ' AND [docState] = %i', 4000)->fetch();
		if ($exist)
		{
			$dataItem[$colInfo['id']] = $exist['ndx'];
			return;
		}
	}

	function finalColumnValue_Currency($colInfo, $colValue, &$dataItem)
	{
		$colId = $colInfo['id'];

		$s = strtolower($colValue);
		$dataItem[$colId] = $s;
	}

	function columnValueFromString($colInfo, $colValue, &$dataItem)
	{
		$colType = $colInfo['type'];
		$colId = $colInfo['id'];

		$v = NULL;

		if ($colType === DataModel::ctMoney || $colType === DataModel::ctNumber)
		{
			$s = str_replace(' ', '', $colValue);
			$s = str_replace(' ', '', $s); // nbsp
			$s = str_replace(',', '.', $s);
			$v = floatval($s);
		}
		elseif ($colType === DataModel::ctLong || $colType === DataModel::ctShort)
		{
			$s = str_replace(' ', '', $colValue);
			$v = floatval($s);
		}
		elseif ($colType === DataModel::ctDate)
		{
			if (utils::dateIsValid($colValue, 'd.m.Y'))
				$v = new \DateTime($colValue);
		}
		elseif ($colType === DataModel::ctString)
		{
			$v = strval($colValue);
		}

		if ($v !== NULL)
		{
			$dataItem[$colId] = $v;
		}
	}

	public function import()
	{
		$this->db()->query('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $this->dstDocNdx);

		$f = $this->tableHeads->getTableForm ('edit', $this->dstDocNdx);

		foreach ($this->dstDataRows as $fileName => $rows)
		{
			forEach ($rows as $r)
			{
				if (isset($r['currency']) && $r['currency'] !== '' && $r['currency'] !== $f->recData['currency'])
					continue;

				$r['document'] = $this->dstDocNdx;
				$this->tableRows->dbInsertRec($r, $f->recData);
			}
		}

		if ($f->checkAfterSave())
			$this->tableHeads->dbUpdateRec ($f->recData);
	}
}
