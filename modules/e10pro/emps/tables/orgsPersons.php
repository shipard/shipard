<?php
namespace e10pro\emps;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;



/**
 * class TableOrgsPersons
 */
class TableOrgsPersons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.emps.orgsPersons', 'e10pro_emps_orgsPersons', 'Osoby organizační struktury');
	}
}

/**
 * class FormOrgsPerson
 */
class FormOrgsPerson extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltGrid);
      $this->openRow();
				$this->addColumnInput ('person', self::coColW12);
			$this->closeRow();
      $this->openRow();
				$this->addColumnInput ('function', self::coColW8);
        $this->addColumnInput ('superior', self::coColW4);
			$this->closeRow();
		$this->closeForm ();
	}
}
