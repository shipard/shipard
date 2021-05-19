<?php

namespace e10doc\stockIn\libs;


class FormStockIn extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$taxPayer = $this->recData['taxPayer'];

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

		$this->addColumnInput ("symbol1");
		$this->addColumnInput ("symbol2");
		$this->addColumnInput ("dateIssue");
		$this->addColumnInput ("dateAccounting");
		if ($taxPayer)
		{
			if ($this->recData['taxCalc'])
			{
				$this->addColumnInput ("dateTax");
			}
		}
		if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
			$this->addColumnInput ("centre");
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
		$this->addCurrency();

		$this->addColumnInput ("roundMethod");
		$this->addColumnInput ("initState");

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
			$this->addColumnInput ('author');
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case'person': return 'Dodavatel';
    }
    return parent::columnLabel ($colDef, $options);
  }
}
