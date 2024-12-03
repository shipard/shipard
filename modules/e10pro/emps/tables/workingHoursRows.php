<?php
namespace e10pro\emps;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;



/**
 * class TableWorkingHoursRows
 */
class TableWorkingHoursRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.emps.workingHoursRows', 'e10pro_emps_workingHoursRows', 'Řádky evidence pracovní doby');
	}
}

/**
 * class FormWorkingHoursRow
 */
class FormWorkingHoursRow extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltGrid);
      $this->openRow();
				$this->addColumnInput ('dow', self::coColW4);
				$this->addColumnInput ('timeBegin', self::coColW4);
				$this->addColumnInput ('timeEnd', self::coColW4);
			$this->closeRow();
      $this->openRow();
				$this->addColumnInput ('cntHours1', self::coColW6);
				$this->addColumnInput ('cntHours2', self::coColW6);
			$this->closeRow();
		$this->closeForm ();
	}
}

