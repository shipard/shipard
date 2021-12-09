<?php

namespace hosting\core;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable, \E10\utils;


/**
 * Class TableDSUsersOptions
 */
class TableDSUsersOptions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.dsUsersOptions', 'hosting_core_dsUsersOptions', 'Nastavení zdrojů dat uživatelů');
	}
}


/**
 * Class FormDSUserOptions
 */
class FormDSUserOptions extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('addToToolbar');
			$this->addColumnInput ('toolbarOrder');

			$this->addColumnInput ('addToDashboard');
			$this->addColumnInput ('dashboardOrder');
		$this->closeForm ();
	}
}
