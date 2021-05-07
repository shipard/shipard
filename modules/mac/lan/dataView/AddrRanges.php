<?php

namespace mac\lan\dataView;

use \lib\dataView\DataView;


/**
 * Class AddrRanges
 * @package e10pro\lan\dataView
 */
class AddrRanges extends DataView
{
	protected function init()
	{
		$this->checkRequestParamsList('lan');

		parent::init();
	}

	protected function loadData()
	{
		$q [] = 'SELECT ranges.*, lans.shortName as lanShortName, vlans.id as vlanId, vlans.num as vlanNum, vlans.fullName as vlanName';
		array_push ($q, ' FROM [mac_lan_lansAddrRanges] AS ranges');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON ranges.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_vlans AS vlans ON ranges.vlan = vlans.ndx');
		array_push ($q, ' WHERE 1');

		if (isset($this->requestParams['lan']))
			array_push ($q, ' AND ranges.[lan] IN %in', $this->requestParams['lan']);

		array_push ($q, ' ORDER BY ranges.[id], ranges.[ndx]');

		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['id' => $r['id'], 'fullName' => $r['fullName'], 'range' => $r['range'], 'dhcpServerId' => $r['dhcpServerId']];

			if ($r['vlanId'])
				$item['vlan'] = ['text' => strval($r['vlanNum']), 'suffix' => $r['vlanName']];

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		$this->data['header'] = ['#' => '#', 'range' => 'Rozsah', 'id' => 'id', 'fullName' => 'Název', 'dhcpServerId' => 'Server ID', 'vlan' => 'VLAN'];
		$this->data['table'] = $t;
	}
}

