<?php

namespace mac\lan;

use e10\TableForm, e10\Wizard;


/**
 * Class AddDeviceWizard
 * @package mac\lan
 */
class AddDeviceWizard extends Wizard
{
	var $tableDevices;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->saveDevice();
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

	public function deviceTypes ()
	{
		$dt = $this->app()->db()->query ('SELECT ndx, fullName FROM [mac_lan_deviceTypes] WHERE [docStateMain] < 4 ORDER BY fullName')->fetchPairs('ndx', 'fullName');
		return $dt;
	}

	public function renderFormWelcome ()
	{
		$neededLan = intval($this->app()->testGetParam ('__lan'));
		$enumLans = $this->enumLans();
		$this->recData['lan'] = ($neededLan) ? $neededLan : key($enumLans);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInputEnum2('deviceType', 'Typ zařízení', $this->deviceTypes (), TableForm::INPUT_STYLE_OPTION);
			$this->addInput('fullName', 'Název', self::INPUT_STYLE_STRING, 0, 80);
			$this->addInputEnum2('lan', 'Síť', $enumLans, TableForm::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	public function saveDevice ()
	{
		$this->tableDevices = $this->app()->table ('mac.lan.devices');
		$newDeviceNdx = $this->tableDevices->createDeviceFromType ($this->recData);

		// -- close wizard
		$this->stepResult ['close'] = 1;
		$this->stepResult ['editDocument'] = 1;
		$this->stepResult ['params'] = ['table' => 'mac.lan.devices', 'pk' => $newDeviceNdx];
	}

	public function enumLans ()
	{
		$lans = $this->app()->db()->query ('SELECT ndx, fullName FROM [mac_lan_lans] WHERE [docStateMain] < 4 ORDER BY fullName')->fetchPairs('ndx', 'fullName');
		return $lans;
	}
}
