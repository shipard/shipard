<?php

namespace e10doc\debs\libs\spreadsheets;
use \e10doc\debs\libs\AccDataConnector;
use e10\utils;


/**
 * Class SpdAccCore
 * @package pkgs\accounting\debs
 */
class SpdAccCore extends \lib\spreadsheets\Spreadsheet
{
	protected $dataSet;

	var $fullSpreadsheetId = '';

	var $spdAccountsSettings;
	var $spdManualAccounts;
	var $usedAccounts = [];
	var $tableAccounts = [];

	function dataConnector()
	{
		return new AccDataConnector($this->app);
	}

	protected function initSpdSettings ()
	{
		$this->spdAccountsSettings = [];
		$this->spdManualAccounts = [];

		$q [] = 'SELECT * FROM [e10doc_debs_spdAccounts] WHERE 1';
		array_push ($q, 'AND [docStateMain] < %i', 4);
		array_push ($q, 'AND [spreadsheetId] = %s', $this->spreadsheetId);

		$rows = $this->app()->db()->query ($q);
		foreach ($rows as $r)
		{
			$am = explode (' ', $r['accountsMask']);
			if (!count($am))
				continue;

			foreach ($am as $accountMask)
			{
				$tam = trim ($accountMask);
				$this->spdManualAccounts[$tam] = 1;
				$this->spdAccountsSettings[$r['spreadsheetId']][$r['spreadsheetTable']][$r['spreadsheetRow']][$r['spreadsheetCol']][] = $tam;
			}
		}
	}

	protected function initCellListItemsPattern ($tableIdx, $rowIdx, $colIdx, $cellPattern)
	{
		if (
			isset($this->spdAccountsSettings[$this->spreadsheetId]) &&
			isset($this->spdAccountsSettings[$this->spreadsheetId][$tableIdx]) &&
			isset($this->spdAccountsSettings[$this->spreadsheetId][$tableIdx][$rowIdx]) &&
			isset($this->spdAccountsSettings[$this->spreadsheetId][$tableIdx][$rowIdx][$colIdx]) )
		{
			$cp = $cellPattern;
			foreach ($this->spdAccountsSettings[$this->spreadsheetId][$tableIdx][$rowIdx][$colIdx] as $accId)
			{
				if ($cp !== '')
					$cp .= ' ';
				$cp .= '!'.$accId;
			}

			if ($cp != '')
				return '=['.$cp.']';
		}

		return parent::initCellListItemsPattern ($tableIdx, $rowIdx, $colIdx, $cellPattern);
	}

	protected function evalCellSumListItem ($cell, $table)
	{
		$res = 0;
		$exp = [];

		$minus = FALSE;
		$signMark = '';
		$accId = $cell;
		$customAccount = FALSE;

		if ($accId[0] === '!')
		{
			$accId = substr ($accId, 1);
			$customAccount = TRUE;
		}

		$cellExplainId = $accId;

		if (!$customAccount && isset($this->spdManualAccounts[$accId]))
		{
			$exp[] = ['value' => $res, 'text' => $cellExplainId, '_options' => ['class' => 'e10-small e10-del']];
			$result = ['result' => $res, 'explain' => $exp];
			return $result;
		}

		if ($accId[0] === '-')
		{
			$accId = substr ($accId, 1);
			$minus = TRUE;
			$signMark = '-';
		}

		$tid = $table['tableId'];
		if (isset($this->tableAccounts[$tid][$accId]))
			$this->tableAccounts[$tid][$accId]++;
		else
			$this->tableAccounts[$tid][$accId] = 1;

		$accKind = FALSE;
		$accKindEnumValue = 0;
		if ($accId[0] === 'a' || $accId[0] === 'p')
		{
			$accKind = ($accId[0] === 'a') ? '_'.$this->dataSet->ackAssets : '_'.$this->dataSet->ackLiabilities;
			$accKindEnumValue = ($accId[0] === 'a') ? /*0*/$this->dataSet->ackAssets : /*1*/$this->dataSet->ackLiabilities;
			$accId = substr ($accId, 1);
		}

		if ($accKind !== FALSE)
		{
			if (isset ($this->dataSet->allData[$accId]) && ($this->dataSet->allData[$accId]['accountKind'] == $accKindEnumValue))
			{
				$res = ($minus) ? -$this->dataSet->allData[$accId]['endState'] : $this->dataSet->allData[$accId]['endState'];
				$accClass = 'e10-small';
				if ($customAccount)
					$accClass .= ' e10-row-this';
				$exp[] = ['value' => $res, 'text' => $cellExplainId, '_options' => ['class' => $accClass]];
				$this->addUsedAccount($accId, $exp);
			}
			else
			{
				$subExp = [];
				$res = 0.0;
				$sl = strlen($accId);
				foreach ($this->dataSet->allData as $accountId => $acc)
				{
					if (substr($accountId, 0, $sl) !== $accId || $acc['accountKind'] != $accKindEnumValue)
						continue;

					$value = ($minus) ? -$acc['endState']:$acc['endState'];

					if (!$customAccount && $this->isExcludedManualAccount ($accId, $accountId))
					{
						$subExp[] = ['value' => $value, 'text' => $signMark.$accountId, '_options' => ['class' => 'e10-small e10-del']];
						continue;
					}

					$res += $value;

					$accClass = 'e10-small';
					if ($customAccount)
						$accClass .= ' e10-row-this';
					$subExp[] = ['value' => $value, 'text' => $signMark.$accountId, '_options' => ['class' => $accClass]];

					$this->addUsedAccount($accountId, $subExp);
				}
				if (count($subExp))
				{
					$expItem = ['value' => $res, 'text' => $cellExplainId];
					if ($customAccount)
						$expItem['_options'] = ['class' => 'e10-row-this'];
					$exp[] = $expItem;
					$exp = array_merge($exp, $subExp);
				}
				else
				{
					$exp[] = ['value' => '', 'text' => $cellExplainId, '_options' => ['class' => 'e10-off']];
				}
			}
		}
		else
		{
			if (isset ($this->dataSet->allData[$accId]))
			{
				$res = ($minus) ? -$this->dataSet->allData[$accId]['endState'] : $this->dataSet->allData[$accId]['endState'];

				$accClass = 'e10-small';
				if ($customAccount)
					$accClass .= ' e10-row-this';
				$exp[] = ['value' => $res, 'text' => $cellExplainId, '_options' => ['class' => $accClass]];

				$this->addUsedAccount($accId, $exp);
			}
			else
			{
				$subExp = [];
				$res = 0.0;
				$sl = strlen($accId);
				foreach ($this->dataSet->allData as $accountId => $acc)
				{
					if (substr($accountId, 0, $sl) !== $accId)
						continue;

					$value = ($minus) ? -$acc['endState']:$acc['endState'];

					if (!$customAccount && $this->isExcludedManualAccount ($accId, $accountId))
					{
						$subExp[] = ['value' => $value, 'text' => $signMark.$accountId, '_options' => ['class' => 'e10-small e10-del']];
						continue;
					}
					$res += $value;

					$accClass = 'e10-small';
					if ($customAccount)
						$accClass .= ' e10-row-this';
					$subExp[] = ['value' => $value, 'text' => $signMark.$accountId, '_options' => ['class' => $accClass]];

					$this->addUsedAccount($accountId, $subExp);
				}
				if (count($subExp))
				{
					$expItem = ['value' => $res, 'text' => $cellExplainId];
					if ($customAccount)
						$expItem['_options'] = ['class' => 'e10-row-this'];
					$exp[] = $expItem;

					$exp = array_merge($exp, $subExp);
				}
				else
				{
					$exp[] = ['value' => '', 'text' => $cellExplainId, '_options' => ['class' => 'e10-off']];
				}
			}
		}

		$result = ['result' => $res, 'explain' => $exp];
		return $result;
	}

	function addUsedAccount ($accountId, &$explain)
	{
		if (isset($this->usedAccounts[$accountId]))
			$this->usedAccounts[$accountId]++;
		else
			$this->usedAccounts[$accountId] = 1;
	}

	function isExcludedManualAccount ($accMask, $accountId)
	{
		if (isset($this->spdManualAccounts[$accountId]))
			return TRUE;

		if (isset($this->spdManualAccounts[$accMask]))
			return TRUE;

		foreach ($this->spdManualAccounts as $manualAccountMask => $manualAccountCnt)
		{
			$wholeAccountMask = strval($manualAccountMask);

			if ($wholeAccountMask[0] === '-')
				$wholeAccountMask = substr($wholeAccountMask, 1);

			$accKind = FALSE;
			$accKindEnumValue = 0;
			if ($wholeAccountMask[0] === 'a' || $wholeAccountMask[0] === 'p')
			{
				$accKind = ($wholeAccountMask[0] === 'a') ? '_'.$this->dataSet->ackAssets : '_'.$this->dataSet->ackLiabilities;
				$accKindEnumValue = ($wholeAccountMask[0] === 'a') ? $this->dataSet->ackAssets : $this->dataSet->ackLiabilities;
				$wholeAccountMask = substr($wholeAccountMask, 1);
			}

			$sl = strlen($accMask);
			if (substr($wholeAccountMask, 0, $sl) === $accMask)
			{
				$slm = strlen ($wholeAccountMask);

				if ($accKind !== FALSE)
				{
					if ($this->dataSet->allData[$accountId]['accountKind'] == $accKindEnumValue && substr($accountId, 0, $slm) == $wholeAccountMask)
						return TRUE;
				}
				else
				{
					if (substr($accountId, 0, $slm) == $wholeAccountMask)
						return TRUE;
				}
			}
		}

		return FALSE;
	}
}
