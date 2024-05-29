<?php
namespace e10doc\slr;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TablePersonsRecs
 */
class TablePersonsRecsRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.personsRecsRows', 'e10doc_slr_personsRecsRows', 'Řádky mzdových podkladů Osob');
	}
}

/**
 * class FormPersonRecRow
 */
class FormPersonRecRow extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('slrItem', self::coColW12);
      $this->closeRow();
      $this->openRow();
				$this->addColumnInput ('amount', self::coColW4);
			$this->closeRow();
		$this->closeForm ();
	}
}

