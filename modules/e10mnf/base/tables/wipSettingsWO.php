<?php

namespace e10mnf\base;

use \Shipard\Table\DbTable, \Shipard\Form\TableForm;


/**
 * class TableWIPSettingsWO
 */
class TableWIPSettingsWO extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.wipSettingsWO', 'e10mnf_base_wipSettingsWO', 'Zakázky nastavení monitoringu práce');
	}
}


/**
 * class FormWIPSettingsWO
 */
class FormWIPSettingsWO extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
      $this->openRow ();
				$this->addColumnInput ('woRecKind', TableForm::coColW6);
				$this->addColumnInput ('woDbCounter', TableForm::coColW6);
      $this->closeRow ();
		$this->closeForm ();
	}
}

