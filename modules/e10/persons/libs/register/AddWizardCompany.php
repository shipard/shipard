<?php

namespace e10\persons\libs\register;


/**
 * class AddWizardCompany
 */
class AddWizardCompany extends \Shipard\Form\Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
      $this->savePerson();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->stepResult['lastStep'] = 1; $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
      $this->addInput('placebo', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
      $this->addViewerWidget ('e10.persons.persons', 'e10.persons.libs.register.ViewPersonsFromRegister', NULL, TRUE);
		$this->closeForm ();
	}

	public function savePerson ()
	{
		$regNdx = intval($this->recData['viewersPks'][0] ?? 0);

		if ($regNdx)
		{
			$reg = new \e10\persons\libs\register\PersonRegister($this->app());
			$reg->addPerson('*'.$regNdx);

			$this->stepResult ['close'] = 1;
			$this->stepResult ['editDocument'] = 1;
			$this->stepResult ['params'] = ['table' => 'e10.persons.persons', 'pk' => $reg->personNdx];
		}
		else
		{
			$this->stepResult ['close'] = 1;
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'system/personCompany';

    $hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat novou firmu z registru'];
		$hdr ['info'][] = ['class' => 'info', 'value' => 'Zadejte IČ nebo část názvu, vyberte firmu a stiskněte tlačítko Pokračovat'];

		return $hdr;
	}
}
