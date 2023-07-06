<?php

namespace e10doc\deliveryNotes\libs;
use \Shipard\Utils\Utils;
use \e10doc\core\libs\E10Utils;


class FormDeliveryNote extends \E10Doc\Core\FormHeads
{
	public function renderForm ()
	{
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
				$tabs ['tabs'][] = ['text' => $mi ['text'], 'icon' => $mi ['icon']];

			$this->addAccountingTab ($tabs['tabs']);

			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);

				$this->openTab ();
					$this->layoutOpen (self::ltHorizontal);
						$this->layoutOpen (self::ltForm);
							$this->addColumnInput ('person');
							$this->addColumnInput ('otherAddress1');

							$this->addColumnInput ('dateIssue');

							if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
								$this->addColumnInput ("centre");
							if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
								$this->addColumnInput ('workOrder');
							if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
								$this->addColumnInput ('project');

							$this->addSeparator(self::coH4);
							$this->addColumnInput ('transport');

							$transportCfg = $this->app()->cfgItem('e10doc.transports.'.$this->recData['transport'], NULL);
							if ($transportCfg && intval($transportCfg['askVehicleLP'] ?? 0))
								$this->addColumnInput ('transportVLP');
							if ($transportCfg && intval($transportCfg['askVehicleWeight'] ?? 0))
								$this->addColumnInput ('transportVWeight');

							if ($transportCfg && intval($transportCfg['askVehicleDriver'] ?? 0))
								$this->addColumnInput ('transportPersonDriver');

							$this->addSeparator(self::coH4);


							if ($this->table->warehouses())
								$this->addColumnInput ('warehouse');

							$this->addCurrency();
						$this->layoutClose ('width50');

						$this->layoutOpen (self::ltForm);
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

				$this->openTab (self::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('author');
					$this->addColumnInput ('owner');
					$this->addColumnInput ('roundMethod');
					$this->addColumnInput ('ownerOffice');

					if ($useDocKinds !== 2)
						$this->addColumnInput ('docKind');
				$this->closeTab ();

			$this->closeTabs ();

		$this->closeForm ();
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ();

		if (!$this->copyDoc)
		{
			$this->recData ['roundMethod'] = intval($this->app()->cfgItem ('options.e10doc-sale.roundInvoice', 0));
			$this->recData ['taxCalc'] = intval($this->app()->cfgItem ('options.e10doc-sale.salePricesType', 1));
			$this->recData ['taxCalc'] = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->recData['dateAccounting'], $this->recData ['taxCalc']);
		}
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
