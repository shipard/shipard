<?php

namespace demo\core\libs;

use \E10\Utility, \E10\utils;


/**
 * Class Generator
 */
class Generator extends Utility
{
	var $now = NULL;
	var $today;
	var $month;
	var $hour;
	var $dayOfWeek;
	var $dayOfMonth;

	var $tasks = [];

	public function setNow ($now = NULL)
	{
		$this->now = ($now) ? $now : new \DateTime();

		$this->today = $this->now->format('Y-m-d');
		$this->hour = intval($this->now->format('H'));
		$this->dayOfWeek = intval($this->now->format('N'));
		$this->dayOfMonth = intval($this->now->format('d'));
		$this->month = intval($this->now->format('m'));
	}

	function paramMinMax ($paramId, $taskDef, $taskTypeDef)
	{
		$v = 1;

		if (isset($taskDef[$paramId]))
		{
			if (is_array($taskDef[$paramId]))
				$v = mt_rand($taskDef[$paramId]['min'], $taskDef[$paramId]['max']);
			else
				$v = $taskDef[$paramId];
		}
		else
		if (isset($taskTypeDef[$paramId]))
		{
			if (is_array($taskTypeDef[$paramId]))
				$v = mt_rand($taskTypeDef[$paramId]['min'], $taskTypeDef[$paramId]['max']);
			else
				$v = $taskTypeDef[$paramId];
		}

		return $v;
	}

	function createTodayTasks()
	{
		$bump = ['taskId' => 'bump', 'date' => $this->today, 'hourStart' => -1, 'cntRequests' => 1];
		$this->db()->query('INSERT INTO [demo_core_tasks]', $bump);

		$allTasks = $this->app()->cfgItem ('demo.tasks', []);
		foreach ($allTasks as $taskId => $task)
		{
			$taskTypeDef = $this->loadTaskDef($task['type']);

			if (!$this->checkPeriod ($task))
				continue;

			$cntRequests = $this->paramMinMax('cntRequests', $task, $taskTypeDef);
			if ($cntRequests === 0)
				continue;

			$hourStart = (isset($task['hourStart'])) ? $task['hourStart'] : 7;
			$hourStop = (isset($task['hourStop'])) ? $task['hourStop'] : 16;

			$item = ['taskId' => $taskId, 'date' => $this->today, 'hourStart' => $hourStart, 'hourStop' => $hourStop, 'cntRequests' => $cntRequests];
			$this->db()->query('INSERT INTO [demo_core_tasks]', $item);
		}
	}

	function checkPeriod ($task)
	{
		if (isset($task['dayOfWeek']))
		{
			if (is_array($task['dayOfWeek']) && !in_array($this->dayOfWeek, $task['dayOfWeek']))
				return FALSE;
			if (!is_array($task['dayOfWeek']) && $task['dayOfWeek'] != $this->dayOfWeek)
				return FALSE;
		}

		if (isset($task['dayOfMonth']))
		{
			if (is_array($task['dayOfMonth']) && !in_array($this->dayOfMonth, $task['dayOfMonth']))
				return FALSE;
			if (!is_array($task['dayOfMonth']) && $task['dayOfMonth'] != $this->dayOfMonth)
				return FALSE;
		}

		if (isset($task['monthOfYear']))
		{
			if (is_array($task['monthOfYear']) && !in_array($this->month, $task['monthOfYear']))
				return FALSE;
			if (is_array($task['monthOfYear']) && $task['monthOfYear'] != $this->month)
				return FALSE;
		}

		return TRUE;
	}

	function loadTaskDef ($taskType)
	{
		$tfn = __SHPD_MODULES_DIR__.'demo/core/tasks/'.$taskType.'.json';
		$taskTypeDef = $this->loadCfgFile($tfn);
		return $taskTypeDef;
	}

	function loadTasks()
	{
		$q[] = 'SELECT * FROM [demo_core_tasks] WHERE 1';
		array_push ($q, ' AND [date] = %d', $this->today);
		array_push ($q, ' AND [cntRequests] > [cntDone]');

		array_push ($q, 'AND (');
		array_push ($q, '([hourStart] <= %i', $this->hour, ' AND [hourStop] >= %i)', $this->hour, ' OR [hourStart] = -1');
		array_push ($q, ')');

		$cnt = 0;
		$rows = $this->app()->db()->query($q);
		//echo \dibi::$sql."\n";
		foreach ($rows as $r)
		{
			if ($r['hourStart'] !== -1)
				$this->tasks[] = $r->toArray();
			$cnt++;
		}

		if (!$cnt)
			$this->createTodayTasks();
	}

	function runTask (&$r)
	{
		$taskDef = $this->app()->cfgItem ('demo.tasks.'.$r['taskId'], NULL);
		$taskTypeDef = $this->loadTaskDef($taskDef['type']);

		$te = $this->app()->createObject($taskTypeDef['class']);
		if (!$te)
			return;

		$te->taskId = $r['taskId'];
		$te->init($taskDef, $taskTypeDef);
		if ($te->run())
		{
			$r['cntDone']++;
			$this->app()->db()->query('UPDATE [demo_core_tasks] SET [cntDone] = [cntDone] + 1 WHERE [ndx] = %i', $r['ndx']);
		}
	}

	public function run()
	{
		if (!$this->now)
			$this->setNow();

		$this->loadTasks();

		foreach ($this->tasks as &$r)
		{
			$this->runTask($r);
		}
	}
}
