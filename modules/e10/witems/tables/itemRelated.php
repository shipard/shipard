<?php

namespace e10\witems;
use \E10\Application, \E10\utils, \E10\TableForm, \E10\DbTable;


/**
 * Class TableItemRelated
 * @package e10\witems
 */
class TableItemRelated extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemRelated', 'e10_witems_itemRelated', 'Související položky');
	}
}


/**
 * Class FormItemRelated
 * @package e10\witems
 */
class FormItemRelated extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('kind', self::coColW4);
				$this->addColumnInput ('relatedItem', self::coColW8);
			$this->closeRow();
		$this->closeForm ();
	}
}
