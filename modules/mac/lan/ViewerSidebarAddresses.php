<?php

namespace mac\lan;

use e10\TableView, e10\utils;


/**
 * Class ViewerSidebarAddresses
 * @package mac\lan
 */
class ViewerSidebarAddresses extends TableView
{
	var $existedAddresses = [];
	var $addrRange = 0;
	var $thisSuffix = '';
	var $rangeRecData = NULL;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = FALSE;
		$this->objectSubType = TableView::vsMini;
		$this->htmlRowsElementClass = 'e10-boxes-viewer';
	}

	public function createToolbar ()
	{
		return [];
	}

	public function selectRows ()
	{
		$this->ok = 1;
		$this->rowsPageSize = 1000;
		$this->queryRows = [];

		if ($this->rowsPageNumber)
			return;

		$this->addrRange = 0;
		$this->addrRange = intval($this->queryParam('addrRange'));
		$this->thisSuffix = $this->queryParam('thisSuffix');

		$this->loadExistedAddresses();

		$defaultClass = '';

		if ($this->rangeRecData)
				$this->queryRows [] = ['ndx' => 0, 'groupName' => ['text' => $this->rangeRecData['fullName'], 'suffix' => $this->rangeRecData['range']]];

		for ($i = 1; $i < 255; $i++)
		{
			if ($this->rangeRecData && $this->rangeRecData['dhcpPoolBegin'] == $i)
			{
				$this->queryRows [] = ['ndx' => 0, 'groupName' => 'DHCP pool - nepoužívat'];
				$defaultClass = 'e10-row-this';
			}

			$this->queryRows [] = ['ndx' => $i, 'name' => strval($i), 'defaultClass' => $defaultClass];

			if ($i != 254 && $this->rangeRecData && $this->rangeRecData['dhcpPoolEnd'] == $i)
			{
				$this->queryRows [] = ['ndx' => 0, 'groupName' => 'Další adresy'];
				$defaultClass = '';
			}
		}
	}

	public function renderRow ($item)
	{
		if (isset($item['groupName']))
		{
			$listItem ['groupName'] = $item['groupName'];
			return $listItem;
		}

		$listItem ['pk'] = $item['ndx'];
		$listItem ['name'] = $item ['name'];

		if (isset($this->existedAddresses[$item['ndx']]))
		{
			if ($item['ndx'] == $this->thisSuffix)
				$listItem['class'] = 'e10-row-pause';
			else
				$listItem['class'] = 'e10-row-minus';
			$listItem['deviceTitle'] = $this->existedAddresses[$item['ndx']]['deviceTitle'];
		}
		else
		{
			$listItem ['data-cc']['ipAddressSuffix'] = strval($item['ndx']);

			if ($item['defaultClass'] !== '')
				$listItem['class'] = $item['defaultClass'];
		}

		return $listItem;
	}

	public function rowHtmlContent ($listItem)
	{
		$c = '';
		$c .= "<div style='border: 1px solid gray; padding: 2px; min-width: 2.5em;'";
		if (isset($listItem['deviceTitle']))
			$c .= ' title="'.utils::es($listItem['deviceTitle']).'"';
		$c .= '>';
		$c .= utils::es($listItem['name']);
		$c .= '</div>';
		return $c;
	}

	public function loadExistedAddresses ()
	{
		$this->rangeRecData = $this->app()->loadItem($this->addrRange, 'mac.lan.lansAddrRanges');

		$q[] = 'SELECT ifaces.*, devices.ndx AS deviceNdx, devices.fullName as deviceFullName, devices.deviceKind, devices.id as deviceId';
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON ifaces.device = devices.ndx');
		array_push ($q, ' WHERE devices.docStateMain < 3');
		array_push ($q, ' AND ifaces.[range] = %i', $this->addrRange);
		array_push ($q, ' ORDER BY ifaces.ndx');

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = ['deviceTitle' => $r['deviceId'].': '.$r['deviceFullName']];
			$this->existedAddresses[$r['ipAddressSuffix']] = $newItem;
		}
	}
}
