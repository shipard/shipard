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

	var $docHeadSrcItems = [];

	public function setSrcText($srcText)
	{
		$this->srcText = $srcText;

		$this->formatItemsTypes = $this->app()->cfgItem ('e10doc.ddm.formatItemsTypes');
	}

	public function testOne($rec)
	{
		$fit = isset($this->formatItemsTypes[$rec['itemType']]) ? $this->formatItemsTypes[$rec['itemType']] : NULL;

		$re = '';

		if (isset($rec['searchPrefix']) && $rec['searchPrefix'] !== '')
		{
			if ($rec['prefixIsRegExp'] ?? 0)
				$re .= $rec['searchPrefix'];
			else
				$re .= preg_quote($rec['searchPrefix'])."\\s+";
		}

		$reValueBase = '';
		if (isset($rec['searchRegExp']) && $rec['searchRegExp'] !== '')
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

		if (isset($rec['searchSuffix']) && $rec['searchSuffix'] !== '')
		{
			if ($rec['prefixIsRegExp'])
				$re .= $rec['searchSuffix'];
			else
				$re .= "\\s+".preg_quote($rec['searchSuffix']);
		}

		$re = '/'.$re.'/';
		$re .= $rec['searchRegExpFlags'] ?? '';
		$reValue = '/'.$reValueBase.'/'.($rec['searchRegExpFlags'] ?? '');

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

			$this->docHeadSrcItems[$rec['itemType']] = $t;

			break;
		}

		return $results;
	}

	public function importDataText($srcText, $formatDef)
	{
		$this->srcText = $srcText;
		$this->formatItemsTypes = $this->app()->cfgItem ('e10doc.ddf.ddm.formatItemsTypes');

		foreach ($formatDef['items'] as $item)
		{
			$itemRes = $this->testOne($item);
			//echo "  -> ".$item['itemType']." = ".json_encode($itemRes)."\n";
		}

		if (!count($this->docHeadSrcItems))
			return;

		foreach ($this->docHeadSrcItems as $itemId => $itemTextValue)
		{
			$colDef = $this->formatItemsTypes[$itemId] ?? NULL;
			if (!$colDef)
				continue;
			if (!isset($colDef['type']))
				continue;
			if ($colDef['type'] === 'date')
			{
				$dateFormat = $formatDef['datesFormat'] ?? 'd.m.Y';
				$dd = date_create_from_format ($dateFormat, $itemTextValue);
				if ($dd !== false)
				{
					$dddd = $dd->format('Y-m-d');
					$this->docHeadSrcItems[$itemId] = $dddd;
				}
				else
					$this->docHeadSrcItems[$itemId] = NULL;
			}
			elseif ($colDef['type'] === 'price')
			{
				$numbersThousandsSeparator = $formatDef['numbersThousandsSeparator'] ?? ' ';
				$s1 = str_replace($numbersThousandsSeparator, '', $itemTextValue);
				$s1 = str_replace(',', '.', $s1);
				$this->docHeadSrcItems[$itemId] = floatval($s1);
			}
		}

		if (isset($this->docHeadSrcItems['bank-account-domestic']))
		{
			$bankAccount = str_replace(' ', '', trim($this->docHeadSrcItems['bank-account-domestic']));
			$this->docHeadSrcItems['bankAccount'] = $bankAccount;
			unset($this->docHeadSrcItems['bank-account-domestic']);
		}

		if (isset($this->docHeadSrcItems['payment-symbol1']))
		{
			$this->docHeadSrcItems['symbol1'] = $this->docHeadSrcItems['payment-symbol1'];
			unset($this->docHeadSrcItems['payment-symbol1']);
		}

		if (isset($this->docHeadSrcItems['date-issue']) && $this->docHeadSrcItems['date-issue'])
		{
			$this->docHeadSrcItems['dateIssue'] = $this->docHeadSrcItems['date-issue'];
			unset($this->docHeadSrcItems['date-issue']);
		}

		if (isset($this->docHeadSrcItems['date-tax']) && $this->docHeadSrcItems['date-tax'])
		{
			$this->docHeadSrcItems['dateTax'] = $this->docHeadSrcItems['date-tax'];
			unset($this->docHeadSrcItems['date-tax']);
		}

		if (isset($this->docHeadSrcItems['date-due']) && $this->docHeadSrcItems['date-due'])
		{
			$this->docHeadSrcItems['dateDue'] = $this->docHeadSrcItems['date-due'];
			unset($this->docHeadSrcItems['date-due']);
		}

		if (isset($this->docHeadSrcItems['document-id']))
		{
			$this->docHeadSrcItems['documentId'] = $this->docHeadSrcItems['document-id'];
			unset($this->docHeadSrcItems['document-id']);
		}

		if (isset($this->docHeadSrcItems['taxType']))
		{
			$this->docHeadSrcItems['taxType'] = intval($this->docHeadSrcItems['taxType']);
			unset($this->docHeadSrcItems['taxType']);
		}

		$this->docHeadSrcItems['person']['country'] = 'cz';
		if (isset($this->docHeadSrcItems['head-company-id']))
		{
			$this->docHeadSrcItems['person']['natId'] = str_replace (' ', '', $this->docHeadSrcItems['head-company-id']);
			unset($this->docHeadSrcItems['head-company-id']);
		}
		if (isset($this->docHeadSrcItems['head-vat-id']))
		{
			$this->docHeadSrcItems['person']['vatId'] = str_replace(' ', '', $this->docHeadSrcItems['head-vat-id']);
			unset($this->docHeadSrcItems['head-vat-id']);
		}
	}
}
