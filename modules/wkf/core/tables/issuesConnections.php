<?php

namespace wkf\core;
use \e10\TableForm, \e10\DbTable;


/**
 * Class TableIssuesConnections
 * @package wkf\core
 */
class TableIssuesConnections extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.core.issuesConnections', 'wkf_core_issuesConnections', 'Propojení zpráv', 1242);
	}
}


/**
 * Class FormIssueConnection
 * @package wkf\core
 */
class FormIssueConnection extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('connectionType', TableForm::coColW2);
				$this->addColumnInput ('connectedIssue', TableForm::coColW10);
			$this->closeRow();
		$this->closeForm ();
	}
}

