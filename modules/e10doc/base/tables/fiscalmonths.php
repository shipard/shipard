<?php

namespace E10Doc\Base;

use \E10\utils;
use \E10\TableForm;
use \E10\DbTable;


/**
 * Fiskální období - měsíční
 *
 */

class TableFiscalMonths extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.base.fiscalmonths", "e10doc_base_fiscalmonths", "Fiskální období - měsíční");
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (isset($recData['start']))
		{
			$prfx = [
				'0' => '2', // běžné
				'1' => '1', // otevření
				'2' => '3', // uzavření
			];
			if (!utils::dateIsBlank($recData['start']))
				$recData['globalOrder'] = intval(utils::createDateTime($recData['start'])->format("Y{$prfx[$recData['fiscalType']]}md")); // 2013.2.0101
		}
		parent::checkBeforeSave ($recData, $ownerData);
	}
}

/* 
 * FormFiscalMonths
 * 
 */

class FormFiscalMonths extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->addColumnInput ("fiscalType", TableForm::coColW2);
			$this->addColumnInput ("localOrder", TableForm::coColW2);
			$this->addColumnInput ("calendarYear", TableForm::coColW2);
			$this->addColumnInput ("calendarMonth",TableForm::coColW2);
			$this->addColumnInput ("start", TableForm::coColW2);
			$this->addColumnInput ("end", TableForm::coColW2);
		$this->closeForm ();
	}
} // class FormFiscalMonths



