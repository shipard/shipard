<?php

namespace mac\lan\libs;

use e10\TableForm, e10\Wizard;


/**
 * Class ConnectDeviceWizard
 * @package mac\lan\libs
 */
class ConnectDeviceWizard extends Wizard
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableDevicesPorts */
	var $tableDevicesPorts;

	var $srcDeviceRecData = NULL;
	var $srcPortRecData = NULL;
	var $dstDeviceRecData = NULL;
	var $dstPortRecData = NULL;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
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
		$this->recData['connectedTo'] = intval($this->app->testGetParam('connectedTo'));
		$this->recData['srcDevice'] = intval($this->app->testGetParam('srcDevice'));
		$this->recData['srcPort'] = intval($this->app->testGetParam('srcPort'));
		$this->recData['dstDevice'] = intval($this->app->testGetParam('dstDevice'));
		$this->recData['dstPort'] = intval($this->app->testGetParam('dstPort'));

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->initInfo();


		$info = [
			['text' => 'Propojit zařízení', 'class' => 'h2 block mb1'],
			['text' => $this->srcDeviceRecData['fullName'], 'suffix' => $this->srcPortRecData['portId'], 'icon' => $this->tableDevices->tableIcon($this->srcDeviceRecData), 'class' => 'block padd5'],
			['text' => 'a', 'class' => 'pl1'],
			['text' => $this->dstDeviceRecData['fullName'], 'suffix' => $this->dstPortRecData['portId'], 'icon' => $this->tableDevices->tableIcon($this->dstDeviceRecData), 'class' => 'block padd5'],
			];

		$this->openForm ();
			$this->addInput('connectedTo', '', self::INPUT_STYLE_STRING,self::coHidden, 120);
			$this->addInput('srcDevice', '', self::INPUT_STYLE_STRING,self::coHidden, 120);
			$this->addInput('srcPort', '', self::INPUT_STYLE_STRING,self::coHidden, 120);
			$this->addInput('dstDevice', '', self::INPUT_STYLE_STRING,self::coHidden, 120);
			$this->addInput('dstPort', '', self::INPUT_STYLE_STRING,self::coHidden, 120);

			$this->layoutOpen(self::ltHorizontal);
				$this->addStatic($info);
			$this->layoutClose('pa2');

		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->initInfo();

		$recData = $this->srcPortRecData;
		$recData['OLD_connectedToWallSocket'] = 0;
		$recData['OLD_connectedToDevice'] = $this->recData['dstDevice'];
		$recData['OLD_connectedToPort'] = $this->recData['dstPort'];

		$recData['connectedTo'] = $this->recData['connectedTo'];
		$recData['connectedToDevice'] = $this->recData['dstDevice'];
		$recData['connectedToPort'] = $this->recData['dstPort'];

		$this->tableDevicesPorts->dbUpdateRec($recData);

		// -- close wizard
		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-plug';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Propojit zařízení'];
		$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => 'Tato dvě zařízení mohou být propojena', 'icon' => 'icon-exchange']];

		return $hdr;
	}

	function initInfo()
	{

		$this->tableDevices = $this->app()->table ('mac.lan.devices');
		$this->tableDevicesPorts = $this->app()->table ('mac.lan.devicesPorts');

		$this->srcDeviceRecData = $this->tableDevices->loadItem($this->recData['srcDevice']);
		$this->srcPortRecData = $this->tableDevicesPorts->loadItem($this->recData['srcPort']);
		$this->dstDeviceRecData = $this->tableDevices->loadItem($this->recData['dstDevice']);
		$this->dstPortRecData = $this->tableDevicesPorts->loadItem($this->recData['dstPort']);
	}
}
