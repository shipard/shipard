<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__.'/e10-modules/e10doc/core/core.php';

use \E10\Application, \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableViewPanel;
use \E10\TableForm, \E10\DbTable;


/**
 * Class TableHodnoceni
 * @package E10Pro\Zus
 */
class TableHodnoceni extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.hodnoceni', 'e10pro_zus_hodnoceni', 'Hodnocení');
	}
}


/**
 * Class FormHodnoceni
 * @package E10Pro\Zus
 */
class FormHodnoceni extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput('znamka');
			$this->addColumnInput('poznamka');

/*
			$this->addColumnInput('ucitel');
			$this->addColumnInput('student');
			$this->addColumnInput('predmet');
			$this->addColumnInput('vyuka');
*/
		$this->closeForm ();
	}
}

