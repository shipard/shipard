<?php

namespace e10doc\cmnbkp\libs;
require_once __SHPD_MODULES_DIR__ . 'e10doc/cmnbkp/cmnbkp.php';


class FormCmnBkpDoc extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		switch ($this->recData['activity'])
		{
			case 'balExchRateDiff': $this->renderForm_balExchRateDiff (); break;
			default: $this->renderForm_Default (); break;
		}
	}

	public function renderForm_default ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);
		$useDocKinds = $this->useDocKinds();

		$properties = $this->addList ('properties', '', self::loAddToFormLayout|self::loWidgetParts);

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];

			forEach ($properties ['memoInputs'] as $mi)
				$tabs ['tabs'][] = ['text' => $mi ['text'], 'icon' => $mi ['icon']];

			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
			$this->layoutOpen (self::ltHorizontal);

				$this->layoutOpen (self::ltForm);
					if ($useDocKinds === 2)
						$this->addColumnInput ("docKind");

					$this->addColumnInput ("person");
					$this->addColumnInput ("dateIssue");
          $this->addColumnInput ("dateAccounting");
					$this->addColumnInput ("taxPeriod");
					$this->addCurrency();
					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ("centre");
				$this->layoutClose ();

				$this->layoutOpen (self::ltForm);
					$this->addColumnInput ("initState");
        $this->layoutClose ();
			$this->layoutClose ();

			$this->layoutOpen (self::ltGrid);
				$this->addColumnInput ('title', self::coColW12);
				$this->addList ('inbox', '', self::loAddToFormLayout|self::coColW12);
				$this->addList ('doclinks', '', self::loAddToFormLayout|self::coColW12);
				$this->addList ('clsf', '', self::loAddToFormLayout|self::coColW12);
			$this->layoutClose ();

			$this->appendCode ($properties ['widgetCode']);

      $this->closeTab ();

			$this->openTab ();
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
				$this->addColumnInput ("author");
				$this->addColumnInput ("owner");
        //$this->addColumnInput ("roundMethod");
			$this->closeTab ();

			$this->closeTabs ();


     $this->closeForm ();
	}

	public function renderForm_balExchRateDiff ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);
		$useDocKinds = $this->useDocKinds();

		$this->openForm (self::ltNone);
		$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
		$this->addAccountingTab ($tabs['tabs']);
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$this->openTabs ($tabs, TRUE);

		$this->openTab ();
			if ($useDocKinds === 2)
				$this->addColumnInput ("docKind");

			$this->addColumnInput ("person");
			$this->addColumnInput ("dateIssue");
			$this->addColumnInput ("dateAccounting");
			$this->addCurrency();
			if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
				$this->addColumnInput ("centre");

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
			$this->addColumnInput ("author");
			$this->addColumnInput ("roundMethod");
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}


	public function checkNewRec ()
	{
		parent::checkNewRec ($this->recData);
		$this->recData ['dateDue'] = new \DateTime ();
	}
}
