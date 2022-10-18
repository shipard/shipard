<?php

namespace e10doc\purchase\libs;
use \Shipard\Form\TableForm;

/**
 * class FormPurchaseDocsRows
 */
class FormPurchaseDocsRows extends TableForm
{
	public function renderForm ()
	{
		$mainMode = $this->recData['rowVds'] ? self::ltForm : self::ltGrid;
		$this->openForm ($mainMode);
			$this->addColumnInput ('text', TableForm::coHidden);
			$this->addColumnInput ('unit', TableForm::coHidden);
			$this->addColumnInput ('itemType', TableForm::coHidden);
			$this->addColumnInput ('itemBalance', TableForm::coHidden);
			$this->addColumnInput ('itemIsSet', TableForm::coHidden);
			$this->addColumnInput ('rowVds', TableForm::coHidden);
			//$this->addColumnInput ("priceSource", TableForm::coHidden);

			if ($mainMode === self::ltGrid)
			{
				$this->openRow ();
					$this->addColumnInput ("item", TableForm::coInfoText|TableForm::coNoLabel|TableForm::coColW12);
				$this->closeRow ();

				$this->openRow ('right');
					$this->addColumnInput ("quantity", TableForm::coColW4);
					if (($this->recData['priceSource'] ?? 0) == 0)
					{
						$inpParams = ['plusminus' => 'smart'];
						$this->addColumnInput ("priceItem", TableForm::coColW4, $inpParams);
					}
				$this->closeRow ();
			}
			else
			{
				$this->addColumnInput ('item', TableForm::coInfoText|TableForm::coNoLabel);
				$this->addColumnInput ('quantity');
				$this->addSubColumns ('rowData', 1);
			}
		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['id'] ?? '')
    {
			case'quantity': return $this->app()->cfgItem ('e10.witems.units.'.$this->recData['unit'].'.shortcut');
			case'priceItem': return 'Kč/'.$this->app()->cfgItem ('e10.witems.units.'.$this->recData['unit'].'.shortcut');
    }
    return parent::columnLabel ($colDef, $options);
  }
}

