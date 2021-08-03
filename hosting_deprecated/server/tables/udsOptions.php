<?php

namespace e10pro\hosting\server;

use \E10\Application, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable, \E10\utils;


/**
 * Class TableUdsOptions
 * @package e10pro\hosting\server
 */
class TableUdsOptions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.server.udsOptions', 'e10pro_hosting_server_udsOptions', 'Nastavení zdrojů dat uživatelů');
	}
}


/**
 * Class FormUDSOptions
 * @package e10pro\hosting\server
 */
class FormUDSOptions extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('favorite');
			$this->addColumnInput ('dsOrder');
		$this->closeForm ();
	}
}
