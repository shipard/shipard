<?php

namespace e10doc\stockOut\libs;


class FormStockOut extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$whCfg = $this->app()->cfgItem('e10doc.warehouses.'.$this->recData['warehouse'], NULL);

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
					$this->addColumnInput ('person');
					$this->addColumnInput ('otherAddress1');
					$this->addColumnInput ('dateIssue');
					$this->addColumnInput ('dateAccounting');

					if ($whCfg && intval($whCfg['useTransportOnDocs'] ?? 0))
					{
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
					}
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
				$this->addColumnInput ('ownerOffice');
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
