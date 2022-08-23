<?php

namespace E10Pro\Zus;

use \E10\TableForm, \E10\DbTable;


/**
 * Class TableTeachPlanRows
 * @package E10Pro\Zus
 */
class TableTeachPlanRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.teachplanrows', 'e10pro_zus_teachplanrows', 'Předměty učebních plánů');
	}
}


/**
 * Class FormTeachPlanRow
 * @package E10Pro\Zus
 */
class FormTeachPlanRow extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ('subject', TableForm::coColW5);
				$this->addColumnInput ('hours', TableForm::coColW2);
				$this->addColumnInput ('povinnost', TableForm::coColW2);
			$this->closeRow();
		$this->closeForm ();
	}
}
