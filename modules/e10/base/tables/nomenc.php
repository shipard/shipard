<?php

namespace e10\base;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \e10\DbTable, \e10\TableForm;


/**
 * Class TableNomenc
 * @package e10\base
 */
class TableNomenc extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.nomenc', 'e10_base_nomenc', 'Nomenklatura');
	}
}


/**
 * Class FormNomenc
 * @package e10\base
 */
class FormNomenc extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ('nomencType', TableForm::coColW6);
				$this->addColumnInput ('nomencItem', TableForm::coColW6);
			$this->closeRow ();
		$this->closeForm ();
	}
}
