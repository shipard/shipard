<?php

namespace terminals\base;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableServers
 * @package terminals\base
 */
class TableServers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('terminals.base.servers', 'terminals_base_servers', 'Místní servery');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$servers = [];
		$rows = $this->app()->db->query ('SELECT * FROM [terminals_base_servers] WHERE [docState] != 9800 ORDER BY [id], [name], [ndx]');

		foreach ($rows as $r)
		{
			$s = [
				'ndx' => $r['ndx'], 'id' => $r['ndx'], 'name' => $r ['name'],
				'wsUrl' => '', 'postUrl' => '',
				'allowedFrom' => [], 'cameras' => [], 'sensors' => []
			];

			if ($r['serverFQDN'] !== '')
			{
				$s['wsUrl'] = 'wss://' . $r['serverFQDN'] . ':' . (($r['serverPort']) ? $r['serverPort'] : 8888) . '/realtime/';
				$s['postUrl'] = 'https://' . $r['serverFQDN'] . ':' . (($r['serverPort']) ? $r['serverPort'] : 8888) . '/';
			}
			$af = explode(' ', $r['allowedFrom']);
			foreach ($af as $afIP)
				$s['allowedFrom'][] = trim($afIP);

			if ($r['camerasURL'] === '')
				$s['camerasURL'] = 'https://'.$r['serverFQDN'].'/';
			else
				$s['camerasURL'] = $r['camerasURL'];

			// -- cameras
			$camerasRows = $this->app()->db->query ('SELECT * from [terminals_base_cameras] WHERE [docState] != 9800 AND localServer = %i',
					$r['ndx'], 'ORDER BY [id], [name], [ndx]');
			foreach ($camerasRows as $cam)
			{
				$s['cameras'][$cam['ndx']] = ['ndx' => $cam ['ndx'], 'id' => $cam ['ndx'], 'name' => $cam ['name'], 'uiPlace' =>  'both', 'sensors' => []];

				// -- sensors
				$sensorsRows = $this->app()->db->query (
						'SELECT doclinks.dstRecId FROM [e10_base_doclinks] as doclinks',
						' WHERE doclinks.linkId = %s', 'terminals-base-cameras-sensors',
						' AND doclinks.srcRecId = %i', $cam['ndx']
				);
				foreach ($sensorsRows as $sensor)
					$s['cameras'][$cam['ndx']]['sensors'][] = $sensor['dstRecId'];
			}

			// -- sensors
			$sensorsRows = $this->app()->db->query ('SELECT * from [terminals_base_sensors] WHERE [docState] != 9800 AND localServer = %i',
					$r['ndx'], 'ORDER BY [id], [name], [ndx]');
			foreach ($sensorsRows as $sensor)
			{
				$s['sensors'][$sensor['ndx']] = [
						'ndx' => $sensor ['ndx'], 'id' => $sensor ['ndx'], 'name' => $sensor ['name'], 'class' => $sensor['sensorClass'],
						'icon' => $sensor ['icon'], 'allwaysOn' => ($sensor ['manual']) ? 0 : 1, 'devices' => []
				];
				// -- sensor devices
				$devicesRows = $this->app()->db->query (
						'SELECT doclinks.dstRecId, devices.id as deviceId, devices.name as deviceName FROM [e10_base_doclinks] as doclinks',
						' LEFT JOIN e10_base_devices AS devices ON doclinks.dstRecId = devices.ndx',
						' WHERE doclinks.linkId = %s', 'terminals-base-sensors-devices',
						' AND doclinks.srcRecId = %i', $sensor['ndx']
				);
				foreach ($devicesRows as $device)
				{
					$s['sensors'][$sensor['ndx']]['devices'][] = $device['deviceId'];
				}
			}

			$cfgData = [];
			$this->saveConfigLan ($cfgData, $r['ndx']);
			$this->saveConfigVideo ($cfgData, $r['ndx']);

			$update = ['cfgData' => json_encode($cfgData)];
			$update['cfgDataVer'] = md5($update['cfgData']);
			$s['cfgDataVer'] = $update['cfgDataVer'];
			$this->app()->db->query ('UPDATE [terminals_base_servers] SET ', $update, ' WHERE ndx = %i', $r['ndx']);

			$servers [$r['ndx']] = $s;
		}

		// -- save to file
		$cfg ['e10']['terminals']['servers'] = $servers;
		file_put_contents(__APP_DIR__ . '/config/_terminals.servers.json', utils::json_lint (json_encode ($cfg)));
	}

	function saveConfigLan (&$cfgData, $serverNdx)
	{
		if ($this->app()->model()->table ('e10pro.lan.lansAddrRanges') === FALSE)
			return;

		$lsc = [];

		$rangesPks = [];
		$ranges = $this->db()->query('SELECT * FROM e10pro_lan_lansAddrRanges WHERE localServer = %i', $serverNdx);
		foreach ($ranges as $range)
		{
			$lsc['ranges'][$range['ndx']] = [
				'range' => $range['range'], 'note' => $range['note'],
				'dhcpPoolBegin' => $range['dhcpPoolBegin'], 'dhcpPoolEnd' => $range['dhcpPoolEnd']
			];

			$rangesPks[] = $range['ndx'];
		}

		$qa[] = 'SELECT ifaces.*, devices.ndx AS deviceNdx, devices.lan as deviceLan, devices.deviceKind as deviceKind FROM [e10pro_lan_devicesIfaces] AS ifaces';
		array_push ($qa, ' LEFT JOIN e10pro_lan_devices AS devices ON ifaces.device = devices.ndx');
		array_push ($qa, ' WHERE devices.docState IN %in', [4000, 8000], ' AND ifaces.range IN %in', $rangesPks);
		$rows = $this->db()->query($qa);
		foreach ($rows as $r)
		{
			$newItem = ['ip' => $r['ip'], 'mac' => $r['mac'], 't' => $r['addrType'], 'd' => $r['deviceNdx'], 'dk' => $r['deviceKind'], 'r' => $r['range']];
			if ($r['ip'] !== '')
				$lsc['ip'][$r['ip']] = $newItem;
			if ($r['mac'] !== '')
				$lsc['mac'][$r['mac']] = $newItem;
		}

		$cfgData['lan'] = $lsc;
	}

	function saveConfigVideo (&$cfgData, $serverNdx)
	{
		if ($this->app()->model()->table ('terminals.base.cameras') === FALSE)
			return;

		$cameras = [];
		$rows = $this->app()->db->query ('SELECT * from [terminals_base_cameras] WHERE [docState] != 9800 AND [localServer] = %i', $serverNdx, ' ORDER BY [id], [name], [ndx]');

		foreach ($rows as $r)
		{
			$cam = ['ndx' => $r['ndx'], 'id' => $r['ndx'], 'name' => $r ['name'], 'streamURL' => $r ['streamURL'], 'localServer' => $r ['localServer']];

			// -- sensors
			$sensorsRows = $this->app()->db->query (
				'SELECT doclinks.dstRecId FROM [e10_base_doclinks] as doclinks',
				' WHERE doclinks.linkId = %s', 'terminals-base-cameras-sensors',
				' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($sensorsRows as $sensor)
				$cam['sensors'][] = $sensor['dstRecId'];

			$cameras [$r['ndx']] = $cam;
		}

		if (count($cameras))
			$cfgData['video']['cameras'] = $cameras;
	}
}


/**
 * Class ViewServers
 * @package terminals\base
 */
class ViewServers extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = ['text' => $item['cfgDataVer'], 'class' => '', 'icon' => 'icon-wrench'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [terminals_base_servers]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [name] LIKE %s', '%'.$fts.'%',
					' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormServer
 * @package terminals\base
 */
class FormServer extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Konfigurace', 'icon' => 'formConfiguration'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('id');

					$this->addColumnInput ('serverFQDN');
					$this->addColumnInput ('serverPort');
					$this->addColumnInput ('allowedFrom');
					$this->addColumnInput ('camerasURL');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addColumnInput ('cfgData', TableForm::coFullSizeY|TableForm::coReadOnly);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();

		$this->closeForm ();
	}
}

