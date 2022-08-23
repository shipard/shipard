<?php

namespace E10Pro\Zus;

use \E10\TableForm, \E10\DbTable;


/**
 * Class TableVyukyStudenti
 * @package E10Pro\Zus
 */
class TableVyukyStudenti extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.vyukystudenti', 'e10pro_zus_vyukystudenti', 'Studenti ve výuce');
	}
}


/**
 * Class FormVyukyStudent
 * @package E10Pro\Zus
 */

class FormVyukyStudent extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ('studium', TableForm::coColW12);
//				$this->addColumnInput ('student', TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}
}
