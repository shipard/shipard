<?php

namespace e10doc\contracts\core;

use \e10\DbTable, \e10\TableForm;


/**
 * Class TableKindsRows
 * @package e10doc\contracts\core
 */
class TableKindsRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.contracts.core.kindsRows', 'e10doc_contracts_kindsRows', 'Řádky druhů smluv');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['quantity']))
			$recData ['quantity'] = 1;

		if (isset($recData ['priceItem']) && $recData ['quantity'])
			$recData ['priceAll'] = $recData ['priceItem'] * $recData ['quantity'];

		parent::checkBeforeSave ($recData, $ownerData);
	}
}


/**
 * Class FormKindRow
 * @package e10doc\contracts\core
 */
class FormKindRow extends TableForm
{
	public function renderForm ()
	{
		$useCentres = intval(($this->app()->cfgItem ('options.core.useCentres', 0)));
		$useProjects = intval($this->app()->cfgItem ('options.core.useProjects', 0));
		$useWorkOrders = $this->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0);

		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ('item', TableForm::coColW12);
			$this->closeRow ();
	
			$this->openRow ();
				$this->addColumnInput ('text', TableForm::coColW12);
			$this->closeRow ();
	
			$this->openRow ();
				$this->addColumnInput ('quantity', TableForm::coColW4);
				$this->addColumnInput ('unit', TableForm::coColW4);
				$this->addColumnInput ('priceItem', TableForm::coColW4);
			$this->closeRow ();
	
			$this->openRow ();
				if ($useCentres)
					$this->addColumnInput ('centre', TableForm::coColW3);
				if ($useProjects)
					$this->addColumnInput ('wkfProject', TableForm::coColW4);
				if ($useWorkOrders)
					$this->addColumnInput ('workOrder', TableForm::coColW5);
			$this->closeRow ();
		$this->closeForm ();
	}
}


