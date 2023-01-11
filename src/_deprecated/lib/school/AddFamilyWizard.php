<?php

namespace lib\School;

require_once __APP_DIR__ . '/e10-modules/e10/persons/tables/persons.php';

use \E10\TableForm;


/**
 * Class AddFamilyWizard
 * @package lib\School
 */
class AddFamilyWizard extends \e10\persons\libs\AddWizardFromID
{
	public function addParams ()
	{
		parent::addParams();

		$addToClass = $this->app->testGetParam('addToClass');
		$this->recData['addToClass'] = $addToClass;
		$this->addInput('addToClass', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();

			$this->layoutOpen(TableForm::ltHorizontal);
				$this->layoutOpen(TableForm::ltForm);
					$this->addStatic('Student', TableForm::coH2);
					$this->renderFormWelcome_AddOnePerson ('S');
				$this->layoutClose('width50');

				$this->layoutOpen(TableForm::ltForm);
				$this->layoutClose();
			$this->layoutClose();

			$this->layoutOpen(TableForm::ltHorizontal);
				$this->layoutOpen(TableForm::ltForm);
					$this->addStatic('Matka', TableForm::coH2);
					$this->renderFormWelcome_AddOnePerson ('M');
				$this->layoutClose();

				$this->layoutOpen(TableForm::ltForm);
					$this->addStatic('Otec', TableForm::coH2);
					$this->renderFormWelcome_AddOnePerson ('F');
				$this->layoutClose();
			$this->layoutClose();

			$this->addParams();
		$this->closeForm ();
	}

	public function savePerson ()
	{
		$this->recData['addToGroups'] = '@e10-schoolchilds';
		$studentNdx = parent::saveOnePerson ('S');

		$this->recData['addToGroups'] = '@e10-parents';
		$motherNdx = parent::saveOnePerson ('M');
		$fatherNdx = parent::saveOnePerson ('F');

		// -- add to class
		$addToClass = $this->recData['addToClass'];
		if ($addToClass != '')
			$this->app->db()->query ('INSERT INTO [school_core_classesStudents] ([student], [class]) VALUES (%i, %i)', $studentNdx, $addToClass);

		// -- set connections
		$this->app->db()->query ('INSERT INTO [e10_persons_connections] ([person], [connectedPerson], [connectionType]) VALUES (%i, %i, %s)',
															$studentNdx, $motherNdx, 'mother');
		$this->app->db()->query ('INSERT INTO [e10_persons_connections] ([person], [connectedPerson], [connectionType]) VALUES (%i, %i, %s)',
															$studentNdx, $fatherNdx, 'father');

		// -- close wizard
		$this->stepResult ['close'] = 1;
	}
}
