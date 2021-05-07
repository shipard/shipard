<?php

namespace E10Doc\BankOrder;
use \E10\TableForm, \E10\utils, \E10\DataModel, \E10Doc\Core\ViewDetailHead;


/**
 * Prohlížeč Příkazů k úhradě
 *
 * Class ViewBankOrderDocs
 * @package E10Doc\Bank
 */
class ViewBankOrders extends \E10Doc\Core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'bankorder';
		parent::init();

		$this->bankAccounts = $this->table->app()->cfgItem ('e10doc.bankAccounts', array());
		$activeBankAccount = key($this->bankAccounts);
		forEach ($this->bankAccounts as $bankAccountNdx => $r)
			$bt [] = array ('id' => $bankAccountNdx, 'title' => $r['shortName'], 'active' => ($bankAccountNdx == $activeBankAccount),
											'addParams' => array ('person' => $r['bank'], 'myBankAccount' => $bankAccountNdx, 'currency' => $r['curr']));
		$this->setBottomTabs ($bt);
	}

	public function createMainQueries ()
	{
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'archive', 'title' => 'Archív'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$myBankAccount = intval($this->bottomTabId ());

		$q [] = 'SELECT heads.ndx, [docNumber], [title], [initBalance], [balance], [debit], [credit], [docOrderNumber], [dateDue],
							[dateIssue], [dateAccounting], docType, heads.docState, heads.docStateMain FROM [e10doc_core_heads] as heads
							LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx
							WHERE 1';

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// -- myBankAccount
		if ($myBankAccount)
      array_push ($q, " AND heads.[myBankAccount] = %s", $myBankAccount);

		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND heads.[docStateMain] < 4");

		if ($mainQuery == 'archive')
			array_push ($q, " AND heads.[docStateMain] = 5");

		if ($mainQuery == 'trash')
      array_push ($q, " AND heads.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [datePeriodBegin] DESC' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [docNumber] DESC' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->icon;
		$listItem ['t1'] = utils::datef($item['dateDue'], '%d');
		$listItem ['i1'] = ['icon' => 'icon-minus-square', 'text' => utils::nf ($item['debit'], 2)];

		if ($item['credit'] != 0.0)
			$listItem ['i2'] = ['icon' => 'icon-plus-square', 'text' => utils::nf ($item['credit'], 2)];

		$listItem ['t3'] = $item ['title'];

		$props [] = ['icon' => 'icon-file', 'text' => $item ['docNumber']];

		$listItem ['t2'] = $props;
		return $listItem;
	}
}


/**
 * Class ViewDetailBankOrder
 * @package E10Doc\BankOrder
 */
class ViewDetailBankOrder extends ViewDetailHead
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.bankorder.dc.Detail');
	}
}


/**
 * Editační formulář Příkazu k úhradě
 *
 * Class FormBankOrder
 * @package E10Doc\Bank
 */
class FormBankOrder extends \E10Doc\Core\FormHeads
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);
			$tabs ['tabs'][] = array ('text' => 'Záhlaví', 'icon' => 'x-content');
			$tabs ['tabs'][] = array ('text' => 'Řádky', 'icon' => 'x-properties');
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'x-attachments');
			$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'x-wrench');
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
			$this->layoutOpen (TableForm::ltHorizontal);
				$this->layoutOpen (TableForm::ltForm);
					$this->addColumnInput ("dateDue");
				$this->layoutClose ();
			$this->layoutClose ();

			$this->layoutOpen (TableForm::ltGrid);
				$this->addColumnInput ('title', TableForm::coColW12);
				$this->addList ('doclinks', '', TableForm::loAddToFormLayout|TableForm::coColW12);
			$this->layoutClose ();

      $this->closeTab ();

			$this->openTab ();
				$this->addList ('rows');
			$this->closeTab ();

			$this->addAccountingTabContent();
			$this->addAttachmentsTabContent ();

			$this->openTab ();
				$this->addColumnInput ("person");
				$this->addColumnInput ("myBankAccount");
				$this->addColumnInput ("currency");
				$this->addColumnInput ("author");
			$this->closeTab ();

      $this->closeTabs ();

		$this->closeForm ();
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ();
		$this->recData ['dateDue'] = new \DateTime ();
	}
}


/**
 * Editační formulář řádku Příkazu k úhradě
 *
 * Class FormBankOrderRow
 * @package E10Doc\Bank
 */
class FormBankOrderRow extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$operation = $this->table->app()->cfgItem ('e10.docs.operations.' . $this->recData ['operation'], FALSE);

		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ("symbol1", TableForm::coColW3);
				$this->addColumnInput ("symbol2", TableForm::coColW2);
				$this->addColumnInput ("symbol3", TableForm::coColW2);
				$this->addColumnInput ("bankAccount", TableForm::coColW5);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ("person", TableForm::coColW6);
				$this->addColumnInput ("text", TableForm::coColW6);
			$this->closeRow ();

			$this->openRow ();
				$this->addColumnInput ('priceItem', TableForm::coColW3);
				$this->addColumnInput ('dateDue', TableForm::coColW3);
				$this->addColumnInput ('operation', TableForm::coColW3|DataModel::coSaveOnChange);
//				if ($ownerRecData ['currency'] != $ownerRecData ['homeCurrency'])
//					$this->addColumnInput ("exchangeRate", TableForm::coColW3);
			$this->closeRow ();

		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'dateDue': return 'Datum';
			case	'priceItem': return 'Částka';
    }
    return parent::columnLabel ($colDef, $options);
  }
}

/**
 * BankOrderReport
 *
 * Výstupní sestava příkazů k úhradě / inkasu
 *
 *
 */

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

		$dateIssue = utils::datef($this->recData['dateIssue'], '%d');
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
			if (utils::dateIsBlank($dateDue))
				$dateDue = $this->recData['dateIssue'];
			if (!utils::dateIsBlank($docRow['dateDue']))
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
			$row['priceAll'] = utils::nf ($docRow['priceAll'], 2).' '.$currency;
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
			$this->data['pages'][$k]['totalPrice'] = utils::nf ($totalPrice, 2).' '.$currency;
			$this->data['pages'][$k]['totalPriceForExport'] = round($totalPrice*100, 0);
			$dateDue = utils::datef($this->data['pages'][$k]['dateDue'], '%d');
			$this->data['pages'][$k]['shortDateDue'] = substr($dateDue, 0, 2).substr($dateDue, 3, 2).substr($dateDue, 8, 2);
		}
	}

	public function createToolbarSaveAs (&$printButton)
	{
		if ($this->data['giro'])
		{
			$printButton['dropdownMenu'][] = [
			'text' => 'Export příkazu k úhradě (.kpc)', 'icon' => 'icon-download',
			'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/bank-order-giro-kpc', 'data-filename' => $this->saveAsFileName('cz/bank-order-giro-kpc'),
			'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.bankorder.BankOrderReport', 'data-pk' => $this->recData['ndx']
			];
		}
		if ($this->data['directDebit'])
		{
			$printButton['dropdownMenu'][] = [
				'text' => 'Export příkazu k inkasu (.kpc)', 'icon' => 'icon-download',
				'type' => 'action', 'action' => 'print', 'data-saveas' => 'cz/bank-order-direct-debit-kpc', 'data-filename' => $this->saveAsFileName('cz/bank-order-direct-debit-kpc'),
				'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.bankorder.BankOrderReport', 'data-pk' => $this->recData['ndx']
			];
		}
	}
	public function saveReportAs ()
	{
		$data = $this->renderTemplate ($this->reportTemplate, $this->saveAs);

		$fn = utils::tmpFileName ('kpc');
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

} // class BankOrderReport

