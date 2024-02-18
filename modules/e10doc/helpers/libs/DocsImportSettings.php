<?php

namespace e10doc\helpers\libs;

use \Shipard\Base\Utility, \Shipard\Utils\Str;


/**
 * class DocsImportSettings
 */
class DocsImportSettings extends Utility
{
	var \Shipard\Utils\Variables $variables;

	function testRow($rs, $docRow, $docHead)
	{
		if ($docRow)
		{
			if (!$this->testStringValue($rs['qryRowSupplierCodeType'], $rs['qryRowSupplierCodeValue'], $docRow['!itemInfo']['supplierCode'] ?? ''))
				return FALSE;

			if (!$this->testStringValue($rs['qryRowTextType'], $rs['qryRowTextValue'], $docRow['text']))
				return FALSE;
		}

		if (!$this->testStringValue($rs['qryHeadTextType'], $rs['qryHeadTextValue'], $docHead['title'] ?? ''))
			return FALSE;

		return TRUE;
	}

	function testStringValue ($qryType, $settingsValue, $docValue)
	{
		if ($qryType == 0)
			return TRUE;
		elseif ($qryType == 1 && $settingsValue === $docValue)
			return TRUE;
		elseif ($qryType == 2 && Str::strStarts($docValue, $settingsValue))
			return TRUE;
		elseif ($qryType == 3 && Str::strstr($docValue, $settingsValue) !== FALSE)
			return TRUE;

		return FALSE;
	}

	function apply (&$docRow, &$docHead)
	{
		if (!$docHead || !isset($docHead['person']) || !$docHead['person'])
		{
			return;
		}

		$q[] = 'SELECT * FROM [e10doc_helpers_impDocsSettings]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [qryHeadPerson] = %i', $docHead['person']);
		array_push ($q, ' AND [docStateMain] = %i', 2);
		array_push ($q, ' AND [settingType] = %i', 0);

		$rows = $this->db()->query ($q);
		foreach ($rows as $rs)
		{
			if (!$this->testRow($rs, $docRow, $docHead))
				continue;
			$this->applyRow($rs, $docRow, $docHead);
		}
	}

	function applyRow($rs, &$docRow, &$docHead)
	{
    $this->variables->setDataItem('docHead', $docHead);
    $this->variables->setDataItem('docRow', $docRow);

		if ($rs['valRowItemType'] === 1)
		{
			$docRow['item'] = $rs['valRowItemValue'];
			//$docRow['itemType'] = '';
		}

		$this->applyMoneyValue($rs['valRowItemPriceType'], $rs['valRowItemPriceValue'], 'priceItem', $docRow);

		if ($rs['valRowCentreType'] === 1)
			$docRow['centre'] = $rs['valRowCentreValue'];

		if ($rs['valRowWorkOrderType'] === 1)
			$docRow['centre'] = $rs['valRowWorkOrderValue'];

		$this->applyStringValue($rs['valRowTextType'], $rs['valRowTextValue'], 'text', $docRow);
		$this->applyStringValue($rs['valHeadTitleType'], $rs['valHeadTitleValue'], 'title', $docHead);
	}

	function applyStringValue($setValueType, $settingsValue, $dstItemColumnId, &$dstItem)
	{
		if ($setValueType === 0)
			return FALSE;

		$dstItem[$dstItemColumnId] = trim($this->variables->resolve($settingsValue));
		return TRUE;
	}

	function applyMoneyValue($setValueType, $settingsValue, $dstItemColumnId, &$dstItem)
	{
		if ($setValueType === 0)
			return FALSE;

		$dstItem[$dstItemColumnId] = floatval(trim($this->variables->resolve($settingsValue)));
		return TRUE;
	}

	function addRows (&$newRows, &$docHead, \lib\docDataFiles\DocDataFile $docDataFile)
	{
		$this->variables = new \Shipard\Utils\Variables($this->app());
		$this->variables->setDataItem('import', $docDataFile->srcImpData);

		if (!$docHead || !isset($docHead['person']) || !$docHead['person'])
		{
			return;
		}

		$q[] = 'SELECT * FROM [e10doc_helpers_impDocsSettings]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [qryHeadPerson] = %i', $docHead['person']);
		array_push ($q, ' AND [docStateMain] = %i', 2);
		array_push ($q, ' AND [settingType] = %i', 1);

		$rows = $this->db()->query ($q);
		foreach ($rows as $rs)
		{
			if (!$this->testRow($rs, NULL, $docHead))
				continue;

			$newRow = [];
			$this->applyRow($rs, $newRow, $docHead);

			if (count($newRow))
				$newRows[] = $newRow;
		}
	}

	public function run (&$docRow, &$docHead)
	{
		$this->variables = new \Shipard\Utils\Variables($this->app());
		$this->apply($docRow, $docHead);
	}
}

