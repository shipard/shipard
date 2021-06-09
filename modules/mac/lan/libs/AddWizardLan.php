<?php

namespace mac\lan\libs;

use e10\TableForm, e10\Wizard;


/**
 * Class AddWizardLan
 * @package mac\lan\libs
 */
class AddWizardLan extends Wizard
{
	var $tableDevices;

	var $lanTemplate = [
		'vlans' => [
			['num' => 2, 'name' => 'Management aktivních prvků', 'id' => 'mng', 'ipRangeNum' => 2, 'poolBegin' => 0, 'poolEnd' => 0],
			['num' => 7, 'name' => 'Správci sítě', 'id' => 'admins', 'ipRangeNum' => 7, 'poolBegin' => 180, 'poolEnd' => 220],
			['num' => 3, 'name' => 'Kamerový systém', 'id' => 'cams', 'ipRangeNum' => 3, 'poolBegin' => 101, 'poolEnd' => 220],
			['num' => 6, 'name' => 'Servery', 'id' => 'servers', 'ipRangeNum' => 6, 'poolBegin' => 101, 'poolEnd' => 220, 'enabledGroups' => ['g-users']],
			['num' => 8, 'name' => 'Tiskárny', 'id' => 'printers', 'ipRangeNum' => 8, 'poolBegin' => 101, 'poolEnd' => 220, 'enabledGroups' => ['g-users']],
			['num' => 4, 'name' => 'Management WiFi', 'id' => 'mng-wifi', 'ipRangeNum' => 4, 'poolBegin' => 11, 'poolEnd' => 220],

			['num' => 101, 'name' => 'Uživatelé ETH', 'id' => 'users-eth', 'ipRangeNum' => 101, 'poolBegin' => 101, 'poolEnd' => 250],
			['num' => 102, 'name' => 'Uživatelé WiFi', 'id' => 'users-wifi', 'ipRangeNum' => 102, 'poolBegin' => 101, 'poolEnd' => 250],
			['num' => 201, 'name' => 'Hosté ETH', 'id' => 'guests-eth', 'ipRangeNum' => 201, 'poolBegin' => 21, 'poolEnd' => 250],
			['num' => 202, 'name' => 'Hosté WiFi', 'id' => 'guests-wifi', 'ipRangeNum' => 202, 'poolBegin' => 21, 'poolEnd' => 250],
		],
		'vlanGroups' => [
			['name' => 'Uživatelé', 'id' => 'g-users', 'vlans' => ['users-eth', 'users-wifi']]
		]
	];

	public function doStep ()
	{
		if ($this->pageNumber === 2)
		{
			$this->doIt();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormPreview (); break;
			case 2: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->recData['ipAddrSecondNumber'] = strval(mt_rand(4, 252));

		$enumRouter = $this->enumRouter();
		$this->recData['routerType'] = key($enumRouter);
		$enumRouterMacLan = $this->enumMacLanType(8);
		$this->recData['routerMacLan'] = key($enumRouterMacLan);

		$enumSwitch = $this->enumSwitch();
		$this->recData['switchType'] = key($enumSwitch);
		$enumSwitchMacLan = $this->enumMacLanType(9);
		$this->recData['switchMacLan'] = key($enumSwitchMacLan);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInput('lanName', 'Název', self::INPUT_STYLE_STRING, 0, 80, FALSE, 'Naše síť');
			$this->addInput('ipAddrSecondNumber', 'Hlavní číslo pro IP adresy (10.XXX.1.1)', self::INPUT_STYLE_INT, 0, 0,FALSE);

			$this->addSeparator(self::coH2);
			$this->addInputEnum2('routerType', 'Router', $enumRouter, TableForm::INPUT_STYLE_OPTION);
			$this->addInputEnum2('routerMacLan', 'Typ', $enumRouterMacLan, TableForm::INPUT_STYLE_OPTION);

			$this->addSeparator(self::coH2);
			$this->addInputEnum2('switchType', 'Switch', $enumSwitch, TableForm::INPUT_STYLE_OPTION);
			$this->addInputEnum2('switchMacLan', 'Typ', $enumSwitchMacLan, TableForm::INPUT_STYLE_OPTION);
		$this->closeForm ();
	}

	public function renderFormPreview()
	{
		if (!intval($this->recData['ipAddrSecondNumber']))
			$this->recData['ipAddrSecondNumber'] = strval(mt_rand(4, 252));

		if ($this->recData['lanName'] === '')
			$this->recData['lanName'] = 'Naše síť';

		$lc = new \mac\lan\libs\LanCreator($this->app());
		$lc->setTemplate($this->lanTemplate);
		$lpc = $lc->createPreviewContent($this->recData['lanName'], $this->recData['ipAddrSecondNumber']);

		$this->setFlag ('formStyle', 'e10-formStyleWizard');

		$this->openForm (self::ltNone);
			$this->addInput('lanName', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->addInput('ipAddrSecondNumber', '', self::INPUT_STYLE_STRING, self::coHidden, 240);

			$this->addInput('routerType', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->addInput('routerMacLan', '', self::INPUT_STYLE_STRING, self::coHidden, 30);

			$this->addInput('switchType', '', self::INPUT_STYLE_STRING, self::coHidden, 240);
			$this->addInput('switchMacLan', '', self::INPUT_STYLE_STRING, self::coHidden, 240);
			$this->addContent($lpc);
		$this->closeForm ();
	}

	public function doIt ()
	{
		$lc = new \mac\lan\libs\LanCreator($this->app());
		$lc->setTemplate($this->lanTemplate);
		$lc->save($this->recData['lanName'], $this->recData['ipAddrSecondNumber'], $this->recData);

		// -- close wizard
		$this->stepResult ['close'] = 1;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'system/iconSitemap';
		$hdr ['info'][] = ['class' => 'title', 'value' => 'Přidat novou síť'];
		$hdr ['info'][] = ['class' => 'info', 'value' => ['text' => 'Průvodce vytvoří novou síť včetně základního nastavení VLAN a rozsahů', 'icon' => 'icon-plus-square']];

		return $hdr;
	}

	public function enumRouter()
	{
		$enum = $this->app()->db()->query ('SELECT ndx, fullName FROM [mac_lan_deviceTypes] WHERE [deviceKind] = 8 AND [docStateMain] < 4 ORDER BY fullName')->fetchPairs('ndx', 'fullName');
		return $enum;
	}

	public function enumSwitch()
	{
		$enum = $this->app()->db()->query ('SELECT ndx, fullName FROM [mac_lan_deviceTypes] WHERE [deviceKind] = 9 AND [docStateMain] < 4 ORDER BY fullName')->fetchPairs('ndx', 'fullName');
		return $enum;
	}

	public function enumMacLanType($deviceKind)
	{
		$allTypes = $this->app()->cfgItem('mac.devices.types');
		$enum = [];
		foreach ($allTypes as $typeId => $typeCfg)
		{
			if ($typeCfg['dk'] != $deviceKind)
				continue;
			$enum[$typeId] = $typeCfg['fn'];
		}

		return $enum;
	}
}
