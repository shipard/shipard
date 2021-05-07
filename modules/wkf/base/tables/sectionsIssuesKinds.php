<?php

namespace wkf\base;
use \e10\TableForm, \e10\DbTable;


/**
 * Class TableSectionsIssuesKinds
 * @package wkf\base
 */
class TableSectionsIssuesKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.sectionsIssuesKinds', 'wkf_base_sectionsIssuesKinds', 'Druhy zpráv v sekcích', 1247);
	}
}


/**
 * Class FormSectionIssueKind
 * @package wkf\base
 */
class FormSectionIssueKind extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('issueKind', TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}
}

