<?php

namespace mac\admin;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableLanUsersPubKeys
 */
class TableLanUsersPubKeys extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.admin.lanUsersPubKeys', 'mac_admin_lanUsersPubKeys', 'Veřejné klíče LAN uživatelů');
	}
}


/**
 * Class FormLanUserPubKey
 */
class FormLanUserPubKey extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('name', TableForm::coColW12);
				$this->addColumnInput ('key', TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}
}
