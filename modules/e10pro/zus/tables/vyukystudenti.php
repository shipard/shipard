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
				$this->addColumnInput ('studium', TableForm::coColW11);
				$this->addColumnInput ('platnost', TableForm::coColW1);
			$this->closeRow();
			if ($this->recData['platnost'])
			{
				$this->addColumnInput ('platnostOd', TableForm::coColW4);
				$this->addColumnInput ('platnostDo', TableForm::coColW4);
			}
		$this->closeForm ();
	}
}
