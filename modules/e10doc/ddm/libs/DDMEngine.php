<?php

namespace e10doc\ddm\libs;
use \e10\Utility, e10\utils, e10\str;


/**
 * class DDMEngine
 */
class DDMEngine extends Utility
{
	var $srcText;
	var $formatItemsTypes;

	var $docHeadSrcItems = [];

	public function setSrcText($srcText)
	{
		$this->srcText = $srcText;

		$this->formatItemsTypes = $this->app()->cfgItem ('e10doc.ddm.ddmItemsTypes');
	}

	public function testOne($rec)
	{
		$itemTypeCfg = $this->app()->cfgItem('e10doc.ddm.ddmItemsTypes.'.$rec['itemType'], NULL);
		$itemTypeDataType = ($itemTypeCfg) ? ($itemTypeCfg['type'] ?? '') : '';

		$strStart = $rec['searchPrefix'];
		$strEnd = $rec['searchSuffix'];

		$strStart = str_replace("\\n", "\n", $strStart);
		$strStart = str_replace("\\s", " ", $strStart);
		$strEnd = str_replace("\\n", "\n", $strEnd);
		$strEnd = str_replace("\\s", " ", $strEnd);

		$res = trim(Str::strBetween($this->srcText, $strStart, $strEnd));

		if ($itemTypeDataType === 'date')
		{
			if ($res === '')
				return '0000-00-00';
			$dateParts = preg_split ('/[\:,\.,\-\/]/', $res);
			if (count($dateParts) !== 3)
				return '0000-00-00';
			$dateFormat = $this->app()->cfgItem('e10doc.ddm.ddmDateFormats.'.$rec['dateFormat'], NULL);
			if (!$dateFormat)
				return '0000-00-00';
			$year = intval($dateParts[$dateFormat['y']]);
			$month = intval($dateParts[$dateFormat['m']]);
			$day = intval($dateParts[$dateFormat['d']]);
			$dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
			if (!Utils::dateIsValid($dateStr))
				return '0000-00-00';
			return $dateStr;
		}

		if ($itemTypeDataType === 'money')
		{
			$numbersThousandsSeparator = /*$formatDef['numbersThousandsSeparator'] ?? */' ';
			$s1 = str_replace($numbersThousandsSeparator, '', $res);
			$s1 = str_replace(',', '.', $s1);
			return floatval($s1);
		}

		$result = $res;

		return $result;
	}

	public function importDataText($srcText, $formatDef)
	{
		$this->srcText = $srcText;
		$this->formatItemsTypes = $this->app()->cfgItem ('e10doc.ddf.ddm.ddmItemsTypes');

		foreach ($formatDef['items'] as $item)
		{
			$itemId = $item['itemType'];
			$itemRes = $this->testOne($item);
			$this->docHeadSrcItems[$itemId] = $itemRes;
			//echo "  -> `".$item['itemType']."` = ".json_encode($itemRes)."\n";
		}

		if (!count($this->docHeadSrcItems))
			return;

		if (isset($this->docHeadSrcItems['paymentSymbol1']))
		{
			$this->docHeadSrcItems['symbol1'] = $this->docHeadSrcItems['paymentSymbol1'];
			unset($this->docHeadSrcItems['paymentSymbol1']);
		}
		if (isset($this->docHeadSrcItems['paymentSymbol2']))
		{
			$this->docHeadSrcItems['symbol2'] = $this->docHeadSrcItems['paymentSymbol2'];
			unset($this->docHeadSrcItems['paymentSymbol2']);
		}

		if (isset($this->docHeadSrcItems['docId']))
		{
			$this->docHeadSrcItems['documentId'] = $this->docHeadSrcItems['docId'];
			unset($this->docHeadSrcItems['docId']);
		}

		if (isset($this->docHeadSrcItems['taxType']))
		{
			$this->docHeadSrcItems['taxType'] = intval($this->docHeadSrcItems['taxType']);
			unset($this->docHeadSrcItems['taxType']);
		}

		$this->docHeadSrcItems['person']['country'] = 'cz';
		if (isset($this->docHeadSrcItems['partnerOID']))
		{
			$this->docHeadSrcItems['person']['natId'] = $this->docHeadSrcItems['partnerOID'];
			unset($this->docHeadSrcItems['partnerOID']);
		}
		if (isset($this->docHeadSrcItems['partnerVatID']))
		{
			$this->docHeadSrcItems['person']['vatId'] = $this->docHeadSrcItems['partnerVatID'];
			unset($this->docHeadSrcItems['partnerVatID']);
		}
	}
}
