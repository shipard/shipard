<?php

namespace e10pro\bcards;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Json;

/**
 * class TableCardsLinks
 */
class TableCardsLinks extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.bcards.cardsLinks', 'e10pro_bcards_cardsLinks', 'Odkazy na vizitkách');
	}
}


/**
 * class FormCardLink
 */
class FormCardLink extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('linkType', TableForm::coColW3);
        $this->addColumnInput ('url', TableForm::coColW9);
			$this->closeRow();
		$this->closeForm ();
	}
}
