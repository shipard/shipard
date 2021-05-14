<?php

namespace e10doc\bankOrder\libs;
use \Shipard\Utils\Utils;



class FormBankOrder extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

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
					$this->addColumnInput ("dateDue");
				$this->layoutClose ();
			$this->layoutClose ();

			$this->layoutOpen (self::ltGrid);
				$this->addColumnInput ('title', self::coColW12);
				$this->addList ('doclinks', '', self::loAddToFormLayout|self::coColW12);
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

