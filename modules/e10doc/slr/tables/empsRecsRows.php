<?php
namespace e10doc\slr;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;



/**
 * class TableEmpsRecsRows
 */
class TableEmpsRecsRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.empsRecsRows', 'e10doc_slr_empsRecsRows', 'Řádky mzdových podkladů zaměstnanců');
	}
}

/**
 * class FormEmpRecRow
 */
class FormEmpRecRow extends TableForm
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
				$this->addColumnInput ('amount', self::coColW4);
				$this->addColumnInput ('quantity', self::coColW4);
				$this->addColumnInput ('unit', self::coColW4);
			$this->closeRow();
      $this->openRow();
				$this->addColumnInput ('bankAccount', self::coColW4);
				$this->addColumnInput ('symbol1', self::coColW3);
				$this->addColumnInput ('symbol2', self::coColW3);
				$this->addColumnInput ('symbol3', self::coColW2);
			$this->closeRow();
		$this->closeForm ();
	}
}

