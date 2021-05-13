<?php

namespace e10doc\purchase\libs;


use e10doc\core\e10utils;
use E10\utils;
use \Shipard\Form\TableForm;


class FormPurchaseDocsRows extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->addColumnInput ("text", TableForm::coHidden);
			$this->addColumnInput ("unit", TableForm::coHidden);
			$this->addColumnInput ("itemType", TableForm::coHidden);
			$this->addColumnInput ("itemBalance", TableForm::coHidden);
			$this->addColumnInput ("itemIsSet", TableForm::coHidden);

			$this->openRow ();
				$this->addColumnInput ("item", TableForm::coInfoText|TableForm::coNoLabel|TableForm::coColW12);
			$this->closeRow ();

			$this->openRow ('right');
				$this->addColumnInput ("quantity", TableForm::coColW4);
				$inpParams = array ('plusminus' => 'smart');
				$this->addColumnInput ("priceItem", TableForm::coColW4, $inpParams);
			$this->closeRow ();
		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
			case'quantity': return $this->app()->cfgItem ('e10.witems.units.'.$this->recData['unit'].'.shortcut');
			case'priceItem': return 'Kč/'.$this->app()->cfgItem ('e10.witems.units.'.$this->recData['unit'].'.shortcut');
    }
    return parent::columnLabel ($colDef, $options);
  }

} // class FormPurchaseRows

