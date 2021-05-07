<?php

namespace services\money;
use \e10\TableForm, \e10\DbTable;


/**
 * Class TableExchangeRatesValues
 * @package services\money
 */
class TableExchangeRatesValues extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.money.exchangeRatesValues', 'services_money_exchangeRatesValues', 'Kurzy měn');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['cntUnits']) || !$recData['cntUnits'])
			$recData['cntUnits'] = 1;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (!isset($recData['cntUnits']) || !$recData['cntUnits'])
			$recData['cntUnits'] = 1;
		if (!isset($recData['exchangeRate']) || !$recData['exchangeRate'])
			$recData['exchangeRate'] = 1.0;

		$recData['exchangeRateOneUnit'] = round($recData['exchangeRate'] / $recData['cntUnits'], 7);
	}
}


/**
 * Class FormExchangeRatesValue
 * @package services\money
 */
class FormExchangeRatesValue extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('currency', TableForm::coColW12);
				$this->addColumnInput ('exchangeRate', TableForm::coColW4);
				$this->addColumnInput ('cntUnits', TableForm::coColW4);
				$this->addColumnInput ('exchangeRateOneUnit', TableForm::coColW4|TableForm::coReadOnly);
			$this->closeRow();
		$this->closeForm ();
	}
}

