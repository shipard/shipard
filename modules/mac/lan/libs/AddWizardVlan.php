<?php

namespace mac\lan\libs;

use e10\TableForm, e10\Wizard;


/**
 * Class AddWizardVlan
 * @package mac\lan\libs
 */
class AddWizardVlan extends Wizard
{
	var $tableDevices;

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

	public function enumLans ()
	{
		$lans = $this->app()->db()->query ('SELECT ndx, fullName FROM [mac_lan_lans] WHERE [docStateMain] < 4 ORDER BY fullName')->fetchPairs('ndx', 'fullName');
		return $lans;
	}

	public function renderFormWelcome ()
	{
		$neededLan = intval($this->app()->testGetParam ('__lan'));
		$enumLans = $this->enumLans();
		$this->recData['lan'] = ($neededLan) ? $neededLan : key($enumLans);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('vlanNumber', 'Číslo', self::INPUT_STYLE_INT, 0, 0,FALSE, '2');
			$this->addInput('vlanName', 'Název', self::INPUT_STYLE_STRING, 0, 80, FALSE, 'Správci sítě');
			$this->addInput('vlanId', 'Id', self::INPUT_STYLE_STRING, 0, 80, FALSE, 'admins');
			$this->addInput('addrRange', 'Adresní rozsah', self::INPUT_STYLE_STRING, 0, 40, FALSE, '10.11.12');
			$this->addInputEnum2('lan', 'Síť', $enumLans, TableForm::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	public function doIt ()
	{
		// -- VLAN
		/** @var \e10\DbTable $tableVlans */
		$tableVlans = $this->app()->table ('mac.lan.vlans');
		$recData = [
			'num' => intval($this->recData['vlanNumber']),
			'id' => $this->recData['vlanId'],
			'fullName' => $this->recData['vlanName'],
			'lan' => intval($this->recData['lan']),
			'docState' => 4000, 'docStateMain' => 2,
		];
		$newVlanNdx = $tableVlans->dbInsertRec($recData);
		$tableVlans->docsLog($newVlanNdx);

		// -- address range
		/** @var \e10\DbTable $tableAddrRanges */
		$tableAddrRanges = $this->app()->table ('mac.lan.lansAddrRanges');

		$rangeParts = explode('.', $this->recData['addrRange']);
		$cntRangeParts = count($rangeParts);

		$fullRange = $this->recData['addrRange'].str_repeat('.0', 4 - $cntRangeParts).'/'.strval($cntRangeParts*8);

		$recData = [
			'id' => $this->recData['vlanId'].'-'.$this->recData['addrRange'],
			'fullName' => $this->recData['vlanName'],
			'shortName' => $this->recData['addrRange'].str_repeat('.0', 4 - $cntRangeParts),
			'range' => $fullRange,
			'addressPrefix' => $this->recData['addrRange'].'.',
			'lan' => $this->recData['lan'],
			'vlan' => $newVlanNdx,
			'dhcpPoolBegin' => 25, 'dhcpPoolEnd' => 254,
			'docState' => 4000, 'docStateMain' => 2,
		];
		$newAddrRangeNdx = $tableAddrRanges->dbInsertRec($recData);
		$tableAddrRanges->docsLog($newAddrRangeNdx);

		// -- close wizard
		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-road';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat novou VLANu'];
		$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => 'Automaticky bude vytvořen i propojený adresní rozsah', 'icon' => 'icon-arrows-h']];

		return $hdr;
	}
}
