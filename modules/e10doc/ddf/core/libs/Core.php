<?php

namespace e10doc\ddf\core\libs;
use \e10\json, \e10\utils, e10\str;


/**
 * Class Core
 * @package e10doc\ddf\core\libs
 */
class Core extends \lib\docDataFiles\DocDataFile
{
	var $srcImpData = NULL;
	var $docHead = [];
	var $docRows = [];
	var $replaceDocumentNdx = 0;


	protected function date($date)
	{
		$d = utils::createDateTime($date);
		if ($d)
			return $d->format('Y-m-d');

		return NULL;
	}

	function valueNumber($v)
	{
		$number = floatval($v);

		return $number;
	}

	protected function valueStr($value, $maxLen)
	{
		if (is_string($value))
			return str::upToLen($value, $maxLen);

		return '';
	}
}
