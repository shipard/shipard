<?php

namespace e10\install\libs;

use E10\DataModel, E10\utils;


/**
 * Class DataPackageInstaller
 * @package e10\install\libs
 */
class DataPackageInstaller extends \E10\Utility
{
	var $packageId = '';
	protected $pkgFileName = '';
	public $primaryKeys = [];
	public $dataSetPrimaryKeys = [];

	public $cntAttachments = 0;

	public function installDataPackage ($packageId)
	{
		$this->packageId = $packageId;
		$this->pkgFileName = __SHPD_MODULES_DIR__.$packageId.'.json';

		$pkg = $this->loadCfgFile($this->pkgFileName);
		if ($pkg === FALSE)
			return;

		$installed = $this->app()->db()->query('SELECT * FROM [e10_install_packages] WHERE [packageId] = %s', $packageId)->fetch();
		if (!$installed)
		{
			$item = [
				'packageId' => $packageId, 'packageVersion' => '0.2.1',
				'packageCheckSum' => sha1_file($this->pkgFileName)
			];

			$this->db()->query('INSERT INTO [e10_install_packages] ', $item);
		}

		$this->installPackage_AppOptions ($pkg);
		$this->installPackage_Includes ($pkg);
		$this->installPackage_Datasets ($pkg);
	}

	public function cleanUp ()
	{
		if ($this->cntAttachments)
		{
			passthru ('chown -R '.utils::wwwUser().':'.utils::wwwGroup().' att');
			passthru ('chown -R '.utils::wwwUser().' imgcache');
		}
	}

	public function installPackage_AppOptions ($pkg)
	{
		if (!isset($pkg['appOptions']))
			return;

		$tableAppOptions = new \E10\TblAppOptions ($this->app);

		forEach ($pkg['appOptions'] as $mainId => $options)
		{
			$o = $this->app->cfgItem ('appOptions.'.$mainId);
			$fileName = $tableAppOptions->appOptionFileName ($mainId, $o);
			if (is_file($fileName))
				$cfg = utils::loadCfgFile($fileName);
			else
				$cfg = [];
			if ($cfg === FALSE)
				continue;

			foreach ($options as $optionId => $optionValue)
			{
				$cfg[$optionId] = $optionValue;
			}
			file_put_contents($fileName, utils::json_lint (json_encode ($cfg)));
		}

		$this->upgradeApp();
	}

	public function installPackage_Datasets ($pkg)
	{
		if (!isset($pkg['datasets']))
			return;
		forEach ($pkg['datasets'] as $ds)
		{
			if (isset($ds['checkModule']) && $this->app->dataModel->module($ds['checkModule']) === FALSE)
				continue;
			if (isset ($ds['data']))
				$this->installPackage_Datasets_Data ($ds);
			if (isset ($ds['commands']))
				$this->installPackage_Datasets_Commands ($ds);
		}
	}

	public function installPackage_Datasets_Data ($ds)
	{
		$table = $this->app->table ($ds['table']);

		if ($table === NULL)
		{
			echo "!!! Unknown tableId '{$ds['table']}'!\n";
			return;
		}

		$this->dataSetPrimaryKeys = [];
		forEach ($ds['data'] as $dataItem)
		{
			$newRec = $dataItem['rec'];
			$pkName = '';
			if (isset ($newRec['#']))
			{
				$pkName = $newRec['#'];
				unset ($newRec['#']);
			}

			if (isset($ds['defaultValues']))
				$this->setDefaults ($newRec, $ds['defaultValues']);

			// -- replace #xxx from primary keys
			$this->checkColumnValues($table, $newRec);
			$this->checkPrimaryKeys($table, $newRec);

			if ($this->checkExistence ($ds, $table, $newRec, $pkName))
				continue;

			// -- "head" record
			$table->checkNewRec ($newRec);

			// memo columns
			if (isset ($dataItem['recMemos']))
			{
				foreach ($dataItem['recMemos'] as $memoColId => $memoColRows)
					$newRec[$memoColId] = implode($memoColRows);
			}

			// insert
			$newItemNdx = $table->dbInsertRec($newRec);
			$newRec['ndx'] = $newItemNdx;
			if ($pkName !== '')
				$this->primaryKeys[$pkName] = $newItemNdx;

			// -- set default lists
			if (isset ($ds['defaultLists']))
			{
				foreach ($ds['defaultLists'] as $dlId => $dlContent)
				{
					if (isset($dataItem[$dlId]))
						$dataItem[$dlId] = array_merge($dataItem[$dlId], $dlContent);
					else
						$dataItem[$dlId] = $dlContent;
				}
			}

			// -- lists records
			foreach ($dataItem as $dipId => $dipContent)
			{
				if ($dipId === 'rec' || $dipId === 'recMemos')
					continue;

				if ($dipId === 'docLinks')
				{
					$this->addDocLinks($newRec, $table, $dipContent);
					continue;
				}

				if ($dipId === 'attachments')
				{
					$this->addAttachments($newRec, $table, $dipContent);
					continue;
				}

				$listDefinition = $table->listDefinition ($dipId);
				if ($listDefinition === NULL)
				{
					$this->err("Invalid list '$dipId' in file '{$this->pkgFileName}'");
					continue;
				}
				$listObject = $this->app->createObject ($listDefinition ['class']);

				$listTable = NULL;
				if (isset ($listDefinition['table']))
					$listTable = $this->app->table ($listDefinition['table']);

				$listData = [];
				foreach ($dipContent as $lr)
				{
					$rd = $lr;
					$rd['ndx'] = 0;
					$this->checkColumnValues($table, $newRec);
					$this->checkPrimaryKeys($listTable, $rd);
					$listData [] = $rd;
				}

				$listObject->setRecData ($table, $dipId, $newRec);
				$listObject->saveData ($listData);
			}

			// -- "close" document
			$docStatesDef = $this->app->model()->tableProperty ($table, 'states');
			if ($docStatesDef)
			{
				$f = $table->getTableForm ('edit', $newItemNdx);

				if (!isset($f->recData[$docStatesDef['stateColumn']]))
					$f->recData[$docStatesDef['stateColumn']] = 4000;
				if (!isset($f->recData[$docStatesDef['mainStateColumn']]))
					$f->recData[$docStatesDef['mainStateColumn']] = 2;

				if ($f->checkAfterSave())
					$table->dbUpdateRec ($f->recData);

				$f->checkAfterSave();
				$table->checkDocumentState ($f->recData);
				$table->dbUpdateRec ($f->recData);
				$table->checkAfterSave2 ($f->recData);

				$table->docsLog ($f->recData['ndx']);
			}

			$this->dataSetPrimaryKeys[] = $newItemNdx;
		}
	}

	public function addAttachments ($ownerRecData, $ownerTable, $attachments)
	{
		foreach ($attachments as $att)
		{
			if (isset($att['fileName']))
			{
				\E10\Base\addAttachments($this->app, $ownerTable->tableId(), $ownerRecData['ndx'], 'e10-modules/' . $att['fileName'], '');
				$this->cntAttachments++;
			}
		}
	}

	public function addDocLinks ($ownerRecData, $ownerTable, $docLinks)
	{
		$tableDocLinks = $this->app->table ('e10.base.doclinks');
		foreach ($docLinks as $dl)
		{
			$newDocLink = $dl;
			$newDocLink['srcRecId'] = $ownerRecData['ndx'];
			$newDocLink['srcTableId'] = $ownerTable->tableId();
			if (!isset ($newDocLink['dstTableId']))
			{
				$newDocLink['dstTableId'] = 'e10.persons.persons';
			}
			$this->checkPrimaryKeys($tableDocLinks, $newDocLink);
			$this->db()->query ('INSERT INTO [e10_base_doclinks]', $newDocLink);
		}
	}

	public function installPackage_Datasets_Commands ($ds)
	{
		forEach ($ds['commands'] as $cmd)
		{
			if (isset($cmd['class']))
			{
				$object = $this->app()->createObject($cmd['class']);
				if (!$object)
				{

				}
				else
				{
					$object->run();
					unset($object);
				}
				continue;
			}
			if ($cmd['command'] === 'upgradeApp')
			{
				$this->upgradeApp();
				continue;
			}
			else
				if ($cmd['command'] === 'disableNotifications')
				{
					$this->app->params['cntfDisabled'] = 1;
					continue;
				}
				else
					if ($cmd['command'] === 'enableNotifications')
					{
						if (isset($this->app->params['cntfDisabled']))
							unset ($this->app->params['cntfDisabled']);
						continue;
					}

			$params = isset ($cmd['params']) ? $cmd['params'] : [];
			$params['dataPackageInstaller'] = $this;
			$this->app->callFunction ($cmd['command'], $params);
		}
	}

	public function installPackage_Includes ($pkg)
	{
		if (!isset ($pkg['includes']))
			return;

		foreach ($pkg['includes'] as $packageId)
		{
			$this->installDataPackage($packageId);
		}
	}

	function checkColumnValues($table, &$recData)
	{
		if (!$table)
			return;
		foreach ($recData as $colId => $colValue)
		{
			$colDef = $table->column($colId);
			if ($colDef['type'] === DataModel::ctDate && is_string($colValue))
			{
				if ($colValue[0] === '+' || $colValue[0] === '-')
				{
					$recData[$colId] = new \DateTime(date('Ymd', strtotime($colValue)));
				}
			}
		}
	}

	public function checkExistence ($ds, $table, $rec, $pkName)
	{
		if (!isset($ds['checkExistence']))
			return FALSE;

		$q[] = 'SELECT * FROM '.$table->sqlName().' WHERE 1';

		foreach ($ds['checkExistence']['qryColumns'] as $colId)
		{
			array_push($q, ' AND ['.$colId.'] = ?', $rec[$colId]);
		}

		$exist = $this->db()->query($q)->fetch();
		if ($exist)
		{
			if ($pkName !== '' && isset($exist['ndx']))
				$this->primaryKeys[$pkName] = $exist['ndx'];
			return TRUE;
		}

		return FALSE;
	}

	function checkPrimaryKeys($table, &$recData)
	{
		if (!$table)
			return;
		foreach ($recData as $colId => $colValue)
		{
			$colDef = $table->column ($colId);
			if ($colDef['type'] !== DataModel::ctInt && $colDef['type'] !== DataModel::ctEnumInt)
				continue;
			if (!is_string($colValue))
				continue;
			if ($colValue !== '' && $colValue[0] === '#')
			{
				$id = substr($colValue, 1);
				if (isset ($this->primaryKeys[$id]))
					$recData[$colId] = $this->primaryKeys[$id];
				else
				{
					$recData[$colId] = 0;
					error_log("ERROR: primary key '{$id}' not found\n");
				}
			}
			elseif ($colValue !== '' && $colValue[0] === '@')
			{
				$dstTableId = '';
				$dstCol = 'id';
				$dstValue = substr($colValue, 1);
				$dstParts = explode(':', $dstValue);
				if (count($dstParts) >= 2)
				{
					$dstCol = $dstParts[0];
					$dstValue = substr($colValue, strlen($dstCol) + 2);
				}
				$dstColParts = explode(';', $dstCol);
				if (count($dstColParts) === 2)
				{
					$dstTableId = $dstColParts[0];
					$dstCol = $dstColParts[1];
				}

				if ($dstTableId === '' && !isset($colDef['reference']) && $colId !== 'ndx')
				{
					error_log ("BAD REFERENCE: column {$colId} / table {$dstTableId}; ".json_encode($recData));
					continue;
				}

				if ($dstTableId !== '')
					$tableRef = $this->app->table($dstTableId);
				elseif ($colId === 'ndx')
					$tableRef = $table;
				else
					$tableRef = $this->app->table($colDef['reference']);

				$q = "SELECT ndx FROM [".$tableRef->sqlName ()."] WHERE [$dstCol] = %s";
				$refRow = $this->app->db()->query ($q, $dstValue)->fetch();

				if (isset($refRow['ndx']))
					$recData[$colId] = $refRow['ndx'];
				else
				{
					$recData[$colId] = 0;
					error_log("ERROR: primary key for '".$table->tableId()."::{$colId}' not found: '".json_encode($colValue)."' from '".$tableRef->tableId()."::{$dstCol}'\n");
				}
			}
		}
	}

	public function setDefaults (&$recData, $cfg)
	{
		forEach ($cfg as $setColId => $setColVal)
		{
			if (!isset ($recData[$setColId]))
				$recData[$setColId] = $setColVal;
		}
	}

	public function upgradeApp ()
	{
		\E10\updateConfiguration($this->app);
		$this->app->loadConfig();
		\E10\updateConfiguration($this->app); // second call
		$this->app->loadConfig();							// is necessary!
	}
}
