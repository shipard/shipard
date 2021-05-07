<?php

namespace integrations\services\core;

use \e10\Utility;

/**
 * Class RunTasks
 * @package integrations\services\core
 */
class RunTasks extends Utility
{
	function doOneTask ($t)
	{
		$taskTypeNdx = $t['taskType'];
		$taskTypeCfg = $this->app()->cfgItem('integration.tasks.types.'.$taskTypeNdx, NULL);
		if (!$taskTypeCfg)
			return;

		$classId = $taskTypeCfg['classId'];

		$o = $this->app()->createObject($classId);
		if (!$o)
			return;

		$o->runTask($t);
	}

	function doAllTasks()
	{
		$q[] = 'SELECT * FROM [integrations_core_tasks]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] = %i', 4000);
		array_push ($q, ' ORDER BY [service], [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->doOneTask($r->toArray());
		}
	}

	public function run()
	{
		$this->doAllTasks();
	}
}