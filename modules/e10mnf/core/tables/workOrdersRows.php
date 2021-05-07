<?php

namespace e10mnf\core;

use \e10\DbTable, \e10\TableForm;


/**
 * Class TableWorkOrdersRows
 * @package e10mnf\core
 */
class TableWorkOrdersRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.core.workOrdersRows', 'e10mnf_core_workOrdersRows', 'Řádky zakázek');
	}

	public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
		return 'default';
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset($recData ['priceSource']))
			$recData ['priceSource'] = 0;
		if (!isset($recData ['priceItem']))
			$recData ['priceItem'] = 0;
		if (!isset($recData ['quantity']))
			$recData ['quantity'] = 1;

		$exchangeRate = $ownerData ['exchangeRate'];
		if (!$exchangeRate)
			$exchangeRate = 1;

		// -- price
		if ($recData ['priceSource'] === 0)
			$recData ['priceAll'] = round ($recData ['priceItem'] * $recData ['quantity'], 2);
		else
		{
			if ($recData ['quantity'] != 0.0)
				$recData ['priceItem'] = round($recData ['priceAll'] / $recData ['quantity'], 4);
			else
				$recData ['priceItem'] = 0.0;
		}

		$recData ['priceItemHc'] = round (($recData ['priceItem'] * $exchangeRate), 2);
		$recData ['priceAllHc'] = round (($recData ['priceAll'] * $exchangeRate), 2);

		parent::checkBeforeSave ($recData, $ownerData);
	}
}


/**
 * Class FormRow
 * @package e10mnf\core
 */
class FormRow extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

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



		$this->closeForm ();
	}
}