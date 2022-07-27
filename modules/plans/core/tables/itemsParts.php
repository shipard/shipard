<?php

namespace plans\core;

use \e10\TableForm, \e10\DbTable, \e10\TableView, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableItemsParts
 */
class TableItemsParts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('plans.core.itemsParts', 'plans_core_itemsParts', 'Části položek plánu');
	}
}

/**
 * class FormItemPart
 */
class FormItemPart extends TableForm
{
	public function renderForm ()
	{
    $ownerRecData = $this->option ('ownerRecData');
    $this->recData['workOrder'] = $ownerRecData['workOrder'] ?? 0;

		$this->openForm (TableForm::ltGrid);
      $this->openRow ();
        $this->addColumnInput ('workOrder', TableForm::coColW12);
      $this->closeRow ();
      $this->openRow ();
				$this->addColumnInput ('subject', TableForm::coColW12);
			$this->closeRow ();
			$this->openRow ();
				$this->addColumnInput ('refId3', TableForm::coColW8);
			$this->closeRow ();
		$this->closeForm ();
	}
}

