<?php

namespace e10doc\offersOut\libs;
use \Shipard\Form\TableForm;


/**
 * class FormOfferOutRow
 */
class FormOfferOutRow extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$this->openForm (TableForm::ltGrid);
      $this->addColumnInput ('itemType', TableForm::coHidden);
      $this->addColumnInput ('itemBalance', TableForm::coHidden);
      $this->addColumnInput ('itemIsSet', TableForm::coHidden);

      $this->openRow ();
        $this->addColumnInput ('operation', TableForm::coColW3);
        $this->addColumnInput ('item', TableForm::coColW9|TableForm::coHeader);
      $this->closeRow ();

      $this->openRow ();
      $this->addColumnInput ('text', TableForm::coColW12);
      $this->closeRow ();

      $this->openRow ();
      $this->addColumnInput ('quantity', TableForm::coColW3);
      $this->addColumnInput ('unit', TableForm::coColW2);
      $this->addColumnInput ('priceItem', TableForm::coColW3);
      if ($ownerRecData && $ownerRecData ['taxPayer'])
        $this->addColumnInput ('taxCode', TableForm::coColW4);
      $this->closeRow ();

      $this->openRow ();
      if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
        $this->addColumnInput ('centre', TableForm::coColW3);
      if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
        $this->addColumnInput ('project', TableForm::coColW5);
      $this->closeRow ();

		$this->closeForm ();
	}
}
