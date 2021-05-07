<?php

namespace mac\lan;
use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils;


/**
 * Class TableDevicesIfaces
 * @package mac\lan
 */
class TableDevicesIfaces extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.devicesIfaces', 'mac_lan_devicesIfaces', 'Síťová rozhraní zařízení');
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', \E10\TableForm $form = NULL)
	{
		if ($columnId === 'devicePort')
		{
			if (!$form)
				return [];
			$ownerRecData = $form->option ('ownerRecData');
			if (!$ownerRecData && !isset($form->recData['device']))
				return [];

			$q[] = 'SELECT * FROM [mac_lan_devicesPorts]';
			if (isset($form->recData['device']))
				array_push ($q, ' WHERE [device] = %i', $form->recData['device']);
			else
				array_push ($q, ' WHERE [device] = %i', $ownerRecData['ndx']);
			array_push ($q, ' ORDER BY [portId], [ndx]');
			$rows = $this->db()->query($q);

			$enum = [];
			foreach ($rows as $r)
			{
				$enum[$r['ndx']] = $r['portId'];
			}

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$portRecData = $this->app()->loadItem($recData['devicePort'], 'mac.lan.devicesPorts');
		$portKind = $this->app()->cfgItem ('mac.lan.ports.kinds.'.$portRecData['portKind'], []);
		$portRole = $this->app()->cfgItem ('mac.lan.ports.roles.'.$portRecData['portRole'], []);

		// -- compose IP address
		if (isset($portRole['useManualAddr']))
		{
			$recData['range'] = 0;
			if ($recData['addrType'] == 1)
				$recData['addrType'] = 0;

			if ($recData['addrType'] == 2)
			{ // DHCP client
				$recData['ip'] = '';
				$recData['addressGateway'] = '';
			}
		}
		else
		{
			$addrRange = $this->app()->cfgItem('mac.lan.addrRanges.' . $recData['range'], FALSE);
			if ($addrRange)
				$recData['ip'] = $addrRange['ap'] . $recData['ipAddressSuffix'];
		}

		// -- get mac prom port
		if ($recData['devicePort'])
		{
			$devicePort = $this->db()->query ('SELECT * FROM [mac_lan_devicesPorts] WHERE [ndx] = %i', $recData['devicePort'])->fetch();
			if ($devicePort)
				$recData['mac'] = $devicePort['mac'];
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}
}


/**
 * Class ViewDevicesIfacesFormList
 * @package mac\lan
 */
class ViewDevicesIfacesFormList extends \e10\TableViewGrid
{
	var $device = 0;
	var $addrTypes;
	var $portRoles;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$g = [
			'port' => 'Port',
			'address' => 'Adresa',
			'info' => 'Rozsah / VLAN',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);

		$this->device = intval($this->queryParam('device'));
		$this->addAddParam ('device', $this->device);

		$this->addrTypes = $this->app()->cfgItem('mac.lan.ifacesAddrTypes');
		$this->portRoles = $this->app()->cfgItem('mac.lan.ports.roles');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$at = $this->addrTypes[$item['addrType']];
		$portRole = $this->portRoles[$item['portRole']];

		if ($item['addrType'] === 2)
			$listItem ['address'] = [['text' => 'DHCP klient', 'prefix' => $at['sc'], 'class' => 'e10-bold']];
		else
			$listItem ['address'] = [['text' => $item['ip'], 'prefix' => $at['sc'], 'class' => 'e10-bold']];
		if ($item['portMac'])
			$listItem ['address'][] = ['text' => $item['portMac'], 'class' => 'e10-small break'];
		if (isset($portRole['useGateway']))
		{
			$l = ['text' => $item['addressGateway'], 'prefix' => 'gw', 'class' => 'break e10-small'];
			if ($item['priority'])
				$l['suffix'] = '#' . $item['priority'];
			$listItem ['address'][] = $l;
		}

		$listItem ['port'] = ['text' => $item['portId'], 'class' => 'e10-bold'];

		$listItem ['info'] = [];
		if ($item['rangeName'])
			$listItem ['info'][] = ['text' => $item['rangeName'], 'icon' => 'icon-arrows-h', 'class' => 'block'];
		if ($item['vlanId'])
			$listItem ['info'][] = ['text' => $item['vlanId'], 'suffix' => $item['vlanNum'], 'icon' => 'icon-square-o', 'class' => 'block'];


		$listItem ['note'] = $item['note'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ifaces.*,';
		array_push ($q, ' ports.portKind as portKind, ports.portRole as portRole, ports.portId, ports.mac as portMac,');
		array_push ($q, ' ranges.shortName AS rangeName,');
		array_push ($q, ' vlans.id AS vlanId, vlans.num AS vlanNum');
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS ports ON ifaces.devicePort = ports.ndx ');
		array_push ($q, ' LEFT JOIN [mac_lan_lansAddrRanges] AS ranges ON ifaces.range = ranges.ndx ');
		array_push ($q, ' LEFT JOIN mac_lan_vlans AS vlans ON ranges.vlan = vlans.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ifaces.[device] = %i', $this->device);
		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (', '[ip] LIKE %s', '%'.$fts.'%', ')');
		}

		array_push ($q, ' ORDER BY ifaces.[rowOrder], ifaces.[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


class ViewDevicesIfacesFormListDetail extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'iface #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormDeviceIface
 * @package mac\lan
 */
class FormDeviceIface extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);

		$portRecData = $this->app()->loadItem($this->recData['devicePort'], 'mac.lan.devicesPorts');
		$portKind = $this->app()->cfgItem ('mac.lan.ports.kinds.'.$portRecData['portKind'], []);
		$portRole = $this->app()->cfgItem ('mac.lan.ports.roles.'.$portRecData['portRole'], []);


		$this->openForm ();
			$this->addColumnInput ('devicePort');
			$this->addColumnInput ('addrType');
			if (isset($portRole['useManualAddr']))
			{
				if ($this->recData['addrType'] == 0)
					$this->addColumnInput('ip');
			}
			else
			{
				$this->addColumnInput('range');
				$this->addColumnInput('ipAddressSuffix');
			}
			if (isset($portRole['useGateway']))
			{
				if ($this->recData['addrType'] == 0)
					$this->addColumnInput('addressGateway');
				$this->addColumnInput('priority');
			}
			$this->addColumnInput ('id');
			$this->addColumnInput ('note');
			$this->addColumnInput ('dnsname');
			//$this->addColumnInput ('mac', TableForm::coColW3|TableForm::coReadOnly);
			//$this->addColumnInput ('ip', TableForm::coColW2|TableForm::coReadOnly);
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
	{
		switch ($colDef ['sql'])
		{
			case	'ipAddressSuffix':
			{
				$addrRange = $this->app()->cfgItem ('mac.lan.addrRanges.'.$this->recData['range'], FALSE);
				if ($addrRange)
					return $addrRange['ap'];
			}
		}
		return parent::columnLabel ($colDef, $options);
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'mac.lan.devicesIfaces' && $srcColumnId === 'ipAddressSuffix')
		{
			$cp = [
				'addrRange' => $recData['range'],
				'thisSuffix' => $recData['ipAddressSuffix'],
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

}
