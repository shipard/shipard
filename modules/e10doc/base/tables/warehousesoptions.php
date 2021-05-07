<?php

namespace e10doc\base;


use \e10\DbTable, \e10\TableForm;


/**
 * Class TableWarehousesOptions
 * @package E10Doc\Base
 */
class TableWarehousesOptions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.warehousesoptions', 'e10doc_base_warehousesoptions', 'Nastavení zásob');
	}
}


/**
 * Class FormWarehousesOption
 * @package E10Doc\Base
 */
class FormWarehousesOption extends TableForm
{
	public function renderForm ()
	{
		$this->openForm ();
			$this->addColumnInput ('fiscalYear');
			$this->addColumnInput ('calcPrices');
			$this->addColumnInput ('debsAccInvAcquisition');
			$this->addColumnInput ('debsAccInvInStore');
			$this->addColumnInput ('debsAccInvInTransit');
		$this->closeForm ();
	}
}



