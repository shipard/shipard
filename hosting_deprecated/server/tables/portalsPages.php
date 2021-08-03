<?php

namespace e10pro\hosting\server;


use e10\TableView, e10\TableForm, e10\DbTable;


/**
 * Class TablePortalsPages
 * @package e10pro\hosting\server
 */
class TablePortalsPages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.server.portalsPages', 'e10pro_hosting_server_portalsPages', 'Stránky portálu');
	}
}


/**
 * Class FormPortalPage
 * @package e10pro\hosting\server
 */
class FormPortalPage extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('pageType', TableForm::coColW3);
				$this->addColumnInput ('url',TableForm::coColW5);
				$this->addColumnInput ('title',TableForm::coColW4);
			$this->closeRow();
			$this->openRow();
				$this->addColumnInput ('logoIcon',TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}

}
