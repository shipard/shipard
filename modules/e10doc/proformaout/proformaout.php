<?php

namespace E10Doc\ProformaOut;

use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\utils;
use E10Doc\Core\e10utils;
use E10Doc\Core\ShortPaymentDescriptor;
use \E10Doc\Core\ViewDetailHead;


/**
 * Pohled na Zálohové Faktury vydané
 *
 */

class View extends \E10Doc\Core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'invpo';
		parent::init();
	}
}


/**
 * Základní detail Zálohové Faktury vydané
 *
 */

class ViewDetail extends ViewDetailHead
{
}


/**
 * Editační formulář Zálohové Faktury vydané
 *
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
		$tabs ['tabs'][] = array ('text' => 'Záhlaví', 'icon' => 'system/formHeader');
		$tabs ['tabs'][] = array ('text' => 'Řádky', 'icon' => 'system/formRows');
		forEach ($properties ['memoInputs'] as $mi)
			$tabs ['tabs'][] = array ('text' => $mi ['text'], 'icon' => $mi ['icon']);

		$this->addAccountingTab ($tabs['tabs']);

		$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
		$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'system/formSettings');
		$this->openTabs ($tabs, TRUE);

		$this->openTab ();
		$this->layoutOpen (TableForm::ltHorizontal);

		$this->layoutOpen (TableForm::ltForm);
		$this->addColumnInput ("person");
		$this->addColumnInput ("paymentMethod");

		if ($paymentMethod ['cash'])
			$this->addColumnInput ("cashBox");

		$this->addColumnInput ("dateIssue");
		$this->addColumnInput ("dateDue");
		$this->addColumnInput ("dateAccounting");
		if ($taxPayer)
			$this->addColumnInput ("dateTax");

		if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
			$this->addColumnInput ("centre");
		if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
			$this->addColumnInput ('project');
		if ($this->table->warehouses())
			$this->addColumnInput ('warehouse');
		$this->layoutClose ('width50');

		$this->layoutOpen (TableForm::ltForm);
		if ($taxPayer)
		{
			$this->addColumnInput ("taxCalc");
			$this->addColumnInput ("taxType");
		}
		$this->addCurrency();

		$this->addColumnInput ("symbol1");
		$this->addColumnInput ("symbol2");

		$this->addColumnInput ("datePeriodBegin");
		$this->addColumnInput ("datePeriodEnd");

		if ($useDocKinds === 2)
			$this->addColumnInput ("docKind");

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
		$this->addColumnInput ('automaticRound');
		$rmRO = $this->recData['automaticRound'] ? self::coReadOnly : 0;
		$this->addColumnInput ('roundMethod', $rmRO);
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
		$dd = intval($this->app()->cfgItem ('options.e10doc-sale.dueDays', 14));
		if (!$dd)
			$dd = 14;
		$this->recData ['dateDue']->add (new \DateInterval('P'.$dd.'D'));

		$this->recData ['automaticRound'] = intval($this->app()->cfgItem ('options.e10doc-sale.automaticRoundOnSale', 0));

		if (!$this->copyDoc)
		{
			$this->recData ['roundMethod'] = intval($this->app()->cfgItem ('options.e10doc-sale.roundInvoice', 0));
			$this->recData ['taxCalc'] = intval($this->app()->cfgItem ('options.e10doc-sale.salePricesType', 1));
			$this->recData ['taxCalc'] = e10utils::taxCalcIncludingVATCode ($this->app(), $this->recData['dateAccounting'], $this->recData ['taxCalc']);
		}
		else
		{
			if ($this->recData ['taxCalc'] == 2)
				$this->recData ['taxCalc'] = e10utils::taxCalcIncludingVATCode ($this->app(), $this->recData['dateAccounting']);
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
 * Editační formulář Řádku Faktury vydané
 *
 */

class FormRows extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$this->openForm (TableForm::ltGrid);
		$this->addColumnInput ('itemType', TableForm::coHidden);
		$this->addColumnInput ('itemBalance', TableForm::coHidden);
		$this->addColumnInput ('itemIsSet', TableForm::coHidden);

		$this->openRow ();
			$this->addColumnInput ("operation", TableForm::coColW3);
			$this->addColumnInput ("item", TableForm::coColW9|TableForm::coHeader);
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
 * @package E10Doc\ProformaOut
 */
class Report extends \e10doc\core\libs\reports\DocReport
{
	function init ()
	{
		parent::init();

		$this->setReportId('reports.default.e10doc.proformaOut.invpo');
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
