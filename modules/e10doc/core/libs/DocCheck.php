<?php

namespace e10doc\core\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/debs/debs.php';
use \e10\TableForm, \e10\utils, \e10\Utility;


/**
 * Class DocCheck
 * @package e10doc\core\libs
 */
class DocCheck extends Utility
{
	var $docNdx = 0;
	var $docRecData = NULL;

	var $witems = [];

	var $msgs = [];

	var $repair = FALSE;
	var $needRecalc = FALSE;
	var $needReAccounting = FALSE;

	/** @var \e10doc\core\TableHeads */
	var $tableHeads;
	/** @var \e10doc\core\TableRows */
	var $tableRows;

	public function init()
	{
		$this->tableHeads = $this->app->table ('e10doc.core.heads');
		$this->tableRows = $this->app->table ('e10doc.core.rows');
	}

	public function setDocNdx($docNdx)
	{
		$this->needRecalc = FALSE;
		$this->needReAccounting = FALSE;

		$this->docNdx = $docNdx;
		$this->docRecData = $this->tableHeads->loadItem($this->docNdx);
	}

	public function checkDocument($repair)
	{
		$this->repair = $repair;
	}

	function addRowMsg($row, $msg)
	{
		$m = [
			'row' => $row,
			'msg' => $msg,
		];

		$this->msgs[] = $m;
	}

	function dumpMessages()
	{
		if (!count($this->msgs))
			return;


		echo "#".$this->docNdx.'; '.$this->docRecData['docNumber'].' / '.$this->docRecData['docType'].'; '.$this->docRecData['title']."\n";

		foreach ($this->msgs as $m)
		{
			$s = '  ';
			$s .= '#'.$m['row']['ndx'];
			$s .= '; '.$m['row']['text'];
			$s .= '; '.$m['msg'];

			echo $s."\n";
		}
	}

	function witem($itemNdx)
	{
		if (isset($this->witems[$itemNdx]))
			return $this->witems[$itemNdx];

		$item = $this->db()->query('SELECT * FROM [e10_witems_items] WHERE [ndx] = %i', $itemNdx)->fetch();
		if ($item)
		{
			$this->witems[$itemNdx] = $item->toArray();
			return $this->witems[$itemNdx];
		}

		return NULL;
	}


}
