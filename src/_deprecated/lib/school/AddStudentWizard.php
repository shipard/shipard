<?php

namespace lib\School;

require_once __APP_DIR__ . '/e10-modules/e10/persons/tables/persons.php';

use \E10\TableForm;


/**
 * Class AddStudentWizard
 * @package lib\School
 */
class AddStudentWizard extends \e10\persons\libs\AddWizardFromID
{
	public function addParams ()
	{
		parent::addParams();

		$addToClass = $this->app->testGetParam('addToClass');
		$this->recData['addToClass'] = $addToClass;
		$this->addInput('addToClass', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
	}

	public function savePerson ()
	{
		parent::savePerson ();

		$addToClass = $this->recData['addToClass'];
		if ($addToClass != '')
			$this->app->db()->query ('INSERT INTO [school_core_classesStudents] ([student], [class]) VALUES (%i, %i)', $this->newPersonNdx, $addToClass);
	}
}
