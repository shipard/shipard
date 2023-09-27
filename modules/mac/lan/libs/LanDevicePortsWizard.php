<?php

namespace mac\lan\libs;

use \Shipard\Form\TableForm, \Shipard\Form\Wizard;


/**
 * class LanDevicePortsWizard
 */
class LanDevicePortsWizard extends Wizard
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;

  public function init()
  {
    $this->tableDevices = $this->app()->table('mac.lan.devices');
  }

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->setPorts();
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
		$lanDeviceNdx = $this->app()->testGetParam ('__lanDeviceNdx');


		$this->recData['lanDeviceNdx'] = $lanDeviceNdx;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

    $this->init();
    $portsInfo = [];
    $this->tableDevices->checkPorts($this->recData['lanDeviceNdx'], $portsInfo);

		$this->openForm ();
			$this->addInput('lanDeviceNdx', '', TableForm::INPUT_STYLE_STRING, self::coHidden);
      if (isset($portsInfo['allPorts']))
      {
        $h = ['#' => '#', 'portId' => 'portId', '_note' => 'Pozn.'];
        $this->addStatic(['type' => 'table', 'table' => $portsInfo['allPorts'], 'header' => $h]);
      }
    $this->closeForm ();
	}

	public function setPorts ()
	{
    $this->init();
    $portsInfo = [];
    $this->tableDevices->checkPorts($this->recData['lanDeviceNdx'], $portsInfo, TRUE);

		$this->stepResult ['close'] = 1;
	}
}
