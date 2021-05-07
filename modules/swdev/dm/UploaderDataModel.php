<?php


namespace swdev\dm;

use e10\E10ApiObject, \e10\utils, \e10\json;


/**
 * Class UploaderDataModel
 * @package swdev\dm
 */
class UploaderDataModel extends E10ApiObject
{
	/** @var \swdev\dm\TableModules */
	var $tableModules;
	/** @var \swdev\dm\TableTables */
	var $tableTables;
	/** @var \swdev\dm\TableColumns */
	var $tableColumns;
	/** @var \swdev\dm\TableViewers */
	var $tableViewers;
	/** @var \swdev\dm\TableDMTrTexts */
	var $tableDMTrTexts;

	/** @var \swdev\dm\TableEnums */
	var $tableEnums;
	/** @var \swdev\dm\TableEnumsValues */
	var $tableEnumsValues;

	var $UILangs;

	var $uploadedNdx = 0;

	public function init ()
	{
		$this->tableModules = $this->app()->table('swdev.dm.modules');
		$this->tableTables = $this->app()->table('swdev.dm.tables');
		$this->tableColumns = $this->app()->table('swdev.dm.columns');
		$this->tableViewers = $this->app()->table('swdev.dm.viewers');
		$this->tableDMTrTexts = $this->app()->table('swdev.dm.dmTrTexts');

		$this->tableEnums = $this->app()->table('swdev.dm.enums');
		$this->tableEnumsValues = $this->app()->table('swdev.dm.enumsValues');

		$this->UILangs = $this->app()->cfgItem('swdev.tr.lang.ui', []);
	}

	function checkUpdateField ($srcKey, $srcData, $dstKey, $dstData, &$updateFields)
	{
		if (isset($srcData[$srcKey]) && isset($dstData[$dstKey]) && $srcData[$srcKey] === $dstData[$dstKey])
			return;

		if (!isset($srcData[$srcKey]) && !isset($updateFields[$dstKey]))
			return;

		if (!isset($srcData[$srcKey]) && isset($updateFields[$dstKey]))
		{
			unset($updateFields[$dstKey]);
			return;
		}

		$updateFields[$dstKey] = $srcData[$srcKey];
	}

	function upload()
	{
		if ($this->requestParams['type'] === 'module')
			$this->uploadModule();
		elseif ($this->requestParams['type'] === 'table')
			$this->uploadTable();
		elseif ($this->requestParams['type'] === 'enums')
			$this->uploadEnums();

		return TRUE;
	}

	function uploadEnums()
	{
		$data = $this->requestParams['data'];
		if (!isset($data['lang']))
			$data['lang'] = 6;
		$srcLanguage = $data['lang'];

		foreach ($this->requestParams['data'] as $enumId => $enumData)
		{
			$exist = $this->db()->query('SELECT * FROM [swdev_dm_enums] WHERE [id] = %s', $enumId)->fetch();
			if (!$exist)
			{
				$config = ['textsIds' => $enumData['textsIds']];
				$item = [
					'id' => $enumId, 'name' => $enumData['name'], 'srcLanguage' => $srcLanguage,
					'config' => json::lint($config),
					'docState' => 4000, 'docStateMain' => 2
				];
				$ndx = $this->tableEnums->dbInsertRec($item);
				if ($ndx < 10000)
				{
					$newNdx = $ndx + 9999;
					$this->db()->query ('UPDATE [swdev_dm_enums] SET ndx = %i', $newNdx, ' WHERE [ndx] = %i', $ndx);
					$ndx = $newNdx;
				}
				$this->tableEnums->docsLog($ndx);
				$this->uploadEnums_Texts($ndx, $enumData, $srcLanguage);
				continue;
			}

			$r = $exist->toArray();
			$item = [];
			$this->checkUpdateField ('columnId', $enumData, 'columnId', $r, $item);
			$this->checkUpdateField ('name', $enumData, 'name', $r, $item);
			$config = ['textsIds' => $enumData['textsIds']];
			$configText = json::lint($config);
			if ($configText !== $r['config'])
				$item['config'] = $configText;

			if (count($item))
			{
				$item['ndx'] = $r['ndx'];
				$ndx = $this->tableEnums->dbUpdateRec($item);
				$this->tableEnums->docsLog($ndx);
			}

			$this->uploadEnums_Texts($r['ndx'], $enumData, $srcLanguage);
		}
	}

	function uploadEnums_Texts($enumNdx, $enumData, $srcLanguage)
	{
		if (!isset($enumData['texts']) || !count($enumData['texts']))
			return;

		foreach ($enumData['texts'] as $valueId => $texts)
		{
			foreach ($texts as $columnId => $text)
			{
				$exist = $this->db()->query('SELECT * FROM [swdev_dm_enumsValues] WHERE [enum] = %i', $enumNdx,
					' AND [value] = %s', strval($valueId), ' AND [columnId] = %s', $columnId)->fetch();

				$enumDataStr = json::lint($enumData['data'][$valueId]);

				if (!$exist)
				{
					$item = [
						'enum' => $enumNdx, 'value' => $valueId, 'columnId' => $columnId, 'text' => $text,
						'data' => $enumDataStr,
						'srcLanguage' => $srcLanguage, 'docState' => 4000, 'docStateMain' => 2
					];
					$ndx = $this->tableEnumsValues->dbInsertRec($item);
					if ($ndx < 100000)
					{
						$newNdx = $ndx + 99999;
						$this->db()->query ('UPDATE [swdev_dm_enumsValues] SET ndx = %i', $newNdx, ' WHERE [ndx] = %i', $ndx);
						$ndx = $newNdx;
					}
					$this->tableEnumsValues->docsLog($ndx);

					continue;
				}

				if ($exist['text'] === $text && $enumDataStr === $exist['data'])
					continue;

				$item = ['ndx' => $exist['ndx'], 'text' => $text, 'data' => $enumDataStr];
				$ndx = $this->tableEnumsValues->dbUpdateRec($item);
				$this->tableEnumsValues->docsLog($ndx);
			}
		}
	}

	function uploadModule()
	{
		$data = $this->requestParams['data'];
		if (!isset($data['lang']))
			$data['lang'] = 6;
		$srcLanguage = $data['lang'];

		$moduleId = $this->requestParams['data']['id'];

		$exist = $this->db()->query('SELECT * FROM [swdev_dm_modules] WHERE [id] = %s', $moduleId)->fetch();
		if (!$exist)
		{
			$item = [
				'id' => $data['id'], 'name' => $data['name'], 'srcLanguage' => $srcLanguage,
				'docState' => 4000, 'docStateMain' => 2
			];
			$ndx = $this->tableModules->dbInsertRec($item);
			if ($ndx < 1000)
			{
				$newNdx = $ndx + 999;
				$this->db()->query ('UPDATE [swdev_dm_modules] SET ndx = %i', $newNdx, ' WHERE [ndx] = %i', $ndx);
				$ndx = $newNdx;
			}
			$this->tableModules->docsLog($ndx);
			$this->uploadedNdx = $ndx;

			return;
		}

		$r = $exist->toArray();
		$item = [];
		$this->checkUpdateField ('id', $data, 'id', $r, $item);
		$this->checkUpdateField ('name', $data, 'name', $r, $item);
		$this->checkUpdateField ('lang', $data, 'srcLanguage', $r, $item);

		if (count($item))
		{
			$item['ndx'] = $r['ndx'];
			$ndx = $this->tableModules->dbUpdateRec($item);
			$this->tableModules->docsLog($ndx);
		}
	}

	function uploadTable()
	{
		$data = $this->requestParams['data'];
		if (!isset($data['lang']))
			$data['lang'] = 6;
		$srcLanguage = $data['lang'];

		$tableId = $this->requestParams['data']['id'];

		$exist = $this->db()->query('SELECT * FROM [swdev_dm_tables] WHERE [id] = %s', $tableId)->fetch();
		if (!$exist)
		{
			$item = [
				'id' => $data['id'], 'name' => $data['name'], 'sql' => $data['sql'], 'srcLanguage' => $srcLanguage,
				'icon' => isset($data['icon']) ? $data['icon'] : '',
				'docState' => 4000, 'docStateMain' => 2
			];

			$ndx = $this->tableTables->dbInsertRec($item);
			if ($ndx < 1000)
			{
				$newNdx = $ndx + 999;
				$this->db()->query ('UPDATE [swdev_dm_tables] SET ndx = %i', $newNdx, ' WHERE [ndx] = %i', $ndx);
				$ndx = $newNdx;
			}
			$this->checkSrcDMTrText(['type' => 0, 'lang' => $data['lang'], 'table' => $ndx, 'text' => $item['name']]);
			$this->addTableColumns($data, $ndx);
			$this->addTableViewers($data, $ndx);
			$this->tableTables->docsLog($ndx);
			$this->uploadedNdx = $ndx;

			return;
		}

		$r = $exist->toArray();
		$item = [];
		$this->checkUpdateField ('id', $data, 'id', $r, $item);
		$this->checkUpdateField ('name', $data, 'name', $r, $item);
		$this->checkUpdateField ('icon', $data, 'icon', $r, $item);
		$this->checkUpdateField ('sql', $data, 'sql', $r, $item);
		$this->checkUpdateField ('lang', $data, 'srcLanguage', $r, $item);

		if (count($item))
		{
			$item['ndx'] = $r['ndx'];
			$ndx = $this->tableTables->dbUpdateRec($item);
			$this->tableTables->docsLog($ndx);
		}

		$this->checkSrcDMTrText(['type' => 0, 'lang' => $data['lang'], 'table' => $r['ndx'], 'text' => $data['name']]);

		$this->addTableColumns($data, $r['ndx']);
		$this->addTableViewers($data, $r['ndx']);
	}

	function addTableColumns($tableData, $tableNdx)
	{
		foreach ($tableData['columns'] as $col)
		{
			$columnId = $col['id'];
			$columnLabel = isset($col['label']) ? $col['label'] : '';

			$exist = $this->db()->query('SELECT * FROM [swdev_dm_columns] WHERE [id] = %s', $columnId, ' AND [table] = %i', $tableNdx)->fetch();
			if (!$exist)
			{
				$item = [
					'table' => $tableNdx, 'id' => $col['id'], 'name' => $col['name'], 'label' => $columnLabel,
					'jsonDef' => json::lint($col),
					'colTypeId' => $col['type'],
					'colTypeReferenceId' => isset($col['reference']) ? $col['reference'] : '',
					'colTypeEnumId' => isset($col['enumCfg']['cfgItem']) ? $col['enumCfg']['cfgItem'] : '',
					'colTypeLen' => isset($col['len']) ? $col['len'] : 0,
					'colTypeDec' => isset($col['dec']) ? $col['dec'] : 0,
				];

				$ndx = $this->tableColumns->dbInsertRec($item);
				if ($ndx < 100000)
				{
					$newNdx = $ndx + 100000;
					$this->db()->query ('UPDATE [swdev_dm_columns] SET ndx = %i', $newNdx, ' WHERE [ndx] = %i', $ndx);
					$ndx = $newNdx;
				}

				$this->checkSrcDMTrText([
					'type' => 1, 'lang' => $tableData['lang'],
					'table' => $tableNdx, 'column' => $ndx['ndx'],
					'text' => $item['name']
				]);
				$this->checkSrcDMTrText([
					'type' => 2, 'lang' => $tableData['lang'],
					'table' => $tableNdx, 'column' => $ndx['ndx'],
					'text' => $item['label']
				]);

				$this->tableColumns->docsLog($ndx);
			}
			else
			{
				$r = $exist->toArray();
				$item = [];
				$this->checkUpdateField ('id', $col, 'id', $r, $item);
				$this->checkUpdateField ('name', $col, 'name', $r, $item);
				$this->checkUpdateField ('label', $col, 'label', $r, $item);

				$this->checkUpdateField ('type', $col, 'colTypeId', $r, $item);
				$this->checkUpdateField ('reference', $col, 'colTypeReferenceId', $r, $item);
				$this->checkUpdateField ('len', $col, 'colTypeLen', $r, $item);
				$this->checkUpdateField ('dec', $col, 'colTypeDec', $r, $item);

				if (isset($col['enumCfg']['cfgItem']) && $col['enumCfg']['cfgItem'] !== $r['colTypeEnumId'])
					$item['colTypeEnumId'] = $col['enumCfg']['cfgItem'];

				$jsonDef = json::lint($col);
				if ($r['jsonDef'] !== $jsonDef)
					$item['jsonDef'] = $jsonDef;

				if (count($item))
				{
					$item['ndx'] = $r['ndx'];
					$item['table'] = $r['table'];
					$ndx = $this->tableColumns->dbUpdateRec($item);
					$this->tableColumns->docsLog($ndx);
				}

				$this->checkSrcDMTrText([
					'type' => 1, 'lang' => $tableData['lang'],
					'table' => $tableNdx, 'column' => $r['ndx'],
					'text' => $r['name']
				]);
				$this->checkSrcDMTrText([
					'type' => 2, 'lang' => $tableData['lang'],
					'table' => $tableNdx, 'column' => $r['ndx'],
					'text' => $r['label']
				]);

			}
		}
	}

	function addTableViewers($tableData, $tableNdx)
	{
		if (!isset($tableData['views']))
			return;

		foreach ($tableData['views'] as $viewer)
		{
			$viewerClassId = $viewer['class'];

			$exist = $this->db()->query('SELECT * FROM [swdev_dm_viewers] WHERE [classId] = %s', $viewerClassId, ' AND [table] = %i', $tableNdx)->fetch();
			if (!$exist)
			{
				$item = ['table' => $tableNdx, 'id' => $viewer['id'], 'classId' => $viewerClassId];

				$ndx = $this->tableViewers->dbInsertRec($item);
				if ($ndx < 10000)
				{
					$newNdx = $ndx + 10000;
					$this->db()->query ('UPDATE [swdev_dm_viewers] SET ndx = %i', $newNdx, ' WHERE [ndx] = %i', $ndx);
					$ndx = $newNdx;
				}
				$this->tableViewers->docsLog($ndx);
			}
			else
			{
				$r = $exist->toArray();
				$item = [];
				$this->checkUpdateField ('id', $viewer, 'id', $r, $item);
				$this->checkUpdateField ('class', $viewer, 'classId', $r, $item);

				if (count($item))
				{
					$item['ndx'] = $r['ndx'];
					$item['table'] = $r['table'];
					$ndx = $this->tableViewers->dbUpdateRec($item);
					$this->tableViewers->docsLog($ndx);
				}
			}
		}
	}

	function checkSrcDMTrText($textData)
	{
		if (!isset($textData['text']) || $textData['text'] === '')
			return;

		$q[] = 'SELECT * FROM [swdev_dm_dmTrTexts] WHERE 1';
		array_push($q, ' AND [textType] = %i', $textData['type']);
		array_push($q, ' AND [lang] = %i', $textData['lang']);
		array_push($q, ' AND [isSource] = %i', 1);
		array_push($q, ' AND [table] = %i', $textData['table']);
		if ($textData['type'] == 1 || $textData['type'] == 2)
			array_push($q, ' AND [column] = %i', $textData['column']);

		$exist = $this->db()->query($q)->fetch();

		if ($exist)
		{
			$srcItem = $exist->toArray();
			$this->checkDstDMTrText($exist['ndx'], $textData);
		}
		else
		{
			$srcItem = [
				'textType' => $textData['type'], 'lang' => $textData['lang'], 'isSource' => 1,
				'text' => $textData['text'],
				'table' => $textData['table'],
				'docState' => 4000, 'docStateMain' => 2
			];
			if ($textData['type'] == 1 || $textData['type'] == 2)
				$srcItem['column'] = $textData['column'];

			$ndx = $this->tableDMTrTexts->dbInsertRec($srcItem);
			$srcItem = $this->tableDMTrTexts->loadItem($ndx);
			$this->checkDstDMTrText($ndx, $textData);
		}
	}

	function checkDstDMTrText($srcText, $textData)
	{
		foreach ($this->UILangs as $uiLang)
		{
			if ($uiLang == $textData['lang'])
				continue;

			$q = [];
			array_push($q, 'SELECT * FROM [swdev_dm_dmTrTexts] WHERE 1');
			array_push($q, ' AND [textType] = %i', $textData['type']);
			array_push($q, ' AND [lang] = %i', $uiLang);
			array_push($q, ' AND [isSource] = %i', 0);
			array_push($q, ' AND [table] = %i', $textData['table']);
			if ($textData['type'] == 1 || $textData['type'] == 2)
				array_push($q, ' AND [column] = %i', $textData['column']);

			$exist = $this->db()->query($q)->fetch();

			if ($exist)
			{
				$srcItem = $exist->toArray();
			}
			else
			{
				$srcItem = [
					'textType' => $textData['type'], 'lang' => $uiLang, 'isSource' => 0,
					'text' => '', 'srcText' => $srcText,
					'table' => $textData['table'],
					'docState' => 1000, 'docStateMain' => 0
				];
				if ($textData['type'] == 1 || $textData['type'] == 2)
					$srcItem['column'] = $textData['column'];

				$ndx = $this->tableDMTrTexts->dbInsertRec($srcItem);
				$srcItem = $this->tableDMTrTexts->loadItem($ndx);
			}

		}
	}

	public function createResponseContent($response)
	{
		$this->init();

		if ($this->requestParams['operation'] === 'upload')
		{
			if ($this->upload())
			{
				$response->add('success', 1);
				if ($this->uploadedNdx)
					$response->add('uploadedNdx', $this->uploadedNdx);

				return;
			}
		}

		//error_log ("----UPLOADER !{$this->requestParams['operation']}! !{$this->requestParams['type']}!");

		$response->add ('success', 1);
		//$response->add ('rowsHtmlCode', $this->code);
	}
}
