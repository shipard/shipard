<?php

namespace E10Pro\Zus;

use \E10\DbTable, \E10\TableForm;


/**
 * Class TableHodinyDochazka
 * @package E10Pro\Zus
 */
class TableHodinyDochazka extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.hodinydochazka', 'e10pro_zus_hodinydochazka', 'Docházka');
	}
}


/**
 * Class FormHodinyDochazka
 * @package E10Pro\Zus
 */
class FormHodinyDochazka extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow ();
				$this->addColumnInput ('pritomnost', TableForm::coColW2);
				if ($this->app()->hasRole('root'))
				{
					$this->addColumnInput ('studium', TableForm::coColW6);
					$this->addInput('student', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 20);
				}
				else
				{
					$this->addColumnInput ('studium', TableForm::coInfoText|TableForm::coNoLabel|TableForm::coColW6);
					$this->addInput('student', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 20);
				}
				$this->addColumnInput ('klasifikaceZnamka', TableForm::coInfoText|TableForm::coNoLabel|TableForm::coColW4);
			$this->closeRow ();
		$this->closeForm ();
	}
}
