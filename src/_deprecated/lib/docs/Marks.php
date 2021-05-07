<?php

namespace lib\docs;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';

use \E10\utils, \E10\uiutils, \E10\Utility;


/**
 * Class Marks
 * @package lib\docs
 */
class Marks extends Utility
{
	var $markId = 0;
	var $markCfg = NULL;
	/** @var \e10\DbTable */
	var $storeTable;
	/** @var \e10\DbTable */
	var $markTable;

	var $marks = [];

	public function setMark ($markId)
	{
		$this->markId = $markId;
		$this->markCfg = $this->app()->cfgItem('docMarks.'.$this->markId, NULL);

		$this->storeTable = $this->app()->table($this->markCfg['storeTable']);
	}

	public function loadMarks ($tableId, $pks)
	{
		if (!$this->markCfg || !$this->storeTable)
			return;

		$this->markTable = $this->app()->table($tableId);
		if (!$this->markTable)
			return;

		if (!count($pks))
			return;

		$rows = $this->db()->query ('SELECT [rec], [state] FROM ['.$this->storeTable->sqlName().'] WHERE ',
			'[user] = %i', $this->app()->userNdx(), ' AND [table] = %i', $this->markTable->ndx,
			' AND [mark] = %i', $this->markId, ' AND [rec] IN %in', $pks);
		foreach ($rows as $r)
		{
			$this->marks[$r['rec']] = $r['state'];
		}
	}
}