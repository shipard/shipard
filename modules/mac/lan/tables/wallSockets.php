<?php

namespace mac\lan;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableWallSockets
 * @package mac\lan
 */
class TableWallSockets extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.wallSockets', 'mac_lan_wallSockets', 'Zásuvky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		$recData['idOrder'] = preg_replace_callback ('/(\\d+)/', function($match){return (($match[0] + 10000));}, $recData['id']);
	}
}


/**
 * Class ViewWallSockets
 * @package mac\lan
 */
class ViewWallSockets extends TableView
{
	var $connectedTo = [];

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		if ($item['locationType'] === 1)
			$listItem ['icon'] = 'iconLeftSocket';
		elseif ($item['locationType'] === 2)
			$listItem ['icon'] = 'iconRightSocket';
		else
			$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['id'];

		if ($item['placeFullName'])
		{
			$listItem['i1'] = ['icon' => 'system/iconMapMarker', 'text' => $item['placeFullName'], 'class' => 'id'];
			if ($item['placeDesc'] !== '')
				$listItem['i1']['suffix'] = $item['placeDesc'];
		}
		elseif ($item['placeDesc'] !== '')
			$listItem['i1'] = ['icon' => 'system/iconMapMarker', 'text' => $item['placeDesc'], 'class' => 'id'];
		else
			$listItem['i1'] = ['icon' => 'system/iconMapMarker', 'text' => '---', 'class' => 'id e10-error'];

		$listItem ['i2'] = [];

		if ($item['rackName'])
			$listItem ['i2'][] = ['text' => $item['rackName'], 'icon' => 'tables/mac.lan.racks', 'class' => ''];
		else
			$listItem ['i2'][] = ['text' => '!!!', 'icon' => 'tables/mac.lan.racks', 'class' => 'e10-error'];

		if ($item['lanShortName'])
			$listItem ['i2'][] = ['text' => $item['lanShortName'], 'icon' => 'system/iconSitemap', 'class' => ''];
		else
			$listItem ['i2'][] = ['text' => '!!!', 'icon' => 'system/iconSitemap', 'class' => 'e10-error'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$pk = $item['pk'];
		if (isset($this->connectedTo[$pk]))
		{
			$item['t2'] = [];
			$item['t3'] = [];
			$first = 1;
			foreach ($this->connectedTo[$pk] as $ci)
			{
				if ($first)
				{
					$item['t2'][] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => ''];
					$item['t2'] = array_merge($item['t2'], $ci);
				}
				else
				{
					$item['t3'][] = ['text' => '', 'icon' => 'icon-level-up fa-rotate-90 fa-fw', 'class' => ''];
					$item['t3'] = array_merge($item['t3'], $ci);
				}
				$first = 0;
			}
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ws.*, places.fullName AS placeFullName, lans.shortName AS lanShortName, racks.fullName AS rackName';
		array_push ($q, ' FROM [mac_lan_wallSockets] AS ws');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON ws.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON ws.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_racks AS racks ON ws.rack = racks.ndx');
		array_push ($q, ' WHERE 1');
		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (', 'ws.[id] LIKE %s', '%'.$fts.'%', ')');
		}

		// -- aktuální
		$this->queryMain ($q, 'ws.', ['ws.[idOrder]', 'ws.[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');


		$q[] = 'SELECT ports.*, ';
		array_push($q, ' devices.id AS deviceId, devices.fullName AS deviceName, devices.deviceKind AS deviceKind');
		array_push($q, ' FROM [mac_lan_devicesPorts] AS ports');
		array_push($q, ' LEFT JOIN [mac_lan_devices] AS devices ON ports.device = devices.ndx');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND ports.connectedToWallSocket IN %in', $this->pks);

		array_push($q, ' ORDER BY devices.deviceKind DESC');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['deviceName'])
			{
				$dstItem = [];
				$dstItem[] = [
					'suffix' => $r['deviceId'], 'text' => $r['deviceName'],
					'icon' => $devicesKinds[$r['deviceKind']]['icon'], 'class' => ''
				];

				$dstItem[] = [
					'text' => $r['portId'], 'suffix' => '#' . $r['portNumber'],
					'icon' => 'iconArrowAltRightCircle', 'class' => ''
				];

				$this->connectedTo[$r['connectedToWallSocket']][] = $dstItem;
			}
		}
	}
}


/**
 * Class ViewDetailWallSocket
 * @package mac\lan
 */
class ViewDetailWallSocket extends TableViewDetail
{
}


/**
 * Class FormWallSocket
 * @package mac\lan
 */
class FormWallSocket extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();

			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('id');
					$this->addColumnInput ('locationType');
					$this->addColumnInput ('place');
					$this->addColumnInput ('placeDesc');
					$this->addColumnInput ('rack');
					$this->addColumnInput ('lan');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}
