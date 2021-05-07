<?php

namespace mac\admin;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableIPAddressListsRows
 * @package mac\admin
 */
class TableIPAddressListsRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.admin.ipAddressListsRows', 'mac_admin_ipAddressListsRows', 'Řádky seznamu IP adres');
	}
}


/**
 * Class FormIPAddressListsRow
 * @package mac\admin
 */
class FormIPAddressListsRow extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('address', TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}
}
