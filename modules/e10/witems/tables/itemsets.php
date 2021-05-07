<?php

namespace e10\witems;

use \E10\Application, \E10\utils, \E10\TableForm, \E10\DbTable;
//use \E10Doc\Core\e10utils;


/**
 * Class TableItemSets
 * @package e10\witems
 */
class TableItemSets extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemsets', 'e10_witems_itemsets', 'Sady položek');
	}
}


/**
 * Class FormItemSet
 * @package e10\witems
 */
class FormItemSet extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('setItemType');
			$this->addColumnInput ('item');
			$this->addColumnInput ('quantity');
			$this->openRow();
				$this->addColumnInput ('validFrom');
				$this->addColumnInput ('validTo');
			$this->closeRow();
		$this->closeForm ();
	}
}
