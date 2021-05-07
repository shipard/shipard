<?php

namespace services\subjects;



use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableSubjectsBranches
 * @package services\subjects
 */
class TableSubjectsBranches extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.subjectsBranches', 'services_subjects_subjectsBranches', 'Obory jednotlivých subjektů');
	}
}


/**
 * Class FormSubjectBranch
 * @package services\subjects
 */
class FormSubjectBranch extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltGrid);
			$this->addColumnInput ('branch', TableForm::coColW12);
		$this->closeForm ();
	}
}

