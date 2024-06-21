<?php
namespace e10doc\slr;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableEmpsOrgs
 */
class TableEmpsCentres extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.empsCentres', 'e10doc_slr_empsCentres', 'Nastavení středisek zaměstnanců');
	}
}

/**
 * class FormEmpCentre
 */
class FormEmpCentre extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('slrItem', self::coColW12);
      $this->closeRow();
			$this->openRow();
				$this->addColumnInput ('centre', self::coColW12);
      $this->closeRow();
      $this->openRow();
        $this->addColumnInput ('validFrom', self::coColW6);
        $this->addColumnInput ('validTo', self::coColW6);
			$this->closeRow();
		$this->closeForm ();
	}
}

