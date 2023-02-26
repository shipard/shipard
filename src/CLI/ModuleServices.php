<?php

namespace Shipard\CLI;
use \Shipard\Utils\Utils;


class ModuleServices
{
	/** @var \Shipard\Application\Application $app */
	protected $app;
	public $initConfig;

	public function __construct ($app)
	{
		$this->app= $app;
		if (is_file (__APP_DIR__ . '/config/createApp.json'))
		{
			$cfgString = file_get_contents ('config/createApp.json');
			$this->initConfig = json_decode ($cfgString, TRUE);
		}
		else
			$this->initConfig = NULL;
	}

	protected function doSqlScripts ($sqlScripts)
	{
		$today = Utils::today('Y-m-d');
		forEach ($sqlScripts as $script)
		{
			$doIt = 0;
			if (isset($script['version']) && ($script['version'] === 0 || $this->app->lastUsedVersionId < $script['version']))
				$doIt = 1;

			if (isset($script['end']) && !Utils::dateIsValid($script['end']))
			{
				$this->app()->err("Wrong date `{$script['end']}` in onAppUpgrade");
				Utils::debugBacktrace();
			}

			if (isset($script['end']) && $script['end'] >= $today)
				$doIt = 1;

			if ($doIt)
			{
				//echo $script['sql']."\n";
				$this->app->db()->query ($script['sql']);
			}
		}
	}

	protected function upgradeAppOption ($oldId, $newId)
	{
		$oldIdParts = explode('.', $oldId);
		$oldIdFileName = 'config/appOptions.'.$oldIdParts[1].'.json';
		if (!is_readable($oldIdFileName))
			return;
		$oldIdData = Utils::loadCfgFile($oldIdFileName);
		if (!$oldIdData)
		{
			error_log("upgradeAppOption: old file `$oldIdFileName` not found or has syntax error.");
			return;
		}

		$newIdParts = explode('.', $newId);
		$newIdFileName = 'config/appOptions.'.$newIdParts[1].'.json';
		$newIdData = Utils::loadCfgFile($newIdFileName);
		if (!$newIdData)
			$newIdData = [];

		if (!isset($oldIdData[$oldIdParts[2]]))
		{
			return;
		}

		$needSave = FALSE;

		if (!isset($newIdData[$newIdParts[2]])/* || $oldIdData[$oldIdParts[2]] !== $oldIdData[$newIdParts[2]]*/)
		{
			$newIdData[$newIdParts[2]] = $oldIdData[$newIdParts[2]];
			$needSave = TRUE;
		}

		if ($needSave)
		{
			file_put_contents($newIdFileName, json_encode($newIdData));
		}
	}

	public function app() {return $this->app;}
	public function db() {return $this->app->db;}

	public function onAnonymize () {return TRUE;}
	public function onAppPublish () {return TRUE;}
	public function onBeforeAppUpgrade () {return TRUE;}
	public function onAppUpgrade () {return TRUE;}
	public function onCreateDataSource () {return TRUE;}
	public function onCheckSystemData () {return TRUE;}
	public function onCron ($cronType) {return TRUE;}

	public function onCliAction ($actionId)
	{
		echo ("Action `{$actionId}` not found.\n");
		return TRUE;
	}

	function moveTable ($srcTableId, $srcTableSqlName, $dstTableId, $disabledCols = [])
	{
		/** @var \e10\DbTable */
		$dstTable = $this->app->table ($dstTableId);

		$colsList = [];
		foreach ($dstTable->columns() as $colDef)
		{
			if (in_array($colDef['sql'], $disabledCols))
				continue;
			$colsList[] = '[' . $colDef['sql'] . ']';
		}

		$sqlCommand = 'INSERT INTO ['.$dstTable->sqlName().']';
		$sqlCommand .= ' ('.implode(', ', $colsList).')';
		$sqlCommand .= ' SELECT '.implode(', ', $colsList).' FROM ['.$srcTableSqlName.'] ORDER BY [ndx]';

		//echo $sqlCommand."\n";
		$this->app->db()->query($sqlCommand);
		$this->app->db()->query('DELETE FROM ['.$srcTableSqlName.']');

		$this->app->db()->query ('UPDATE [e10_base_docslog] SET tableId = %s', $dstTableId, ' WHERE tableid = %s', $srcTableId);
	}
}
