<?php

namespace e10doc\bank\libs;



class FormBankDoc extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
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
					$this->addColumnInput ("docOrderNumber");
					$this->addColumnInput ("dateAccounting");
					$this->addColumnInput ("datePeriodBegin");
					$this->addColumnInput ("datePeriodEnd");
					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ("centre");
				$this->layoutClose ();

				$this->layoutOpen (self::ltForm);
					$this->addColumnInput ("initBalance");
					$this->addColumnInput ("credit", self::coReadOnly);
					$this->addColumnInput ("debit", self::coReadOnly);
					$this->addColumnInput ("balance", self::coReadOnly);
				$this->layoutClose ();
			$this->layoutClose ();

			$this->layoutOpen (self::ltGrid);
				$this->addColumnInput ('title', self::coColW12);
				$this->addList ('inbox', '', self::loAddToFormLayout|self::coColW12);
				$this->addList ('doclinks', '', self::loAddToFormLayout|self::coColW12);
				$this->addList ('clsf', '', self::loAddToFormLayout|self::coColW12);
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
		$this->recData ['datePeriodBegin'] = new \DateTime ();
		$this->recData ['datePeriodEnd'] = new \DateTime ();
	}
}

