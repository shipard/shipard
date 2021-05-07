<?php

namespace e10doc\cashRegister\libs;
use \Shipard\Utils\Utils;
use e10doc\core\libs\E10Utils;


class FormCashRegisterDoc extends \E10Doc\Core\FormHeads
{
	public function renderForm ()
	{
		$taxPayer = $this->recData['taxPayer'];

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_LEFT);

		if ($this->recData['docState'] === 1201)
		{	// hotově
			$this->openForm (self::ltNone);
				$this->addColumnInput ("title");
			$this->closeForm ();
			return;
		}

		if ($this->recData['docState'] === 1202)
		{	// kartou
			$this->openForm (self::ltNone);
			$this->addColumnInput ("title");
			$this->closeForm ();
			return;
		}

		$this->openForm (self::ltNone);
			$properties = $this->addList ('properties', '', self::loAddToFormLayout|self::loWidgetParts);
			$tabs ['tabs'][] = array ('text' => 'Doklad', 'icon' => 'x-content');
			forEach ($properties ['memoInputs'] as $mi)
				$tabs ['tabs'][] = array ('text' => $mi ['text'], 'icon' => $mi ['icon']);
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'x-attachments');
			$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'x-wrench');
			$this->openTabs ($tabs, 'right');

			$this->openTab (self::ltNone);
				$this->layoutOpen (self::ltDocRows, 'big');
					$this->addList ('rows', '', self::loRowsDisableMove);
				$this->layoutClose ();

				$this->layoutOpen (self::ltDocMain);
					$this->layoutOpen (self::ltVertical);
						$this->addColumnInput ("title");
						$this->addColumnInput ("person");
					$this->layoutClose ();

					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ("centre");
				$this->layoutClose ();

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
				$this->addColumnInput ("dateIssue");
				$this->addColumnInput ("dateDue");
        $this->addColumnInput ("dateAccounting");
        if ($taxPayer)
        {
          $this->addColumnInput ("dateTax");
          $this->addColumnInput ("taxCalc");
          $this->addColumnInput ("taxMethod");
          $this->addColumnInput ("taxType");
        }
				$this->addColumnInput ("cashBox");
				$this->addColumnInput ('warehouse');
        $this->addColumnInput ("currency");
				$this->addColumnInput ('automaticRound');
				$rmRO = $this->recData['automaticRound'] ? self::coReadOnly : 0;
				$this->addColumnInput ('roundMethod', $rmRO);
				$this->addColumnInput ("author");
				$this->addColumnInput ("correctiveDoc");
				$this->addColumnInput ("paymentMethod");
			$this->closeTab ();

			$this->closeTabs ();


     $this->closeForm ();
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ();
		$this->recData ['dateDue'] = new \DateTime ();
		$this->recData ['dateDue'] = new \DateTime ();

		$this->recData ['paymentMethod'] = 1;

		$this->recData ['automaticRound'] = intval($this->app()->cfgItem ('options.e10doc-sale.automaticRoundOnSale', 0));

		if (!$this->copyDoc)
		{
			$this->recData ['roundMethod'] = intval($this->app()->cfgItem ('options.e10doc-sale.roundCashRegister', 1));
			$this->recData ['taxCalc'] = intval($this->app()->cfgItem ('options.e10doc-sale.cashRegSalePricesType', 2));
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
    }
    return parent::columnLabel ($colDef, $options);
  }
}
