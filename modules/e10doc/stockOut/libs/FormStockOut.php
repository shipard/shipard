<?php

namespace e10doc\stockOut\libs;


class FormStockOut extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = array ('text' => 'Záhlaví', 'icon' => 'system/formHeader');
			$tabs ['tabs'][] = array ('text' => 'Řádky', 'icon' => 'system/formRows');
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
			$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'system/formSettings');
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
				$this->layoutOpen (self::ltHorizontal);
					$this->layoutOpen (self::ltForm);
						$this->addColumnInput ("person");

						$this->addColumnInput ("dateIssue");
						$this->addColumnInput ("dateAccounting");
					$this->layoutClose ();

					$this->layoutOpen (self::ltForm);
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
				$this->addColumnInput ('symbol1');
				$this->addColumnInput ('symbol2');
			$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case'person': return 'Odběratel';
    }
    return parent::columnLabel ($colDef, $options);
  }
}
