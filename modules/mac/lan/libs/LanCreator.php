<?php

namespace mac\lan\libs;

use e10\Utility, \e10\utils, \e10\str;


/**
 * Class LanCreator
 * @package mac\lan\libs
 */
class LanCreator extends Utility
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;
	/** @var \mac\lan\TableLans */
	var $tableLans;
	/** @var \mac\lan\TableVlans */
	var $tableVlans;
	/** @var \mac\lan\TableLansAddrRanges */
	var $tableAddrRanges;
	/** @var \mac\lan\TableRacks */
	var $tableRacks;

	var $lanTemplate = NULL;
	var $lanData = [];

	var $lanRecData;

	public function setTemplate($lanTemplate)
	{
		$this->lanTemplate = $lanTemplate;
	}

	public function createPreviewContent($lanName, $ipSecondNumber)
	{
		$this->createLanData($lanName, $ipSecondNumber);

		$previewContent = [];

		$header = ['vlanNumber' => ' VLAN', 'name' => 'Název', 'id' => 'id', 'range' => 'Rozsah adres', 'pool' => ' DHCP pool'];
		$table = [];

		foreach ($this->lanData['vlans'] as $vlan)
		{
			$item = [
				'vlanNumber' => $vlan['num'], 'name' => $vlan['fullName'], 'id' => $vlan['id'],
				'range' => $vlan['range']['range'],
			];

			if ($vlan['range']['dhcpPoolBegin'] || $vlan['range']['dhcpPoolEnd'])
				$item['pool'] = $vlan['range']['dhcpPoolBegin'].' - '.$vlan['range']['dhcpPoolEnd'];

			$table[] = $item;
		}

		$previewContent[] = ['type' => 'table', 'table' => $table, 'header' => $header];

		return $previewContent;
	}

	function createLanData($lanName, $ipSecondNumber)
	{
		$this->lanData['vlans'] = [];
		$this->lanData['vlanGroups'] = [];

		foreach ($this->lanTemplate['vlanGroups'] as $vg)
		{
			$this->lanData['vlanGroups'][$vg['id']] = [
				'id' => $vg['id'], 'name' => $vg['name'], 'vlans' => $vg['vlans']
			];
		}

		foreach ($this->lanTemplate['vlans'] as $vlan)
		{
			$vlanItem = ['num' => $vlan['num'], 'fullName' => $vlan['name'], 'id' => $vlan['id']];

			$addrBaseRange = '10.'.$ipSecondNumber.'.'.$vlan['ipRangeNum'];

			$rangeItem = [
				'id' => $vlanItem['id'].'-'.$addrBaseRange,
				'fullName' => $vlanItem['fullName'],
				'shortName' => $addrBaseRange.'.0',
				'range' => $addrBaseRange.'.0/24',
				'addressPrefix' => $addrBaseRange.'.',
				'dhcpPoolBegin' => $vlan['poolBegin'], 'dhcpPoolEnd' => $vlan['poolEnd'],
			];

			if (isset($vlan['enabledGroups']))
				$vlanItem['enabledGroups'] = $vlan['enabledGroups'];

			$vlanItem['range'] = $rangeItem;

			$this->lanData['vlans'][$vlan['id']] = $vlanItem;
		}
	}

	public function save($lanName, $ipSecondNumber, $formRecData)
	{
		$this->createLanData($lanName, $ipSecondNumber);

		$this->tableDevices = $this->app()->table('mac.lan.devices');
		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableVlans = $this->app()->table('mac.lan.vlans');
		$this->tableAddrRanges = $this->app()->table ('mac.lan.lansAddrRanges');
		$this->tableRacks = $this->app()->table('mac.lan.racks');

		// -- create LAN
		$this->lanRecData = [
			'fullName' => $lanName, 'shortName' => str::upToLen($lanName, 60),
			'docState' => 4000, 'docStateMain' => 2,
		];

		$newLanNdx = $this->tableLans->dbInsertRec($this->lanRecData);
		$this->lanRecData = $this->tableLans->loadItem($newLanNdx);

		$lanWifiManagementVlan = 0;

		// -- create vlans & ranges
		foreach ($this->lanData['vlans'] as $vlanId => $vlan)
		{
			// -- vlan
			$recData = [
				'num' => intval($vlan['num']),
				'id' => $vlan['id'],
				'fullName' =>  $vlan['fullName'],
				'lan' => $newLanNdx,
				'docState' => 4000, 'docStateMain' => 2,
			];
			$newVlanNdx = $this->tableVlans->dbInsertRec($recData);
			$this->tableVlans->docsLog($newVlanNdx);
			$this->lanData['vlans'][$vlanId]['ndx'] = $newVlanNdx;

			switch($vlan['id'])
			{
				case 'mng': $this->lanRecData['vlanManagement'] = $newVlanNdx; break;
				case 'admins': $this->lanRecData['vlanAdmins'] = $newVlanNdx; break;
				case 'mng-wifi': $lanWifiManagementVlan = $newVlanNdx; break;
			}

			// -- range
			$recData = [
				'id' => $vlan['range']['id'],
				'fullName' => $vlan['range']['fullName'],
				'shortName' => $vlan['range']['shortName'],
				'range' => $vlan['range']['range'],
				'addressPrefix' => $vlan['range']['addressPrefix'],
				'dhcpPoolBegin' => $vlan['range']['dhcpPoolBegin'], 'dhcpPoolEnd' => $vlan['range']['dhcpPoolEnd'],
				'lan' => $newLanNdx, 'vlan' => $newVlanNdx,
				'docState' => 4000, 'docStateMain' => 2,
			];
			$newAddrRangeNdx = $this->tableAddrRanges->dbInsertRec($recData);
			$this->tableAddrRanges->docsLog($newAddrRangeNdx);
		}

		// -- wifi management vlan
		if ($lanWifiManagementVlan)
		{
			$newLink = [
				'linkId' => 'mac-lans-wifi-mng-vlans',
				'srcTableId' => 'mac.lan.lans', 'srcRecId' => $newLanNdx,
				'dstTableId' => 'mac.lan.vlans', 'dstRecId' => $lanWifiManagementVlan
			];
			$this->app()->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
		}

		// -- update LAN
		$this->tableLans->dbUpdateRec($this->lanRecData);
		$this->tableLans->docsLog($newLanNdx);

		// -- vlan groups
		foreach ($this->lanData['vlanGroups'] as $vgId => $vg)
		{
			$recData = [
				'isGroup' => 1,
				'id' => $vg['id'],
				'fullName' =>  $vg['name'],
				'lan' => $newLanNdx,
				'docState' => 4000, 'docStateMain' => 2,
			];
			$newVGNdx = $this->tableVlans->dbInsertRec($recData);
			$this->lanData['vlanGroups'][$vgId]['ndx'] = $newVGNdx;

			foreach ($vg['vlans'] as $vlanId)
			{
				$vlanNdx = $this->lanData['vlans'][$vlanId]['ndx'];
				$newLink = [
					'linkId' => 'mac-lan-vlans-groups',
					'srcTableId' => 'mac.lan.vlans', 'srcRecId' => $vlanNdx,
					'dstTableId' => 'mac.lan.vlans', 'dstRecId' => $newVGNdx
				];
				$this->app()->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
			}
			$this->tableVlans->docsLog($newVGNdx);
		}

		// -- vlans enabled groups
		foreach ($this->lanData['vlans'] as $vlanId => $vlan)
		{
			if (!isset($vlan['enabledGroups']))
				continue;
			foreach ($vlan['enabledGroups'] as $vgId)
			{
				$vgNdx = $this->lanData['vlanGroups'][$vgId]['ndx'];
				$newLink = [
					'linkId' => 'mac-lan-vlans-incoming',
					'srcTableId' => 'mac.lan.vlans', 'srcRecId' => $vlan['ndx'],
					'dstTableId' => 'mac.lan.vlans', 'dstRecId' => $vgNdx
				];
				$this->app()->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
			}
		}

		// rack
		$recData = [
			'id' => 'R1', 'fullName' => 'R1', 'lan' => $newLanNdx, 'rackKind' => 10,
			'docState' => 4000, 'docStateMain' => 2,
		];
		$newRackNdx = $this->tableRacks->dbInsertRec($recData);
		$this->tableRacks->docsLog($newRackNdx);

		// -- create devices
		// -- router
		$dataRouter = [
			'deviceType' => $formRecData['routerType'], 'macDeviceType' => $formRecData['routerMacLan'],
			'fullName' => 'R-1', 'id' => 'R-1', 'docState' => 4000, 'docStateMain' => 2, 'rack' => $newRackNdx,
			'lan' => $this->lanRecData['ndx']
		];
		$newRouterNdx = $this->tableDevices->createDeviceFromType ($dataRouter);
		$this->lanRecData['mainRouter'] = $newRouterNdx;

		// -- switch
		$dataSwitch = [
			'deviceType' => $formRecData['switchType'], 'macDeviceType' => $formRecData['switchMacLan'],
			'fullName' => 'S-1', 'id' => 'S-1', 'docState' => 4000, 'docStateMain' => 2, 'rack' => $newRackNdx,
			'lan' => $this->lanRecData['ndx']
		];
		$newSwitchNdx = $this->tableDevices->createDeviceFromType ($dataSwitch);

		// -- update lan recData with main router
		$this->tableLans->dbUpdateRec($this->lanRecData);
		$this->tableLans->docsLog($newLanNdx);


		\E10\compileConfig ();
	}
}
