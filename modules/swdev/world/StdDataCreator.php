<?php


namespace swdev\world;

use e10\Utility, \e10\utils, \e10\DataModel;


/**
 * Class StdDataCreator
 * @package swdev\world
 */
class StdDataCreator extends Utility
{
	/** @var \e10\DbTable */
	var $exportTable;
	var $exportRowNumber = 0;

	var $exportFileName;
	var $exportData = '';

	var $usedCountries = [];
	var $usedLanguages = [];
	var $usedCurrencies = [];

	var $dataLanguages = [];
	var $dataLanguagesNdxs = [];

	function setExportTable ($exportTableId)
	{
		$this->flushData();
		$this->exportTable = $this->app()->table($exportTableId);
		$this->exportData .= $this->exportDeleteCmd();
	}

	function addExportRow($r)
	{
		if ($this->exportRowNumber === 0)
			$this->exportData .= $this->exportInsertCmd();

		$colValues = [];

		foreach ($this->exportTable->columns() as $key => $colDef)
		{
			$value = $r[$key];
			$this->addExportColumn($key, $value, $colValues);
		}

		if ($this->exportRowNumber)
			$this->exportData .= ",\n";

		$this->exportData .= '(' . implode(',', $colValues) . ')';

		$this->exportRowNumber++;
	}

	function addExportColumn ($key, $value, &$dest)
	{
		$colInfo = $this->exportTable->column($key);
		switch ($colInfo['type'])
		{
			case DataModel::ctString:
				$dest[] =$this->exportColumnValueString($value);
				break;
			case DataModel::ctDate:
				$dest[] =$this->exportColumnValueDate($value);
				break;
			case DataModel::ctLogical:
				$dest[] = ($value) ? '1' : '0';
				break;
			default:
				$dest[] = strval($value);
		}
	}

	function doCountries()
	{
		$this->setExportTable('e10.world.countries');

		$q[] = 'SELECT * FROM [swdev_world_countries]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
			$this->usedCountries[] = $r['ndx'];
		}

		$this->doCountries_Languages();
		$this->doCountries_Currencies();
		$this->doCountries_Tr();
	}

	function doCountries_Languages()
	{
		$this->setExportTable('e10.world.countryLanguages');

		$q[] = 'SELECT * FROM [swdev_world_countryLanguages]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [country] IN %in', $this->usedCountries);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
			$this->usedLanguages[] = $r['language'];
		}
	}

	function doCountries_Currencies()
	{
		$this->setExportTable('e10.world.countryCurrencies');

		$q[] = 'SELECT * FROM [swdev_world_countryCurrencies]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [country] IN %in', $this->usedCountries);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
		}
	}

	function doCountries_Tr()
	{
		$this->setExportTable('e10.world.countriesTr');

		$q[] = 'SELECT * FROM [swdev_world_countriesTr]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [country] IN %in', $this->usedCountries);
		array_push ($q, ' AND [language] IN %in', $this->dataLanguagesNdxs);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
		}
	}

	function doCurrencies()
	{
		$this->setExportTable('e10.world.currencies');

		$q[] = 'SELECT * FROM [swdev_world_currencies]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
			$this->usedCurrencies[] = $r['ndx'];
		}

		$this->doCurrencies_Tr();
	}

	function doCurrencies_Tr()
	{
		$this->setExportTable('e10.world.currenciesTr');

		$q[] = 'SELECT * FROM [swdev_world_currenciesTr]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [currency] IN %in', $this->usedCurrencies);
		array_push ($q, ' AND [language] IN %in', $this->dataLanguagesNdxs);
		array_push ($q, ' ORDER BY [currency], [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
		}
	}

	function doLanguages()
	{
		$this->setExportTable('e10.world.languages');

		$q[] = 'SELECT * FROM [swdev_world_languages]';
		array_push ($q, ' WHERE 1');
		//array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' AND [ndx] IN %in', $this->usedLanguages);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
		}

		$this->doLanguages_Tr();
	}

	function doLanguages_Tr()
	{
		$this->setExportTable('e10.world.languagesTr');

		$q[] = 'SELECT * FROM [swdev_world_languagesTr]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [languageSrc] IN %in', $this->usedLanguages);
		array_push ($q, ' AND [languageDst] IN %in', $this->dataLanguagesNdxs);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$row = $r->toArray();
			$this->addExportRow($row);
		}
	}

	public function run ()
	{
		$this->initFile();
		$this->initTranslation();

		$this->doCountries();
		$this->doCurrencies();
		$this->doLanguages();
		$this->flushData();
	}

	function exportDeleteCmd()
	{
		$c = 'DELETE FROM `'.$this->exportTable->sqlName().'`;'."\n";
		return $c;
	}

	function exportInsertCmd()
	{
		$c = 'INSERT INTO `'.$this->exportTable->sqlName().'` VALUES '."\n";
		return $c;
	}

	function exportColumnValueDate ($v)
	{
		$val = '';
		if ($v)
			$val .= "'".$v->format('Y-m-d')."'";
		else
			$val .= 'NULL';
		return $val;
	}

	function exportColumnValueString ($v)
	{
		$val = '';
		if ($v)
		{
			$v = str_replace("\\", "\\\\", trim($v));
			$val .= "'" . str_replace("'", "\\'", $v) . "'";
			//$val .= json_encode($v);
		}
		else
			$val .= "''";
		return $val;
	}

	function initFile()
	{
		$this->exportFileName = __APP_DIR__.'/tmp/world-mysql.sql';
		file_put_contents($this->exportFileName, '');
	}

	function flushData()
	{
		if ($this->exportRowNumber)
			$this->exportData .= ";\n";

		file_put_contents($this->exportFileName, $this->exportData, FILE_APPEND);
		$this->exportData = '';
		$this->exportRowNumber = 0;
	}

	function initTranslation ()
	{
		//$this->dataLanguages = ['cs', 'de', 'en', 'es', 'et', 'fi', 'fr', 'hr', 'it', 'ja', 'nl', 'pt', 'ru', 'sk', 'zh'];
		$this->dataLanguages = ['cs', 'de', 'en', 'es', 'et', 'fi', 'fr', 'hr', 'it', 'nl', 'pt', 'ru', 'sk'];
		$this->dataLanguagesNdxs = [];

		$q[] = 'SELECT * FROM [swdev_world_languages]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [alpha2] IN %in', $this->dataLanguages);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$this->dataLanguagesNdxs[] = $r['ndx'];
		}
	}
}