<?php

namespace e10doc\balance;

use \e10\utils, \e10\Utility;


/**
 * Class BalanceDocumentInfo
 * @package E10Doc\Balance
 */
class BalanceDocumentInfo extends Utility
{
	var $docRecData;
	var $valid = FALSE;

	var $primaryBalanceId = 0;
	var $paymentTotal = 0.0;
	var $restAmount = 0.0;
	var $daysOver = 0;
	var $lastPayment = NULL;
	var $stateClass;
	var $tools = [];

	public function setDocRecData ($recData)
	{
		$this->docRecData = $recData;
	}

	public function run ()
	{
		$docType = $this->app->cfgItem ('e10.docs.types.'.$this->docRecData['docType'], FALSE);
		$this->primaryBalanceId = utils::param($docType, 'primaryBalance', FALSE);
		if ($this->primaryBalanceId === FALSE)
			return;

		$this->valid = TRUE;

		if ($this->docRecData['paymentMethod'] === 1)
		{ // cash
			$this->stateClass = 'e10-row-plus';
			return;
		}

		$pairId = "{$this->primaryBalanceId}_{$this->docRecData['personBalance']}_{$this->docRecData['symbol1']}_{$this->docRecData['symbol2']}_{$this->docRecData['currency']}";
		$q[] = 'SELECT * FROM [e10doc_balance_journal] WHERE';
		array_push ($q, ' [pairId] = %s', $pairId);

		$rows = $this->db()->query ($q);

		$payments = [];
		foreach ($rows as $r)
		{
			if ($r['payment'] !== 0.0)
			{
				$this->paymentTotal += $r['payment'];
				$this->lastPayment = ['date' => $r['date'], 'amount' => $r['payment']];
				$payments[] = $this->lastPayment;
			}
		}

		$this->restAmount = $this->docRecData['toPay'] - $this->paymentTotal;

		$today = utils::today();
		$this->daysOver = - utils::dateDiff ($today, $this->docRecData['dateDue']);
		$this->stateClass = 'e10-warning0';

		$line = [];

		$line[] = ['text' => utils::datef($this->docRecData['dateDue']), 'icon' => 'system/iconBalance'];

		if ($this->restAmount < 1.0)
		{
			//$line[] = ['text' => 'UHRAZENO v plné výši', 'icon' => 'icon-check-square'];
			$this->stateClass = 'e10-row-plus';
		}
		else
		if ($this->restAmount == $this->docRecData['toPay'])
		{
			//$line[] = ['text' => 'NEUHRAZENO', 'icon' => ($this->daysOver > 0) ? 'icon-exclamation' : 'system/iconCheck', 'class' => 'e10-linePart h1'];
		}
		else
		{
			//$line[] = ['text' => '', 'icon' => 'system/iconCheck', 'class' => 'e10-linePart h1'];
			//$line[] = ['text' => 'ČÁSTEČNĚ UHRAZENO', 'prefix' => utils::nf($this->paymentTotal / $this->docRecData['toPay'] * 100, 0).' %', 'class' => 'e10-none'];
		}

		if ($this->restAmount > 1.0)
		{
			if ($this->daysOver > 30)
				$this->stateClass = 'e10-warning3';
			else
			if ($this->daysOver > 15)
				$this->stateClass = 'e10-warning2';
			else
			if ($this->daysOver > 2)
				$this->stateClass = 'e10-warning1';

			if ($this->daysOver > 1 && $this->primaryBalanceId == 1000)
			{
				$btn = ['type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => 'Upomínka', 'data-report' => 'e10doc.balance.RequestForPayment',
								'data-table' => 'e10.persons.persons', 'data-pk' => $this->docRecData['person'], 'actionClass' => 'btn-xs', 'class' => 'pull-right'];
				$btn['subButtons'] = [];
				$btn['subButtons'][] = [
					'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-default btn-xs',
					'data-table' => 'e10.persons.persons', 'data-pk' => $this->docRecData['person'], 'data-class' => 'Shipard.Report.SendFormReportWizard',
					'data-addparams' => 'reportClass=' . 'e10doc.balance.RequestForPayment' . '&documentTable=' . 'e10.persons.persons'
				];

				$this->tools[] = $btn;
			}
		}
	}
}

