<?php

namespace e10doc\ddm\libs;
use \e10\Utility, e10\utils, e10\str;


/**
 * Class Engine
 * @package e10doc\ddm\libs
 */
class Engine extends Utility
{
	var $srcText;
	var $formatItemsTypes;

	public function setSrcText($srcText)
	{
		$this->srcText = $srcText;

		$this->formatItemsTypes = $this->app()->cfgItem ('e10doc.ddm.formatItemsTypes');
	}

	public function testOne($rec)
	{
		$fit = isset($this->formatItemsTypes[$rec['itemType']]) ? $this->formatItemsTypes[$rec['itemType']] : NULL;

		$re = '';

		if ($rec['searchPrefix'] !== '')
		{
			if ($rec['prefixIsRegExp'])
				$re .= $rec['searchPrefix'];
			else
				$re .= preg_quote($rec['searchPrefix'])."\\s+";
		}

		$reValueBase = '';
		if ($rec['searchRegExp'] !== '')
		{
			$re .= $rec['searchRegExp'];
			$reValueBase = $rec['searchRegExp'];
		}
		else
		{
			if ($fit && isset($fit['re']))
			{
				$re .= $fit['re'];
				$reValueBase = $fit['re'];
			}
		}

		if ($rec['searchSuffix'] !== '')
		{
			if ($rec['prefixIsRegExp'])
				$re .= $rec['searchSuffix'];
			else
				$re .= "\\s+".preg_quote($rec['searchSuffix']);
		}

		$re = '/'.$re.'/';
		$re .= $rec['searchRegExpFlags'];
		$reValue = '/'.$reValueBase.'/'.$rec['searchRegExpFlags'];

		$matches = [];
		$r = preg_match($re, $this->srcText, $matches);

		$results = [];
		foreach ($matches as $match)
		{
			$t = $match;
			/*
			if ($rec['searchPrefix'] !== '')
			{
				if ($rec['prefixIsRegExp'])
					$t = preg_replace("/".$rec['searchPrefix']."/".$rec['searchRegExpFlags'], '', $t);
				else
					$t = preg_replace("/".preg_quote($rec['searchPrefix'])."/", '', $t);
			}
			if ($rec['searchSuffix'] !== '')
			{
				if ($rec['suffixIsRegExp'])
					$t = preg_replace("/" . $rec['searchSuffix'] . "/".$rec['searchRegExpFlags'], '', $t);
				else
					$t = preg_replace("/".preg_quote($rec['searchSuffix'])."/", '', $t);
			}
			*/

			$matches2 = [];
			$r2 = preg_match($reValue, $t, $matches2);

			foreach ($matches2 as $m2)
			{
				$t = $m2;
				break;
			}

			$t = trim($t);
			if ($t === '')
				continue;

			$results[] = $t;

			break;
		}

		return $results;
	}
}
