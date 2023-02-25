<?php

namespace e10doc\invoicesIn\libs;
use \Shipard\Utils\Utils;

class FormInvoiceIn extends \e10doc\core\FormHeads
{
	var $useAttInfoPanel = 0;

	public function renderForm ()
	{
		$this->checkInfoPanelAttachments();
		$taxPayer = $this->recData['taxPayer'];
		$paymentMethod = $this->table->app()->cfgItem ('e10.docs.paymentMethods.' . $this->recData['paymentMethod'], 0);
		$useDocKinds = 0;
		if (isset ($this->recData['dbCounter']) && $this->recData['dbCounter'] !== 0)
		{
			$dbCounter = $this->table->app()->cfgItem ('e10.docs.dbCounters.'.$this->recData['docType'].'.'.$this->recData['dbCounter'], FALSE);
			$useDocKinds = Utils::param ($dbCounter, 'useDocKinds', 0);
		}

		$usePropertyExpenses = $this->table->app()->cfgItem ('options.property.usePropertyExpenses', 0);

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
			$this->layoutOpen (self::ltHorizontal);
				$this->layoutOpen (self::ltForm);
					$this->addColumnInput ("person");
					$this->addColumnInput ("paymentMethod");

					if ($paymentMethod ['cash'])
						$this->addColumnInput ("cashBox");

					$this->addColumnInput ("bankAccount");
					$this->addColumnInput ("symbol1");
					$this->addColumnInput ("symbol2");
					$this->addColumnInput ("dateIssue");
					$this->addColumnInput ("dateDue");
          $this->addColumnInput ("dateAccounting");
					if ($taxPayer)
					{
						if ($this->recData['taxCalc'])
						{
							$this->openRow();
								$this->addColumnInput("dateTax");
								$this->addColumnInput('dateTaxDuty');
							$this->closeRow();
						}
						$this->addColumnInput ('docId');
					}
				$this->layoutClose ();

				$this->layoutOpen (self::ltForm);
					if ($taxPayer)
					{
						if ($this->useMoreVATRegs())
						{
							$this->addColumnInput ('vatReg');
							if ($this->vatRegs[$this->recData['vatReg']]['payerKind'] === 1)
								$this->addColumnInput ('taxCountry');
						}

						$this->addColumnInput ("taxCalc");
						if ($this->recData['taxCalc'])
						{
							$this->addColumnInput ("taxMethod");
							$this->addColumnInput ("taxType");
						}
					}
					$this->addCurrency();

          $this->addColumnInput ("roundMethod");

					if ($useDocKinds === 2)
						$this->addColumnInput ("docKind");

					$this->addSeparator();

					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ('centre');
					if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
						$this->addColumnInput ('workOrder');
					if ($usePropertyExpenses)
						$this->addColumnInput ('property');
					if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
						$this->addColumnInput ('project');
					if ($this->table->warehouses())
						$this->addColumnInput ('warehouse');
				$this->layoutClose ();

			$this->layoutClose ();

			$this->addRecapitulation ();

    	$this->closeTab ();

			$this->openTab ();
					$this->addList ('rows');
			$this->closeTab ();

			$this->addAccountingTabContent();
			$this->addAttachmentsTabContent ();

			$this->openTab ();
				$this->addColumnInput ("author");
				if ($taxPayer)
					$this->addVatSettingsIn ();
				if ($useDocKinds !== 2)
					$this->addColumnInput ("docKind");
			$this->closeTab ();

			$this->closeTabs ();

		$this->closeForm ();

		$this->addInfoPanelAttachments();
  }

	public function checkNewRec ()
	{
		parent::checkNewRec ();

		if (!isset($this->recData ['dateDue']) || Utils::dateIsBlank($this->recData ['dateDue']))
		{
			$this->recData ['dateDue'] = new \DateTime ();
			$this->recData ['dateDue']->add (new \DateInterval('P' . $this->app()->cfgItem ('e10.options.dueDays', 14) . 'D'));
		}
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'person': return 'Dodavatel';
			case	'personVATIN': return 'DIČ dodavatele';
		}
    return parent::columnLabel ($colDef, $options);
  }
}

