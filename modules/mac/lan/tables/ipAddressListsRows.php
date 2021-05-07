<?php

namespace mac\lan;


use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \mac\data\libs\SensorHelper;


/**
 * Class TableIPAddressListsRows
 * @package mac\lan
 */
class TableIPAddressListsRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.ipAddressListsRows', 'mac_lan_ipAddressListsRows', 'Řádky seznamu IP adres');
	}
}


/**
 * Class FormIPAddressListsRow
 * @package mac\lan
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
