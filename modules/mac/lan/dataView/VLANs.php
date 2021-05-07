<?php

namespace mac\lan\dataView;

use \lib\dataView\DataView;


/**
 * Class VLANs
 * @package e10pro\lan\dataView
 */
class VLANs extends DataView
{
	protected function init()
	{
		$this->checkRequestParamsList('lan');

		parent::init();
	}

	protected function loadData()
	{
		$q [] = 'SELECT vlans.*, lans.shortName as lanShortName';
		array_push ($q, ' FROM [mac_lan_vlans] AS vlans');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON vlans.lan = lans.ndx');
		array_push ($q, ' WHERE 1');

		if (isset($this->requestParams['lan']))
			array_push ($q, ' AND vlans.[lan] IN %in', $this->requestParams['lan']);

		array_push ($q, ' ORDER BY vlans.[num], vlans.[ndx]');

		$t = [];
		$pks = [];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['id' => $r['id'], 'fullName' => $r['fullName'], 'num' => $r['num']];

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		$this->loadRanges($pks, $t);

		$this->data['header'] = ['#' => '#', 'num' => ' VLAN', 'fullName' => 'Název', 'id' => 'id', 'ranges' => 'Rozsahy IP adres'];
		$this->data['table'] = $t;
	}

	public function loadRanges ($pks, &$data)
	{
		if (!count($pks))
			return;

		$q [] = 'SELECT ranges.*';
		array_push ($q, ' FROM [mac_lan_lansAddrRanges] AS ranges');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ranges.[vlan] IN %in', $pks);
		array_push ($q, ' ORDER BY ranges.[id], ranges.[ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['text' => $r['range'], 'class' => 'block'];
			$data[$r['vlan']]['ranges'][] = $item;
		}
	}
}

