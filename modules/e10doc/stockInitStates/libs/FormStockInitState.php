<?php

namespace e10doc\stockInitStates\libs;


class FormStockInitState extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs);

			$this->openTab (self::ltNone);
			$this->layoutOpen (self::ltDocMain);
				$this->layoutOpen (self::ltForm);
					$this->addColumnInput ("dateAccounting");
        $this->layoutClose ();
				$this->layoutOpen (self::ltVertical);
					$this->addColumnInput ("title");
				$this->layoutClose ();
			$this->layoutClose ();

			$this->layoutOpen (self::ltDocRows);
				//$this->addList ('rows');
			$this->layoutClose ();

      $this->closeTab ();

			$this->openTab (self::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
			$this->closeTab ();

			$this->openTab ();
        $this->addColumnInput ("warehouse");
				$this->addColumnInput ("author");
				$this->addColumnInput ("taxCalc");
			$this->closeTab ();

      $this->closeTabs ();

    $this->closeForm ();
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ($this->recData);
		$this->recData ['initState'] = 1;
  }
}
