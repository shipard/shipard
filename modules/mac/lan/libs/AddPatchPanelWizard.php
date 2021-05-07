<?php

namespace mac\lan\libs;

use e10\TableForm, e10\Wizard;


/**
 * Class AddPatchPanelWizard
 * @package mac\lan
 */
class AddPatchPanelWizard extends Wizard
{
	/** @var \mac\lan\TablePatchPanels */
	var $tablePatchPanels;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->savePatchPanel();
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

	public function patchPanelsKinds ()
	{
		$enum = [];
		$allPPK = $this->app()->cfgItem('mac.lan.patchPanels.kinds', []);
		foreach ($allPPK as $ppkNdx => $ppk)
			$enum[$ppkNdx] = $ppk['fn'];
		return $enum;
	}

	public function renderFormWelcome ()
	{
		$neededRack = 0;

		$leftPanelTreeValue = $this->app()->testGetParam ('__rack');

		if ($leftPanelTreeValue !== '' && $leftPanelTreeValue[0] !== 'L')
			$neededRack = intval($leftPanelTreeValue);

		$enumRacks = $this->enumRacks();
		$this->recData['rack'] = ($neededRack) ? $neededRack : key($enumRacks);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInputEnum2('patchPanelKind', 'Typ patchPanelu', $this->patchPanelsKinds (), TableForm::INPUT_STYLE_OPTION);
			$this->addInput('fullName', 'Název', self::INPUT_STYLE_STRING, 0, 80);
			$this->addInputEnum2('rack', 'Rack', $enumRacks, TableForm::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	public function savePatchPanel ()
	{
		$this->tablePatchPanels = $this->app()->table ('mac.lan.patchPanels');
		$newPatchPanelNdx = $this->tablePatchPanels->createPatchPanelFromKind ($this->recData);

		// -- close wizard
		$this->stepResult ['close'] = 1;
		$this->stepResult ['editDocument'] = 1;
		$this->stepResult ['params'] = ['table' => 'mac.lan.patchPanels', 'pk' => $newPatchPanelNdx];
	}

	public function enumRacks ()
	{
		$racks = $this->app()->db()->query ('SELECT ndx, fullName FROM [mac_lan_racks] WHERE [docStateMain] < 4 ORDER BY fullName')->fetchPairs('ndx', 'fullName');
		return $racks;
	}
}
