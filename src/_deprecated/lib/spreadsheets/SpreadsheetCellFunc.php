<?php

namespace lib\spreadsheets;

use \e10\Utility;

/**
 * Class SpreadsheetCellFunc
 * @package lib\spreadsheets
 */
class SpreadsheetCellFunc
{
	private $name;
	private $params;
	private $isCell = FALSE;

	public function __construct ()
	{
	}

	public function setFunction ($funcCode)
	{
		if ($funcCode[0] === '{')
			$fc = mb_substr ($funcCode, 1, -1, 'utf-8');
		else
		{
			$fc = $funcCode;
			$this->params = array ();
		}

		$funcParts = explode (';', $fc);
		if (count($funcParts) === 1)
		{
			$this->name = $funcParts[0];
			$this->params = array ();
			return;
		}

		$this->name = array_shift($funcParts);
		$this->params = array ();
		forEach ($funcParts as $prm)
		{
			$paramParts = explode ('=', $prm);
			if (count($paramParts) === 1)
				$this->params[$paramParts[0]] = 1;
			else
				$this->params[$paramParts[0]] = $paramParts[1];
		}
	}

	public function param ($paramKey = FALSE)
	{
		if ($paramKey === FALSE)
			return $this->params;

		return FALSE;
	}

	public function name () {return $this->name;}
}
