<?php


namespace e10mnf\mf;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Table\DbTable, \Shipard\Form\TableForm;


/**
 * class TableProductsMaterials
 */
class TableProductsMaterials extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.mf.productsMaterials', 'e10mnf_mf_productsMaterials', 'Materiály výrobků');
	}
}


/**
 * Class FormProductsMaterial
 */
class FormProductsMaterial extends TableForm
{
	var $dko = NULL;

	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

    $this->openForm (TableForm::ltGrid);
      $this->openRow ();
        $this->addColumnInput ('item', TableForm::coColW12);
      $this->closeRow ();
      $this->openRow ();
        $this->addColumnInput ('quantity', TableForm::coColW2);
        $this->addColumnInput ('positions', TableForm::coColW5);
        $this->addColumnInput ('productVariant', TableForm::coColW5);
      $this->closeRow ();
		$this->closeForm ();
	}
}
