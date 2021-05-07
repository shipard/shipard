<?php

namespace mac\lan\libs;

use e10\TableForm, e10\Wizard;


/**
 * Class ConnectSocketWizard
 * @package mac\lan\libs
 */
class ConnectSocketWizard extends Wizard
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableDevicesPorts */
	var $tableDevicesPorts;
	/** @var \mac\lan\TableWallSockets */
	var $tableWallSockets;

	var $dstDeviceRecData = NULL;
	var $dstPortRecData = NULL;
	var $dstWallSocketRecData = NULL;

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
		$this->recData['wallSocket'] = intval($this->app->testGetParam('wallSocket'));
		$this->recData['dstDevice'] = intval($this->app->testGetParam('dstDevice'));
		$this->recData['dstPort'] = intval($this->app->testGetParam('dstPort'));

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->initInfo();


		$info = [
			['text' => 'Zapojit zařízení do zásuvky', 'class' => 'h2 block mb1'],
			['text' => $this->dstDeviceRecData['fullName'], 'suffix' => $this->dstPortRecData['portId'], 'icon' => $this->tableDevices->tableIcon($this->dstDeviceRecData), 'class' => 'block padd5'],
			['text' => 'do', 'class' => 'pl1'],
			['text' => $this->dstWallSocketRecData['id'], 'icon' => $this->tableWallSockets->tableIcon($this->dstWallSocketRecData), 'class' => 'block padd5'],
		];

		$this->openForm ();
			$this->addInput('connectedTo', '', self::INPUT_STYLE_STRING,self::coHidden, 120);
			$this->addInput('dstDevice', '', self::INPUT_STYLE_STRING,self::coHidden, 120);
			$this->addInput('dstPort', '', self::INPUT_STYLE_STRING,self::coHidden, 120);
			$this->addInput('wallSocket', '', self::INPUT_STYLE_STRING,self::coHidden, 120);

			$this->layoutOpen(self::ltHorizontal);
			$this->addStatic($info);
			$this->layoutClose('pa2');
		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->initInfo();

		$update = ['connectedTo' => 1, 'connectedToWallSocket' => intval($this->recData['wallSocket'])];
		$this->app()->db()->query('UPDATE [mac_lan_devicesPorts] SET ', $update, ' WHERE [ndx] = %i', $this->recData['dstPort']);

		// -- close wizard
		$this->stepResult ['close'] = 1;
		$this->stepResult ['refreshDetail'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-plug';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Zapojit zařízení do zásuvky'];
		$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => 'Toto zařízení může být zapojeno do zásuvky', 'icon' => 'icon-exchange']];

		return $hdr;
	}

	function initInfo()
	{
		$this->tableDevices = $this->app()->table ('mac.lan.devices');
		$this->tableDevicesPorts = $this->app()->table ('mac.lan.devicesPorts');
		$this->tableWallSockets = $this->app()->table ('mac.lan.wallSockets');

		$this->dstDeviceRecData = $this->tableDevices->loadItem($this->recData['dstDevice']);
		$this->dstPortRecData = $this->tableDevicesPorts->loadItem($this->recData['dstPort']);
		$this->dstWallSocketRecData = $this->tableWallSockets->loadItem($this->recData['wallSocket']);
	}
}
