<?php

namespace Shipard\CLI;
use \Shipard\Utils\Utils;


class ModuleServices
{
	/** @var  \lib\base\Application */
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

	public function app() {return $this->app;}
	public function db() {return $this->app->db;}

	public function onAnonymize () {return TRUE;}
	public function onAppPublish () {return TRUE;}
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
