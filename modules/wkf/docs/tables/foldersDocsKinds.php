<?php

namespace wkf\docs;
use \e10\TableForm, \e10\DbTable;


/**
 * Class TableFoldersDocsKinds
 * @package wkf\docs
 */
class TableFoldersDocsKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.docs.foldersDocsKinds', 'wkf_docs_foldersDocsKinds', 'Druhy dokumentů ve složkách');
	}
}


/**
 * Class FormFolderDocKind
 * @package wkf\docs
 */
class FormFolderDocKind extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('docKind', TableForm::coColW12);
			$this->closeRow();
		$this->closeForm ();
	}
}

