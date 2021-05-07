<?php

namespace lib\spreadsheets;

use \e10\Utility, \lib\spreadsheets\Spreadsheet, \e10\GlobalReport;


/**
 * Class SpreadsheetDataConnector
 * @package lib\spreadsheets
 */
class SpreadsheetDataConnector extends Utility
{
	protected $spd;
	protected $params = [];

	public function setSpreadsheet (Spreadsheet $spd)
	{
		$this->spd = $spd;

		forEach ($spd->param() as $paramKey => $paramValue)
			$this->setParam($paramKey, $paramValue);
	}

	public function setReport (GlobalReport $report)
	{
		//$this->spd = $spd->app;
		//$this->app = $report->app;

//		forEach ($spd->param() as $paramKey => $paramValue)
//			$this->setParam($paramKey, $paramValue);
	}


	public function init()
	{
	}

	public function setParam ($paramKey, $paramValue)
	{
		$this->params[$paramKey] = $paramValue;
	}

	public function param ($paramKey = FALSE)
	{
		if ($paramKey === FALSE)
			return $this->params;
		if (isset ($this->params[$paramKey]))
			return $this->params[$paramKey];
		return FALSE;
	}

}
