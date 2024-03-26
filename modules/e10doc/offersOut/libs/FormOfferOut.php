<?php

namespace e10doc\offersOut\libs;
use \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;


/**
 * class FormOfferOut
 */
class FormOfferOut extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$taxPayer = $this->recData['taxPayer'];
		$paymentMethod = $this->table->app()->cfgItem ('e10.docs.paymentMethods.' . $this->recData['paymentMethod'], 0);
		$useDocKinds = 0;
		if (isset ($this->recData['dbCounter']) && $this->recData['dbCounter'] !== 0)
		{
			$dbCounter = $this->table->app()->cfgItem ('e10.docs.dbCounters.'.$this->recData['docType'].'.'.$this->recData['dbCounter'], FALSE);
			$useDocKinds = Utils::param ($dbCounter, 'useDocKinds', 0);
		}
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$this->openForm (self::ltNone);
			$properties = $this->addList ('properties', '', self::loAddToFormLayout|self::loWidgetParts);
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
			forEach ($properties ['memoInputs'] as $mi)
				$tabs ['tabs'][] = array ('text' => $mi ['text'], 'icon' => $mi ['icon']);

			$this->addAccountingTab ($tabs['tabs']);

			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);

				$this->openTab ();
					$this->layoutOpen (self::ltHorizontal);

					$this->layoutOpen (self::ltForm);
					$this->addColumnInput ('person');
					$this->addColumnInput ('paymentMethod');

					if ($paymentMethod ['cash'])
						$this->addColumnInput ('cashBox');

					$this->addColumnInput ('dateIssue');
					//$this->addColumnInput ('dateDue');
					$this->addColumnInput ('dateAccounting');
					if ($taxPayer)
						$this->addColumnInput ("dateTax");

					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ('centre');
					if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
						$this->addColumnInput ('project');
					if ($this->table->warehouses())
						$this->addColumnInput ('warehouse');
					$this->layoutClose ('width50');

					$this->layoutOpen (self::ltForm);
					if ($taxPayer)
					{
						$this->addColumnInput ('taxCalc');
						$this->addColumnInput ('taxType');
					}
					$this->addCurrency();

					//$this->addColumnInput ('symbol1');
					//$this->addColumnInput ('symbol2');

					$this->addColumnInput ('datePeriodBegin');
					$this->addColumnInput ('datePeriodEnd');

					if ($useDocKinds === 2)
						$this->addColumnInput ('docKind');

					$this->layoutClose ();

					$this->layoutClose ();

					$this->addRecapitulation ();
				$this->closeTab ();

				$this->openTab (self::ltNone);
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
					$this->addColumnInput ('correctiveDoc');
					$this->addColumnInput ('author');
					$this->addColumnInput ('myBankAccount');
					$this->addColumnInput ('owner');
					$this->addColumnInput ('automaticRound');
					$rmRO = $this->recData['automaticRound'] ? self::coReadOnly : 0;
					$this->addColumnInput ('roundMethod', $rmRO);
					if ($taxPayer)
						$this->addColumnInput ('taxPercentDateType');

					if ($useDocKinds !== 2)
						$this->addColumnInput ('docKind');
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
			$this->recData ['taxCalc'] = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->recData['dateAccounting'], $this->recData ['taxCalc']);
		}
		else
		{
			if ($this->recData ['taxCalc'] == 2)
				$this->recData ['taxCalc'] = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->recData['dateAccounting']);
		}
	}

	function columnLabel ($colDef, $options)
	{
		switch ($colDef ['sql'])
		{
			case 'person': return 'Odběratel';
			case 'datePeriodBegin': return 'Platnost od';
			case 'datePeriodEnd': return 'Platnost do';
		}
		return parent::columnLabel ($colDef, $options);
	}
}
