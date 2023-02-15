<?php

namespace services\persons\libs;


use \Shipard\Form\Wizard, \Shipard\Utils\Json;


/**
 * class WizardPersonRefresh
 */
class WizardPersonRefresh extends Wizard
{
	var $personNdx = 0;
	var $personRecData;

	function init()
	{
		$this->personNdx = ($this->focusedPK) ? $this->focusedPK : $this->recData['personNdx'];
		$this->personRecData = $this->app()->loadItem($this->personNdx, 'services.persons.persons');

		if (!isset($this->recData['personNdx']))
			$this->recData['personNdx'] = $this->personNdx;
	}

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->rebuild();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('personNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
		$this->closeForm ();
	}

	public function rebuild ()
	{
		$this->init();

    $e = new \services\persons\libs\PersonData($this->app());
    $e->refreshImport(intval($this->recData['personNdx']));

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'system/iconSpinner';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Obnovit data osoby z registů'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->personRecData['fullName']];

		return $hdr;
	}
}
