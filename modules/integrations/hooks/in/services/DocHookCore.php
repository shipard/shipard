<?php

namespace integrations\hooks\in\services;
use e10\Utility;


/**
 * Class DocHookCore
 * @package integrations\hooks\in\services
 */
class DocHookCore extends Utility
{
	/** @var \integrations\hooks\in\services\HookCore  */
	var $hook = NULL;

	/** @var \e10\DbTable */
	var $table = NULL;

	var $dstDoc = [];

	protected function setTable($tableId)
	{
		$this->table = $this->app()->table($tableId);
	}

	public function setHook(\integrations\hooks\in\services\HookCore $hook)
	{
		$this->hook = $hook;
	}

	function saveDocument($setResult = TRUE)
	{
		$o = new \lib\objects\ObjectsImport($this->app());
		$o->importData($this->table, $this->dstDoc);


		$ndx = 0;

		if ($o->result['status'] === 0)
		{ // ok
			if (isset($o->result['recData']['ndx']))
			{
				$ndx = $o->result['recData']['ndx'];
				$this->table->docsLog($ndx);

				if ($setResult)
					$this->hook->setResult($o->result);
			}
		}
		else
		{
			if ($setResult)
				$this->hook->setResult($o->result);
		}

		return $ndx;
		//echo json_encode($o->result)."\n";
	}
}
