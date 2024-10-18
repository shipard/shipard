<?php

namespace e10mnf\base;

use \Shipard\Table\DbTable, \Shipard\Form\TableForm;


/**
 * class TableWIPSettingsPersons
 */
class TableWIPSettingsPersons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.wipSettingsPersons', 'e10mnf_base_wipSettingsPersons', 'Osoby nastavení monitoringu práce');
	}
}


/**
 * class FormWIPSettingsPerson
 */
class FormWIPSettingsPerson extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
      $this->openRow ();
        $this->addColumnInput ('rowType', TableForm::coColW3);
        if ($this->recData['rowType'] == 0)
          $this->addColumnInput ('personsGroup', TableForm::coColW9);
        else
          $this->addColumnInput ('person', TableForm::coColW9);
      $this->closeRow ();
		$this->closeForm ();
	}
}

