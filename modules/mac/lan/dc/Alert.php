<?php

namespace mac\lan\dc;

use e10\utils, e10\json;


/**
 * Class DocumentCardMac
 * @package mac\lan\dc
 */
class Alert extends \e10\DocumentCard
{
	public function createContentBodyDevices ()
	{
		$q = [];
		array_push($q, 'SELECT [ad].*,');
		array_push($q, ' devices.fullName AS [deviceFullName], devices.id AS [deviceId], devices.deviceKind AS [deviceKind]');
		array_push($q, ' FROM [mac_lan_alertsDevices] AS [ad]');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS [devices] ON ad.device = devices.ndx');
		array_push($q, ' WHERE [ad].[alert] = %i', $this->recData['ndx']);

		$t = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'device' => $r['deviceFullName'],
			];
			$t[] = $item;
		}

		$h = ['#' => '#', 'device' => 'Zařízení'];
		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $t, 'header' => $h]);
	}

	public function createContentBody ()
	{

		$this->createContentBodyDevices();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}

}

