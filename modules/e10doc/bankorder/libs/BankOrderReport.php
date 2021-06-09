<?php

namespace e10doc\bankOrder\libs;
use \Shipard\Utils\Utils;


class BankOrderReport extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId = 'e10doc.bankorder.bankorder';
		$this->reportTemplate = 'e10doc.bankorder.bankorder';
	}

	public function loadData ()
	{
		parent::loadData();

		$this->data['giro'] = FALSE;
		$this->data['directDebit'] = FALSE;

		$dateIssue = Utils::datef($this->recData['dateIssue'], '%d');
		$this->data['export']['shortDateIssue'] = substr($dateIssue, 0, 2).substr($dateIssue, 3, 2).substr($dateIssue, 8, 2);
		$this->data['export']['shortDocNumber'] = substr($this->recData['docNumber'], -3);

		$separatorPos = strrpos($this->data['myBankAccount']['bankAccount'], "/");
		if ($separatorPos === false)
		{
			$this->data['myBankAccount']['shortBankAccount'] = $this->data ['myBankAccount']['bankAccount'];
			$this->data['myBankAccount']['bankCode'] = '';
		}
		else
		{
			$this->data['myBankAccount']['shortBankAccount'] = substr ($this->data ['myBankAccount']['bankAccount'], 0, $separatorPos);
			$this->data['myBankAccount']['bankCode'] = substr ($this->data ['myBankAccount']['bankAccount'], $separatorPos+1);
		}

		$separatorPos = strrpos($this->data['myBankAccount']['shortBankAccount'], "-");
		if ($separatorPos === false)
		{
			$this->data['myBankAccount']['prefixBankAccount'] = '';
			$this->data['myBankAccount']['numberBankAccount'] = $this->data ['myBankAccount']['shortBankAccount'];
		}
		else
		{
			$this->data['myBankAccount']['prefixBankAccount'] = substr ($this->data ['myBankAccount']['shortBankAccount'], 0, $separatorPos);
			$this->data['myBankAccount']['numberBankAccount'] = substr ($this->data ['myBankAccount']['shortBankAccount'], $separatorPos+1);
		}

		$this->data['export']['blankABOSymbol2'] = ' ';
		if ($this->data['myBankAccount']['bankCode'] === '2010')
			$this->data['export']['blankABOSymbol2'] = '0'; // FIO ABO change 2015-11-12

		$this->data['pages'] = array();
		$newPage = false;
		$currency = $this->app->cfgItem ('e10.base.currencies.'.$this->data ['myBankAccount']['currency'].'.shortcut');
		foreach ($this->data['rows'] as $docRow)
		{
			$dateDue = $this->recData['dateDue'];
			if (Utils::dateIsBlank($dateDue))
				$dateDue = $this->recData['dateIssue'];
			if (!Utils::dateIsBlank($docRow['dateDue']))
				$dateDue = $docRow['dateDue'];
			$directDebit = 0;
			if ($docRow['operation'] == 1030102)
			{
				$directDebit = 1;
				$this->data['directDebit'] = TRUE;
			}
			else
				$this->data['giro'] = TRUE;

			$keyPage = count($this->data['pages']);
			foreach ($this->data['pages'] as $k => $p)
			{
				if ($p['dateDue'] == $dateDue && $p['directDebit'] == $directDebit)
				{
					$keyPage = $k;
					break;
				}
			}

			if (!isset($this->data['pages'][$keyPage]))
			{
				$page = array();
				$page['dateDue'] = $dateDue;
				$page['directDebit'] = $directDebit;
				$page['myBankAccount']['shortBankAccount'] = $this->data['myBankAccount']['shortBankAccount'];
				$page['myBankAccount']['prefixBankAccount'] = $this->data['myBankAccount']['prefixBankAccount'];
				$page['myBankAccount']['numberBankAccount'] = $this->data['myBankAccount']['numberBankAccount'];
				$page['myBankAccount']['bankCode'] = $this->data['myBankAccount']['bankCode'];
				$page['myBankPerson']['fullName'] = $this->data['myBankPerson']['fullName'];
				$page['newPage'] = $newPage;
				$newPage = TRUE;
				$this->data['pages'][$keyPage] = $page;
			}

			$row = array();
			$bankacc = $docRow['bankAccount'];
			$separatorPos = strrpos($bankacc, "/");
			if ($separatorPos === FALSE)
			{
				$row['shortBankAccount'] = $bankacc;
				$row['bankCode'] = '';
			}
			else
			{
				$row['shortBankAccount'] = substr ($bankacc, 0, $separatorPos);
				$row['bankCode'] = substr ($bankacc, $separatorPos+1);
			}

			$separatorPos = strrpos($row['shortBankAccount'], "-");
			if ($separatorPos === false)
			{
				$row['prefixBankAccount'] = '';
				$row['numberBankAccount'] = $row['shortBankAccount'];
			}
			else
			{
				$row['prefixBankAccount'] = substr ($row['shortBankAccount'], 0, $separatorPos);
				$row['numberBankAccount'] = substr ($row['shortBankAccount'], $separatorPos+1);
			}

			$row['text'] = $docRow['text'];
			$row['symbol1'] = $docRow['symbol1'];
			$row['symbol2'] = $docRow['symbol2'];
			$row['symbol3'] = $docRow['symbol3'];
			$row['price'] = $docRow['priceAll'];
			$row['priceAll'] = Utils::nf ($docRow['priceAll'], 2).' '.$currency;
			$row['priceForExport'] = round($docRow['priceAll']*100, 0);
			$this->data['pages'][$keyPage]['rows'][] = $row;
		}

		foreach ($this->data['pages'] as $k => $p)
		{
			if (count($p['rows']) == 1)
				$this->data['pages'][$k]['oneRow'] = TRUE;
			else
				$this->data['pages'][$k]['oneRow'] = FALSE;
			$totalPrice = 0;
			foreach ($p['rows'] as $r)
				$totalPrice += $r['price'];
			$this->data['pages'][$k]['totalPrice'] = Utils::nf ($totalPrice, 2).' '.$currency;
			$this->data['pages'][$k]['totalPriceForExport'] = round($totalPrice*100, 0);
			$dateDue = Utils::datef($this->data['pages'][$k]['dateDue'], '%d');
			$this->data['pages'][$k]['shortDateDue'] = substr($dateDue, 0, 2).substr($dateDue, 3, 2).substr($dateDue, 8, 2);
		}
	}

	public function createToolbarSaveAs (&$printButton)
	{
		if ($this->data['giro'])
		{
			$printButton['dropdownMenu'][] = [
			'text' => 'Export příkazu k úhradě (.kpc)', 'icon' => 'system/actionDownload',
			'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/bank-order-giro-kpc', 'data-filename' => $this->saveAsFileName('cz/bank-order-giro-kpc'),
			'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.bankorder.BankOrderReport', 'data-pk' => $this->recData['ndx']
			];
		}
		if ($this->data['directDebit'])
		{
			$printButton['dropdownMenu'][] = [
				'text' => 'Export příkazu k inkasu (.kpc)', 'icon' => 'system/actionDownload',
				'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/bank-order-direct-debit-kpc', 'data-filename' => $this->saveAsFileName('cz/bank-order-direct-debit-kpc'),
				'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.bankorder.BankOrderReport', 'data-pk' => $this->recData['ndx']
			];
		}
	}
	public function saveReportAs ()
	{
		$data = $this->renderTemplate ($this->reportTemplate, $this->saveAs);

		$fn = Utils::tmpFileName ('kpc');
		file_put_contents($fn, $data);
		$this->fullFileName = $fn;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'text/plain';
	}

	public function saveAsFileName ($type)
	{
		$fn = 'Příkaz-';
		switch ($type)
		{
			case 'cz/bank-order-giro-kpc': 					$fn .= 'k-úhradě-'; break;
			case 'cz/bank-order-direct-debit-kpc':	$fn .= 'k-inkasu-'; break;
		}
		$fn .= $this->recData['docNumber'].'.kpc';
		return $fn;
	}

}
