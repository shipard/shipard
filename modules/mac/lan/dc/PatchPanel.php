<?php

namespace mac\lan\dc;
include_once (__SHPD_MODULES_DIR__.'mac/lan/tables/patchPanelsPorts.php');
use \mac\lan\TablePatchPanelsPorts;


/**
 * Class PatchPanel
 * @package mac\lan\dc
 */
class PatchPanel extends \e10\DocumentCard
{
	var $devicesKinds;
	var $cableTerms;

	var $ports = [];

	protected function loadPorts()
	{
		$q [] = 'SELECT ports.*,';
		array_push ($q, ' wallSockets.id AS wallSocketId, wallSocketsPlaces.shortName AS wallSocketPlaceName,');
		array_push ($q, ' cableTermDevices.id AS cableTermDeviceId, cableTermDevices.fullName AS cableTermDeviceName, cableTermDevices.deviceKind AS cableTermDeviceKind,');
		array_push ($q, ' cableTermDevicesPorts.portNumber AS cableTermDevicePortNumber, cableTermDevicesPorts.portId AS cableTermDevicePortId,');
		array_push ($q, ' cableTermPatchPanels.id AS cableTermPatchPanelId, cableTermPatchPanels.fullName AS cableTermPatchPanelName, cableTermPatchPanels.patchPanelKind AS cableTermPatchPanelKind,');
		array_push ($q, ' cableTermPatchPanelsPorts.portNumber AS cableTermPatchPanelPortNumber, cableTermPatchPanelsPorts.portId AS cableTermPatchPanelPortId');
		array_push ($q, ' FROM [mac_lan_patchPanelsPorts] AS ports');
		array_push ($q, ' LEFT JOIN [mac_lan_wallSockets] AS wallSockets ON ports.cableTermWallSocket = wallSockets.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS wallSocketsPlaces ON wallSockets.place = wallSocketsPlaces.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS cableTermDevices ON ports.cableTermDevice = cableTermDevices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devicesPorts] AS cableTermDevicesPorts ON ports.cableTermDevicePort = cableTermDevicesPorts.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_patchPanels] AS cableTermPatchPanels ON ports.cableTermPatchPanel = cableTermPatchPanels.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_patchPanelsPorts] AS cableTermPatchPanelsPorts ON ports.cableTermPatchPanelPort = cableTermPatchPanelsPorts.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND ports.[patchPanel] = %i', $this->recData['ndx']);
		array_push ($q, ' ORDER BY ports.[portNumber]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->addPortRow($r->toArray());
		}
	}

	public function addPortRow ($item)
	{
		$listItem = [];

		$ctCfg = $this->cableTerms[$item['cableTerm']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'icon-ellipsis-h';

		$listItem ['portInfo'] = [
			['text' => $item['portId'], 'class' => 'e10-bold'],
			//['text' => $portKind['sc'], 'class' => 'break e10-small']
		];

		// -- cable termination

		$listItem ['cableTerm'] =[];
		$listItem ['cableTerm'][] = ['text' => $ctCfg['fn'], 'class' => 'e10-off block'];

		if ($item['cableTerm'] == TablePatchPanelsPorts::ctNone)
		{
		}
		elseif ($item['cableTerm'] == TablePatchPanelsPorts::ctWallSocket)
		{
			$listItem ['cableTerm'][] = ['text' => $item['wallSocketId'], 'icon' => 'icon-square-o', 'class' => 'e10-bold'];
			if ($item['wallSocketPlaceName'])
				$listItem ['cableTerm'][] = ['text' => $item['wallSocketPlaceName'], 'icon' => 'system/iconMapMarker', 'class' => ''];
		}
		elseif ($item['cableTerm'] == TablePatchPanelsPorts::ctPatchPanel)
		{
			if ($item['cableTermPatchPanelId'])
			{
				$listItem['cableTerm'][] = [
					'suffix' => $item['cableTermPatchPanelId'], 'text' => $item['cableTermPatchPanelName'],
					'icon' => 'icon-ellipsis-h', 'class' => 'e10-bold'
				];

				if ($item['cableTermPatchPanelPortNumber'])
				{
					$listItem['cableTerm'][] = [
						'text' => $item['cableTermPatchPanelPortId'], 'suffix' => '#' . $item['cableTermPatchPanelPortNumber'],
						'icon' => 'icon-arrow-circle-o-right', 'class' => ''];
				}
				else
					$listItem['cableTerm'][] = ['text' => '!!!', 'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-error'];

			}
		}
		elseif ($item['cableTerm'] == TablePatchPanelsPorts::ctLanDevice)
		{
			if ($item['cableTermDeviceId'])
			{
				$listItem['cableTerm'][] = [
					'suffix' => $item['cableTermDeviceId'], 'text' => $item['cableTermDeviceName'],
					'icon' => $this->devicesKinds[$item['cableTermDeviceKind']]['icon'], 'class' => 'e10-bold'
				];

				if ($item['cableTermDevicePortNumber'])
				{
					$listItem['cableTerm'][] = [
						'text' => $item['cableTermDevicePortId']/*, 'suffix' => '#' . $r['connectedPortNumber']*/,
						'icon' => 'icon-arrow-circle-o-right', 'class' => ''];
				}
				else
					$listItem['cableTerm'][] = ['text' => '!!!', 'icon' => 'icon-arrow-circle-o-right', 'class' => 'e10-error'];
			}
		}


		$listItem ['note'] = $item['note'];

		$this->ports[] = $listItem;
	}

	public function createContentBody ()
	{
		// -- ports
		$h = [
			'portInfo' => ' Port',
			'cableTerm' => 'Kabel zakončen v',
			'note' => 'Pozn.',
		];

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $this->ports, 'header' => $h]);
	}

	public function createContent ()
	{
		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
		$this->cableTerms = $this->app()->cfgItem('mac.lan.patchPanels.cableTerm');

		$this->loadPorts();
		$this->createContentBody ();
	}
}
