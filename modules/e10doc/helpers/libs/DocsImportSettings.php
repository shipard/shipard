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

			if (!$this->testNumberValue($rs['qryRowPriceAllType'], $rs['qryRowPriceAllValueFrom'], $rs['qryRowPriceAllValueTo'], $docRow['priceAll']))
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

	function testNumberValue ($qryType, $settingsValueFrom, $settingsValueTo, $docValue)
	{
		if ($qryType == 0)
			return TRUE;
		elseif ($qryType == 1)
		{
			if ($docValue < $settingsValueFrom)
				return FALSE;
			if ($docValue > $settingsValueTo)
				return FALSE;

			return TRUE;
		}

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
		array_push ($q, ' AND ([qryHeadPerson] = %i', $docHead['person'], ' OR [qryHeadPerson] = %i', 0, ')');
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
			$docRow['workOrder'] = $rs['valRowWorkOrderValue'];
		elseif ($rs['valRowWorkOrderType'] === 2)
			$this->applyReferenceScript($rs['valRowWorkOrderType'], $rs['valRowWorkOrderScript'], 'workOrder', $docRow,
																	'e10mnf.core.workOrders', ['docNumber', 'title']);

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

	function applyReferenceScript($setValueType, $settingsValue, $dstItemColumnId, &$dstItem, $refTableId, $refColumns)
	{
		if ($setValueType !== 2)
			return FALSE;

		$refValue = trim($this->variables->resolve($settingsValue));

		/** @var \Shipard\Table\DbTable */
		$refTable = $this->app()->table($refTableId);
		if (!$refTable)
			return FALSE;

		foreach ($refColumns as $col)
		{
			$q = [];
			array_push($q, 'SELECT * FROM ['.$refTable->sqlName().']');
			array_push($q, ' WHERE 1');
			array_push($q, ' AND ['.$col.'] = %s',  $refValue);
			array_push($q, ' AND [docState] IN %in', [4000, 8000, 1200]);

			$exist = $this->db()->query($q)->fetch();
			if ($exist)
			{
				$dstItem[$dstItemColumnId] = $exist['ndx'];
				return TRUE;
			}
		}

		return FALSE;
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

