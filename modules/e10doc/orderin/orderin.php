<?php

namespace E10Doc\OrderIn;


use \E10\TableForm, \E10\Application, \E10\utils,
		E10Doc\Core\ShortPaymentDescriptor, \E10Doc\Core\ViewDetailHead, E10Doc\Core\e10utils;


/**
 * Class View
 * @package E10Doc\OrderIn
 */
class View extends \E10Doc\Core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'orderin';
		parent::init();
	}
}


/**
 * Class ViewDetail
 * @package E10Doc\OrderIn
 */
class ViewDetail extends ViewDetailHead
{
}


/**
 * Class Form
 * @package E10Doc\OrderIn
 */
class Form extends \E10Doc\Core\FormHeads
{
	public function renderForm ()
	{
		$taxPayer = $this->recData['taxPayer'];
		$paymentMethod = $this->table->app()->cfgItem ('e10.docs.paymentMethods.' . $this->recData['paymentMethod'], 0);
		$useDocKinds = 0;
		if (isset ($this->recData['dbCounter']) && $this->recData['dbCounter'] !== 0)
		{
			$dbCounter = $this->table->app()->cfgItem ('e10.docs.dbCounters.'.$this->recData['docType'].'.'.$this->recData['dbCounter'], FALSE);
			$useDocKinds = utils::param ($dbCounter, 'useDocKinds', 0);
		}
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);
		$properties = $this->addList ('properties', '', TableForm::loAddToFormLayout|TableForm::loWidgetParts);
		$tabs ['tabs'][] = array ('text' => 'Záhlaví', 'icon' => 'x-content');
		$tabs ['tabs'][] = array ('text' => 'Řádky', 'icon' => 'x-properties');
		forEach ($properties ['memoInputs'] as $mi)
			$tabs ['tabs'][] = array ('text' => $mi ['text'], 'icon' => $mi ['icon']);

		$this->addAccountingTab ($tabs['tabs']);

		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'x-attachments');
		$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'x-wrench');
		$this->openTabs ($tabs, TRUE);

		$this->openTab ();
		$this->layoutOpen (TableForm::ltHorizontal);

		$this->layoutOpen (TableForm::ltForm);
		$this->addColumnInput ("person");
		$this->addColumnInput ("paymentMethod");

		if ($paymentMethod ['cash'])
			$this->addColumnInput ("cashBox");

		$this->addColumnInput ("dateIssue");
		$this->addColumnInput ("docId");
		$this->addColumnInput ("symbol1");
		//$this->addColumnInput ("dateDue");
		//$this->addColumnInput ("dateAccounting");
		//if ($taxPayer)
		//	$this->addColumnInput ("dateTax");

		if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
			$this->addColumnInput ("centre");
		if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
			$this->addColumnInput ('project');
		$this->addColumnInput ('transport');
		if ($this->table->warehouses())
			$this->addColumnInput ('warehouse');
		if ($taxPayer)
		{
			$this->addColumnInput ("taxCalc");
			$this->addColumnInput ("taxType");
		}
		$this->addCurrency();
		$this->layoutClose ('width50');

		$this->layoutOpen (TableForm::ltForm);

		if ($useDocKinds === 2)
			$this->addColumnInput ("docKind");

		$this->addList ('address', '', TableForm::loAddToFormLayout);

		$this->layoutClose ();

		$this->layoutClose ();

		$this->addRecapitulation ();
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
		$this->addList ('rows');
		$this->closeTab ();


		forEach ($properties ['memoInputs'] as $mi)
		{
			$this->openTab ();
			$this->appendCode ($mi ['widgetCode']);
			$this->closeTab ();
		}

		$this->addAccountingTabContent();
		$this->addAttachmentsTabContent ();

		$this->openTab ();
		$this->addColumnInput ("correctiveDoc");
		$this->addColumnInput ("author");
		$this->addColumnInput ("myBankAccount");
		$this->addColumnInput ("owner");
		$this->addColumnInput ("roundMethod");
		if ($taxPayer)
			$this->addColumnInput ("taxPercentDateType");

		if ($useDocKinds !== 2)
			$this->addColumnInput ("docKind");
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ();

		$this->recData ['dateDue'] = new \DateTime ();
		$this->recData ['dateDue']->add (new \DateInterval('P' . Application::cfgItem ('e10.options.dueDays', 14) . 'D'));

		if (!$this->copyDoc)
		{
			$this->recData ['roundMethod'] = intval(Application::cfgItem ('options.e10doc-sale.roundInvoice', 0));
			$this->recData ['taxCalc'] = intval(Application::cfgItem ('options.e10doc-sale.salePricesType', 1));
			$this->recData ['taxCalc'] = e10utils::taxCalcIncludingVATCode ($this->app(), $this->recData['dateAccounting'], $this->recData ['taxCalc']);
		}
	}

	function columnLabel ($colDef, $options)
	{
		switch ($colDef ['sql'])
		{
			case'person': return 'Odběratel';
		}
		return parent::columnLabel ($colDef, $options);
	}
} // class FormInvoices


/**
 * Class FormRows
 * @package E10Doc\OrderIn
 */
class FormRows extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$this->openForm (TableForm::ltGrid);

		$this->openRow ();
			/*if ($this->table->app()->cfgItem ('options.core.useOperations', 0))
			{
				$this->addColumnInput ("operation", TableForm::coColW3);
				$this->addColumnInput ("item", TableForm::coColW9|TableForm::coHeader);
			}
			else*/
				$this->addColumnInput ("item", TableForm::coColW12|TableForm::coHeader);
		$this->closeRow ();

		$this->openRow ();
			$this->addColumnInput ("text", TableForm::coColW12);
		$this->closeRow ();

		$this->openRow ();
			$this->addColumnInput ("quantity", TableForm::coColW3);
			$this->addColumnInput ("unit", TableForm::coColW2);
			$this->addColumnInput ("priceItem", TableForm::coColW3);
			if ($ownerRecData && $ownerRecData ['taxPayer'])
				$this->addColumnInput ("taxCode", TableForm::coColW4);
		$this->closeRow ();

		$this->openRow ();
			if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
				$this->addColumnInput ("centre", TableForm::coColW3);
			if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
				$this->addColumnInput ('project', TableForm::coColW5);
		$this->closeRow ();

		$this->closeForm ();
	}
} // class FormInvoices


/**
 * Class Report
 * @package E10Doc\OrderIn
 */
class Report extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		$this->reportId 			= 'e10doc.orderin.orderin';
		$this->reportTemplate = 'e10doc.orderin.orderin';
	}

	public function loadData ()
	{
		parent::loadData();

		$spayd = new ShortPaymentDescriptor($this->app);
		$spayd->setBankAccount ('CZ', $this->data ['myBankAccount']['bankAccount'], $this->data ['myBankAccount']['iban'], $this->data ['myBankAccount']['swift']);
		$spayd->setAmount ($this->recData ['toPay'], $this->recData ['currency']);
		$spayd->setPaymentSymbols ($this->recData ['symbol1'], $this->recData ['symbol2']);

		$spayd->createString();
		$spayd->createQRCode();

		$this->data ['spayd'] = $spayd;
	}
}
