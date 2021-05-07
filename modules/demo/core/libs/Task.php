<?php

namespace demo\core\libs;


use \e10\utils, \e10\Utility;


/**
 * Class Task
 */
class Task extends Utility
{
	var $taskId;
	var $taskDef;
	var $taskTypeDef;

	public function init ($taskDef, $taskTypeDef)
	{
		$this->taskDef = $taskDef;
		$this->taskTypeDef = $taskTypeDef;
	}
}
