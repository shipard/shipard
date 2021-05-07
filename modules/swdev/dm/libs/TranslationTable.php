<?php

namespace swdev\dm\libs;

use \e10\Utility, \e10\json;


/**
 * Class TranslationTable
 * @package swdev\dm\libs
 */
class TranslationTable extends Utility
{
	var $tableNdx = 0;

	var $allLanguages;
	var $userLanguages;
	var $srcLanguageNdx = 6;
	var $srcLanguage;
	var $dstLanguageNdx = 0;
	var $tableColumns = [];
	var $trTexts = [];

	var $dsClasses = [
		1000 => 'e10-docstyle-concept',
		1200 => 'e10-docstyle-halfdone',
		4000 => 'e10-docstyle-done',
		8000 => 'e10-docstyle-edit',
	];

	public function init ()
	{
		$this->userLanguages = $this->app()->cfgItem('swdev.tr.translators.'.$this->app()->userNdx(), []);
		$this->allLanguages = $this->app()->cfgItem ('swdev.tr.lang.langs', []);
		$this->srcLanguage = $this->allLanguages[$this->srcLanguageNdx];

		if (!count($this->userLanguages))
			$this->userLanguages[] = 1;
	}

	public function setTableNdx($tableNdx, $dstLanguageNdx = 0)
	{
		$this->tableNdx = $tableNdx;
		$this->dstLanguageNdx = $dstLanguageNdx;
	}

	function loadTrTexts()
	{
		$q[] = 'SELECT * FROM [swdev_dm_dmTrTexts]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [table] = %i', $this->tableNdx);
		if ($this->dstLanguageNdx)
			array_push($q, ' AND [lang] = %i', $this->dstLanguageNdx);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['textType'] == 0)
				$this->trTexts['table'][$r['lang']][0] = ['t' => $r['text'], 'pk' => $r['ndx'], 'ds' => $r['docState']];
			elseif ($r['textType'] == 1 || $r['textType'] == 2)
				$this->trTexts['cols'][$r['column']][$r['lang']][$r['textType']] = ['t' => $r['text'], 'pk' => $r['ndx'], 'ds' => $r['docState']];
		}
	}

	function loadColumns()
	{
		$q[] = 'SELECT * FROM [swdev_dm_columns] AS [cols]';
		array_push($q, ' WHERE [cols].[table] = %i', $this->tableNdx);
		array_push($q, ' ORDER BY [cols].ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'id' => $r['id'],
				'name' => $r['name'], 'label' => (($r['label']) ? $r['label'] : ''),
			];

			$this->tableColumns[$r['ndx']] = $item;
		}
	}

	public function saveTableTrData ($tableNdx, $dstLanguageNdx)
	{
		$this->init();
		$this->setTableNdx($tableNdx, $dstLanguageNdx);
		$this->loadColumns();
		$this->loadTrTexts();

		$trData = ['table' => [], 'columns' => []];

		// -- table
		foreach ($this->trTexts['table'] as $langNdx => $langTexts)
		{
			foreach ($langTexts as $textType => $text)
			{
				if ($text['t'] === '')
					continue;
				if ($textType === 0)
					$trData['table']['name'] = $text['t'];
			}
		}

		// -- columns
		foreach ($this->trTexts['cols'] as $columnNdx => $columnTexts)
		{
			if (!isset($this->tableColumns[$columnNdx]['id']))
				continue;
			$colId = $this->tableColumns[$columnNdx]['id'];
			foreach ($columnTexts as $langNdx => $langTexts)
			{
				foreach ($langTexts as $textType => $text)
				{
					if ($text['t'] === '')
						continue;
					if ($textType === 1)
						$trData['columns'][$colId]['name'] = $text['t'];
					elseif ($textType === 2)
						$trData['columns'][$colId]['label'] = $text['t'];
				}
			}
		}

		$exist = $this->db()->query('SELECT * FROM [swdev_dm_tablesTrData] WHERE [table] = %i', $this->tableNdx,
			' AND [lang] = %i', $this->dstLanguageNdx)->fetch();

		$trDataStr = json::lint($trData);
		$trDataStrCheckSum = sha1($trDataStr);
		if ($exist)
		{
			$update = ['data' => $trDataStr, 'checksum' => $trDataStrCheckSum];
			$this->db()->query('UPDATE [swdev_dm_tablesTrData] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
		}
		else
		{
			if (count($trData['columns']))
			{
				$newItem = [
					'data' => $trDataStr, 'checksum' => $trDataStrCheckSum,
					'table' => $this->tableNdx, 'lang' => $this->dstLanguageNdx
				];
				$this->db()->query('INSERT INTO [swdev_dm_tablesTrData]  ', $newItem);
			}
		}
	}

	public function updateTableTrData()
	{
		$tables = $this->db()->query('SELECT DISTINCT [table], [lang] FROM [swdev_dm_dmTrTexts] WHERE [lang] != %i', 6);
		foreach ($tables as $t)
		{
			$this->tableColumns = [];
			$this->trTexts = [];

			$this->saveTableTrData ($t['table'], $t['lang']);
		}
	}

	public function updateDictsTrData()
	{
		$dicts = $this->db()->query('SELECT * FROM [swdev_translation_dicts] WHERE [docState] = %i', 4000);
		foreach ($dicts as $d)
		{
			$dictsIdentifiers = [];
			$dictsTexts = [];
			$dictsNdxs = [];

			// -- dict items
			$dictNdx = 0;
			$rows = $this->db()->query('SELECT * FROM [swdev_translation_dictsItems] WHERE [dict] = %i', $d['ndx'], ' ORDER BY ndx');
			foreach ($rows as $r)
			{
				$dictsIdentifiers[$dictNdx] = $r['identifier'];
				$dictsTexts[$dictNdx] = $r['text'];
				$dictsNdxs[$dictNdx] = $r['ndx'];

				$dictNdx++;
			}
			if (!$dictNdx)
				continue;

			$classCode = $this->dictsTrData_ClassCode($d, $dictsIdentifiers);
			$classCodeCheckSum = sha1($classCode);

			// -- save class code
			$exist = $this->db()->query('SELECT * FROM [swdev_translation_dictsTrData] WHERE [dict] = %i', $d['ndx'],
				' AND [lang] = %i', 0)->fetch();
			if ($exist)
			{
				$update = ['data' => $classCode, 'checksum' => $classCodeCheckSum];
				$this->db()->query('UPDATE [swdev_translation_dictsTrData] SET ', $update, ' WHERE ndx = %i', $exist['ndx']);
			}
			else
			{
				$newItem = ['data' => $classCode, 'checksum' => $classCodeCheckSum, 'dict' => $d['ndx'], 'lang' => 0];
				$this->db()->query('INSERT INTO [swdev_translation_dictsTrData] ', $newItem);
			}

			// -- dicts items translations
			$uiLanguages = $this->app()->cfgItem('swdev.tr.lang.ui', []);
			$uiLanguages[] = 6;
			foreach ($uiLanguages as $uiLanguage)
			{
				$trData = [];
				foreach ($dictsNdxs as $identifierNdx => $itemNdx)
				{
					$trItem = $this->db()->query('SELECT * FROM [swdev_translation_dictsItemsTr] WHERE [dictItem] = %i', $itemNdx, ' AND [lang] = %i', $uiLanguage)->fetch();
					if ($trItem)
						$trData[] = $trItem['text'];
					else
						$trData[] = $dictsTexts[$identifierNdx];
				}

				if (!count($trData))
					continue;

				$trDataStr = json::lint($trData);
				$trDataCheckSum = sha1($trDataStr);
				// -- save tr data
				$exist = $this->db()->query('SELECT * FROM [swdev_translation_dictsTrData] WHERE [dict] = %i', $d['ndx'],
					' AND [lang] = %i', $uiLanguage)->fetch();
				if ($exist)
				{
					$update = ['data' => $trDataStr, 'checksum' => $trDataCheckSum];
					$this->db()->query('UPDATE [swdev_translation_dictsTrData] SET ', $update, ' WHERE ndx = %i', $exist['ndx']);
				}
				else
				{
					$newItem = ['data' => $trDataStr, 'checksum' => $trDataCheckSum, 'dict' => $d['ndx'], 'lang' => $uiLanguage];
					$this->db()->query('INSERT INTO [swdev_translation_dictsTrData] ', $newItem);
				}
			}
		}
	}
	
	function dictsTrData_ClassCode($dictRecData, $identifiers)
	{
		$c = '';
		$c .= "<?php\n\n";

		$idParts = explode('.', $dictRecData['identifier']);
		$className = array_pop($idParts);


		$c .= "namespace translation\\dicts\\".implode("\\", $idParts).";\n";
		$c .= "use \\e10\\Application, \\e10\\utils;\n\n";
		$c .= "class ".$className."\n";
		$c .= "{\n";
		$c .= "\t static".'$'."path = __APP_DIR__.'/e10-modules/translation/dicts/".implode("/", $idParts)."';\n";
		$c .= "\t static".'$'."baseFileName = '$className';\n";
		$c .= "\t static".'$'."data = NULL;\n\n";

		$c .= "\t\tconst\n";
		foreach ($identifiers as $idNdx => $id)
		{
			$c .= "\t\t\t";
			if ($idNdx)
				$c .= ",";
			else
				$c .= " ";
			$c .= $id." = ".$idNdx."\n";
		}
		$c .= "\t;\n\n";

		$c .= "
	static function init()
	{
		if (self::\$data)
			return;

		\$langId = Application::\$userLanguageCode;
		\$fn = self::\$path.'/'.self::\$baseFileName.'.'.\$langId.'.data';
		\$strData = file_get_contents(\$fn);
		self::\$data = unserialize(\$strData);
	}

	static function text(\$id)
	{
		self::init();
		return self::\$data[\$id];
	}

	static function es(\$id)
	{
		self::init();
		return utils::es(self::\$data[\$id]);
	}
";

		$c .= "}\n";

		return $c;
	}

	public function updateEnumsTrData()
	{
		$enums = $this->db()->query('SELECT * FROM [swdev_dm_enums] WHERE [docState] = %i', 4000);
		foreach ($enums as $enum)
		{
			$enumValues = [];

			// -- enum values
			$rows = $this->db()->query('SELECT * FROM [swdev_dm_enumsValues] WHERE [enum] = %i', $enum['ndx'], ' ORDER BY ndx');
			foreach ($rows as $r)
			{
				$enumValueNdx = $r['ndx'];
				$enumValue = $r['value'];
				$columnId = $r['columnId'];

				$enumValues[$enumValueNdx] = ['value' => $enumValue, 'columnId' => $columnId, ];
			}
			if (!count($enumValues))
				continue;


			// -- enum values translations
			$uiLanguages = $this->app()->cfgItem('swdev.tr.lang.ui', []);
			$uiLanguages[] = 6;
			foreach ($uiLanguages as $uiLanguage)
			{
				$trData = [];
				$rowsTr = $this->db()->query('SELECT * FROM [swdev_dm_enumsValuesTr] WHERE [enumValue] IN %in', array_keys($enumValues), ' AND [lang] = %i', $uiLanguage);
				foreach ($rowsTr as $rTr)
				{
					$ev = $enumValues[$rTr['enumValue']];
					$trData[$ev['value']][$ev['columnId']] = $rTr['text'];
				}

				if (!count($trData))
					continue;

				$trDataStr = json::lint($trData);
				$trDataCheckSum = sha1($trDataStr);
				// -- save tr data
				$exist = $this->db()->query('SELECT * FROM [swdev_dm_enumsTrData] WHERE [enum] = %i', $enum['ndx'],
					' AND [lang] = %i', $uiLanguage)->fetch();
				if ($exist)
				{
					$update = ['data' => $trDataStr, 'checksum' => $trDataCheckSum];
					$this->db()->query('UPDATE [swdev_dm_enumsTrData] SET ', $update, ' WHERE ndx = %i', $exist['ndx']);
				}
				else
				{
					$newItem = ['data' => $trDataStr, 'checksum' => $trDataCheckSum, 'enum' => $enum['ndx'], 'lang' => $uiLanguage];
					$this->db()->query('INSERT INTO [swdev_dm_enumsTrData] ', $newItem);
				}
			}
		}
	}
}
