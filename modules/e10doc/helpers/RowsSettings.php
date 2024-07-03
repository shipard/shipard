<?php

namespace e10doc\helpers;

use \e10\Utility, \e10\str;


/**
 * Class RowsSettings
 * @package e10doc\helpers
 */
class RowsSettings extends Utility
{
	var $enabledRowsSettingsType = NULL;

	var $cntApplies = 0;
	var $usedRowsSettings = [];

	function testRow($rs, $docRow, $docHead)
	{
		if ($rs['qryRowDirType'] == 1 && $docRow['debit'] > 0.0) // in/credit
			return FALSE;
		if ($rs['qryRowDirType'] == 2 && $docRow['credit'] > 0.0) // out/debit
			return FALSE;

		if ($rs['qryMyBankAccountType'] == 1 && $docHead['myBankAccount'] != $rs['myBankAccount'])
			return FALSE;
		//if ($rs['qryOperationType'] == 1 && $docRow['operation'] != $rs['qryOperationValue'])
		//	return FALSE;
		if (!$this->testStringValue($rs['qryRowBankAccountType'], $rs['qryRowBankAccountValue'], $docRow['bankAccount'] ?? ''))
			return FALSE;
		if (!$this->testStringValue($rs['qryRowSymbol1Type'], $rs['qryRowSymbol1Value'], $docRow['symbol1'] ?? ''))
			return FALSE;
		if (!$this->testStringValue($rs['qryRowSymbol2Type'], $rs['qryRowSymbol2Value'], $docRow['symbol2'] ?? ''))
			return FALSE;
		if (!$this->testStringValue($rs['qryRowSymbol3Type'], $rs['qryRowSymbol3Value'], $docRow['symbol3'] ?? ''))
			return FALSE;
		if (!$this->testStringValue($rs['qryRowTextType'], $rs['qryRowTextValue'], $docRow['text']))
			return FALSE;

		return TRUE;
	}

	function testStringValue ($qryType, $settingsValue, $docValue)
	{
		if ($qryType == 0)
			return TRUE;
		elseif ($qryType == 1 && $settingsValue === $docValue)
			return TRUE;
		elseif ($qryType == 2 && str::strStarts($docValue, $settingsValue))
			return TRUE;
		elseif ($qryType == 3 && str::strstr($docValue, $settingsValue) !== FALSE)
			return TRUE;

		return FALSE;
	}

	function apply (&$docRow, $docHead)
	{
		if (!$this->enabledRowsSettingsType || !count($this->enabledRowsSettingsType))
			return;

		$q[] = 'SELECT * FROM [e10doc_helpers_rowsSettings]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docStateMain] = %i', 2);

		if ($docHead && isset($docHead['person']))
		{
			if ($docHead['person'])
				array_push ($q, ' AND ([qryHeadPerson] = %i', 0, ' OR [qryHeadPerson] = %i', $docHead['person'], ')');
			else
				array_push ($q, ' AND [qryHeadPerson] = %i', 0);
		}

		if (count($this->enabledRowsSettingsType) === 1)
			array_push ($q, ' AND [settingsType] = %i', $this->enabledRowsSettingsType[0]);
		else
			array_push ($q, ' AND [settingsType] IN %in', $this->enabledRowsSettingsType);

		$rows = $this->db()->query ($q);
		foreach ($rows as $rs)
		{
			if (!$this->testRow($rs, $docRow, $docHead))
				continue;

			$this->usedRowsSettings[$rs['ndx']] = [
				'rsRecData' => $rs->toArray(),
				'changes' => [],
				'applies' => [],
			];
			$this->applyRow($rs, $docRow, $docHead, $this->usedRowsSettings[$rs['ndx']]);
		}
	}

	function applyRow($rs, &$docRow, $docHead, &$info)
	{
		if ($rs['valRowPersonType'] === 1)
		{
			if ($docRow['person'] != $rs['valRowPersonValue'])
				$info['changes'][] = ['person' => ['from' => $docRow['person'], 'to' => $rs['valRowPersonValue']]];
			$info['applies'][] = ['person' => $rs['valRowPersonValue']];

			$docRow['person'] = $rs['valRowPersonValue'];
			$this->cntApplies++;
		}
		if ($rs['valRowOperationType'] === 1)
		{
			if ($docRow['operation'] != $rs['valRowOperationValue'])
				$info['changes'][] = ['operation' => ['from' => $docRow['operation'], 'to' => $rs['valRowOperationValue']]];
			$info['applies'][] = ['operation' => $rs['valRowOperationValue']];

			$docRow['operation'] = $rs['valRowOperationValue'];
			$this->cntApplies++;
		}

		if ($rs['valRowItemType'] === 1)
		{
			if ($docRow['item'] != $rs['valRowItemValue'])
				$info['changes'][] = ['item' => ['from' => $docRow['item'], 'to' => $rs['valRowItemValue']]];
			$info['applies'][] = ['item' => $rs['valRowItemValue']];

			$docRow['item'] = $rs['valRowItemValue'];
			$docRow['itemType'] = '';
			$this->cntApplies++;
		}

		if ($rs['valRowCentreType'] === 1)
		{
			if ($docRow['centre'] != $rs['valRowCentreValue'])
				$info['changes'][] = ['centre' => ['from' => $docRow['centre'], 'to' => $rs['valRowCentreValue']]];
			$info['applies'][] = ['centre' => $rs['valRowCentreValue']];

			$docRow['centre'] = $rs['valRowCentreValue'];
			$this->cntApplies++;
		}

		if ($rs['valRowWorkOrderType'] === 1)
		{
			if ($docRow['workOrder'] != $rs['valRowWorkOrderValue'])
				$info['changes'][] = ['workOrder' => ['from' => $docRow['workOrder'], 'to' => $rs['valRowWorkOrderValue']]];
			$info['applies'][] = ['workOrder' => $rs['valRowWorkOrderValue']];

			$docRow['workOrder'] = $rs['valRowWorkOrderValue'];
			$this->cntApplies++;
		}

		$this->applyStringValue($rs['valRowTextType'], $rs['valRowTextValue'], 'text', $docRow, $docHead, $info);
		$this->applyStringValue($rs['valRowSymbol1Type'], $rs['valRowSymbol1Value'], 'symbol1', $docRow, $docHead, $info);
		$this->applyStringValue($rs['valRowSymbol2Type'], $rs['valRowSymbol2Value'], 'symbol2', $docRow, $docHead, $info);
		$this->applyStringValue($rs['valRowSymbol3Type'], $rs['valRowSymbol3Value'], 'symbol3', $docRow, $docHead, $info);
	}

	function applyStringValue($setValueType, $settingsValue, $docRowColumnId, &$docRow, $docHead, &$info)
	{
		if ($setValueType === 0)
			return;

		if ($setValueType === 1)
		{
			if ($docRow[$docRowColumnId] != $settingsValue)
				$info['changes'][] = [$docRowColumnId => ['from' => $docRow[$docRowColumnId], 'to' => $settingsValue]];
			$info['applies'][] = [$docRowColumnId => $settingsValue];

			$docRow[$docRowColumnId] = $settingsValue;

			$this->cntApplies++;

			return;
		}

		if ($setValueType === 2)
		{
			$t = new \Shipard\Report\TemplateMustache($this->app());
			$t->data['row'] = $docRow;
			$t->data['head'] = $docHead;
			$value = $t->render($settingsValue);

			if ($docRow[$docRowColumnId] != $value)
				$info['changes'][] = [$docRowColumnId => ['from' => $docRow[$docRowColumnId], 'to' => $value]];
			$info['applies'][] = [$docRowColumnId => $value];

			$docRow[$docRowColumnId] = $value;

			$this->cntApplies++;

			return;
		}
	}

	public function run (&$docRow, $docHead)
	{
		$this->cntApplies = 0;
		$this->usedRowsSettings = [];

		$this->enabledRowsSettingsType = [];
		$allRowsSettingTypes = $this->app()->cfgItem ('e10doc.helpers.rowsSettingsTypes', []);
		foreach ($allRowsSettingTypes as $rstId => $rstCfg)
		{
			if (isset($rstCfg['docType']) && $rstCfg['docType'] !== $docHead['docType'])
				continue;

			$this->enabledRowsSettingsType[] = intval($rstId);
		}

		$this->apply($docRow, $docHead);
	}
}

