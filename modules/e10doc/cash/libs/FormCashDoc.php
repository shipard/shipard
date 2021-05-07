<?php

namespace e10doc\cash\libs;
use \Shipard\Application\DataModel;
use e10doc\core\libs\E10Utils;



class FormCashDoc extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$taxPayer = $this->recData['taxPayer'];

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$useProperty = 0;
		if ($this->recData['cashBoxDir'] == 2)
			$useProperty = $this->table->app()->cfgItem ('options.property.usePropertyExpenses', 0);

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = array ('text' => 'Záhlaví', 'icon' => 'x-content');
			$tabs ['tabs'][] = array ('text' => 'Řádky', 'icon' => 'x-properties');
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'x-attachments');
			$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'x-wrench');
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
			$this->layoutOpen (self::ltHorizontal);

				$this->layoutOpen (self::ltForm);
					$this->addColumnInput ("person");

					$this->openRow ();
						$this->addColumnInput ("cashBoxDir");
						$this->addColumnInput ("collectingDoc");
					$this->closeRow ();

					$this->addColumnInput ("dateIssue");
          $this->addColumnInput ("dateAccounting");
					if ($taxPayer)
					{
						if ($this->recData['taxCalc'])
						{
							if ($this->recData['cashBoxDir'] == 1)
								$this->addColumnInput("dateTax");
							else
							{
								$this->openRow();
									$this->addColumnInput("dateTax");
									$this->addColumnInput('dateTaxDuty');
								$this->closeRow();
								$this->addColumnInput ('docId');
							}
						}
					}
					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ("centre");
					if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
						$this->addColumnInput ('workOrder');
					if ($useProperty)
						$this->addColumnInput ('property');
					if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
						$this->addColumnInput ('project');
					if ($this->table->warehouses())
						$this->addColumnInput ('warehouse');
        $this->layoutClose ();

				$this->layoutOpen (self::ltForm);
					if ($taxPayer)
					{
						$this->addColumnInput ("taxCalc");
						if ($this->recData['taxCalc'])
						{
							$this->addColumnInput ("taxMethod");
							$this->addColumnInput ("taxType");
						}
					}
					$this->addCurrency(self::coReadOnly);
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
				$this->addColumnInput ('automaticRound');
				$rmRO = $this->recData['automaticRound'] ? self::coReadOnly : 0;
				$this->addColumnInput ('roundMethod', $rmRO);
				$this->addColumnInput ("cashBox");
				if ($taxPayer)
				{
					$this->addVatSettingsIn ();
					if ($this->recData['cashBoxDir'] == 1)
					{
						if ($this->recData['taxCalc']) {
							$this->addColumnInput('dateTaxDuty');
						}
						$this->addColumnInput('docId');
					}
				}

				$this->openRow();
					$this->addColumnInput ("initState", DataModel::coSaveOnChange);
					if ($this->recData['initState'])
						$this->addColumnInput ("initBalance");
				$this->closeRow();

				$this->addColumnInput ('paymentMethod');
			$this->closeTab ();

		$this->closeTabs ();
		$this->closeForm ();
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ();

		$this->recData ['dateDue'] = new \DateTime ();
		$this->recData ['dateDue'] = new \DateTime ();
		$this->recData ['roundMethod'] = 1;

		$this->recData ['automaticRound'] = intval($this->app()->cfgItem ('options.e10doc-sale.automaticRoundOnSale', 0));
		if ($this->copyDoc)
		{
			if ($this->recData ['taxCalc'] == 2)
				$this->recData ['taxCalc'] = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->recData['dateAccounting']);
		}
	}

	function columnLabel ($colDef, $options)
	{
		if ($this->recData['cashBoxDir'] == 1)
		{
			switch ($colDef ['sql'])
			{
				case  'personVATIN': return 'DIČ odběratele';
			}
		}
		elseif  ($this->recData['cashBoxDir'] == 2)
		{
			switch ($colDef ['sql'])
			{
				case  'personVATIN': return 'DIČ dodavatele';
			}
		}
		return parent::columnLabel ($colDef, $options);
	}
}
