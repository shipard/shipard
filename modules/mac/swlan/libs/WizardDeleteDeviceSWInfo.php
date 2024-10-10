<?php

namespace mac\swlan\libs;
use \Shipard\Form\Wizard;


/**
 * class WizardDeleteDeviceSWInfo
 */
class WizardDeleteDeviceSWInfo extends Wizard
{
	var $deviceNdx = 0;

	function init()
	{
		$this->deviceNdx = intval($this->focusedPK);
  }

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt();
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
    $this->init();

    $this->recData['deviceNdx'] = $this->deviceNdx;

    $this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
      $this->addInput('deviceNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 120);
    $this->closeForm ();
	}

	public function doIt ()
	{
		$deviceNdx = $this->recData['deviceNdx'];

    $this->app()->db()->query('DELETE FROM [mac_swlan_devicesSWHistory] WHERE [device] = %i', $deviceNdx);
    $this->app()->db()->query('DELETE FROM [mac_swlan_devicesSW] WHERE [device] = %i', $deviceNdx);

		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$this->init();

		$hdr = [];
		$hdr ['icon'] = 'system/actionDelete';
    $hdr ['info'][] = ['class' => 'title', 'value' => 'Smazat informace o SW'];

		return $hdr;
	}
}
