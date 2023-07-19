<?php

namespace mac\lan;

use \Shipard\Viewer\TableViewGrid;
use \Shipard\Viewer\TableViewDetail;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Utils\Utils;
use \Shipard\Viewer\TableViewPanel;

/**
 * class TableWifiClients
 */
class TableWifiClients extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.wifiClients', 'mac_lan_wifiClients', 'WiFi klienti');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['mac']];

		return $h;
	}
}


/**
 * class ViewWifiClients
 */
class ViewWifiClients extends TableViewGrid
{
	var $macs = [];
	var $macDevicesLabels = [];
  var $now;
	var $ssids;
	//var $aps;

	public function init ()
	{
		$this->ssids = [];
		$rows = $this->db()->query ('SELECT * FROM mac_lan_wlans WHERE docStateMain != 4');
		foreach ($rows as $r)
			$this->ssids[$r['ndx']] = ['id' => $r['ssid'], 'fn' => $r['fullName']];

		parent::init();

    $this->enableDetailSearch = TRUE;
    $this->type = 'form';

    $this->fullWidthToolbar = TRUE;
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

    $this->objectSubType = self::vsMain;
    $this->linesWidth = 80;

		$g = [
			'mac' => 'MAC',
      'hostName' => 'Jméno',
      'ap' => 'AP',
      'ssid' => 'SSID',
      'rssi' => ' rssi',
      'cch' => 'Kanál',
      'rxtx' => 'RX / TX',
		];

    $this->setGrid ($g);

    $this->setPanels (self::sptQuery);

    $this->now = new \DateTime();
	}

	public function renderRow ($item)
	{
		$this->macs[] = $item['mac'];

		$listItem ['pk'] = $item ['ndx'];

		$listItem ['mac'] = [['text' => $item ['mac'], 'class' => 'block']];

    if ($item['inactive'])
    {
      $listItem ['mac'][] = ['text' => Utils::dateDiffShort($item['updated'], $this->now), 'prefix' => 'off:', 'class' => 'e10-small'];
    }

    if ($item['uptime'])
      $listItem ['mac'][] = ['text' => Utils::secondsToTime($item['uptime']), 'prefix' => 'up:', 'class' => 'e10-small'];

		if ($item['aip'] !== '')
			$listItem ['mac'][] = ['text' => $item['aip'], 'prefix' => 'ip:', 'class' => 'e10-small'];

    $listItem ['hostName'] = $item ['hostName'];
    $listItem ['rssi'] = $item ['rssi'];
    $listItem ['ssid'] = $item ['ssid'];
    $listItem ['ap'] = $item ['apId'];
    $listItem ['cch'] = [['text' => $item ['cch'], 'class' => 'block']];//$item ['cch'];
		$listItem ['cch'][] = ['text' => $item ['rxRate'], 'prefix' => 'rx:', 'class' => 'e10-small'];
		$listItem ['cch'][] = ['text' => $item ['txRate'], 'prefix' => 'tx:', 'class' => 'e10-small'];

    $listItem ['rxtx'] = [
      ['text' => Utils::memf($item ['rxBytes']), 'prefix' => 'rx:', 'class' => 'e10-small block'],
      ['text' => Utils::memf($item ['txBytes']), 'prefix' => 'tx:', 'class' => 'e10-small block'],
    ];

		$listItem ['icon'] = $this->table->tableIcon ($item);

    if ($item['inactive'])
      $listItem ['class'] = 'e10-off';

		return $listItem;
	}

	function decorateRow (&$item)
	{
    /*
		if (isset ($this->macDevicesLabels [$item ['mac']]))
		{
			if (count($this->macDevicesLabels [$item ['mac']]) === 1)
			{
				$item['icon'] = $this->macDevicesLabels [$item ['mac']][0]['icon'];
				unset($this->macDevicesLabels [$item ['mac']][0]['icon']);
			}
			$item ['t2'] = $this->macDevicesLabels [$item ['mac']];
		}
    */
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [wc].* ';
		array_push($q, ' FROM [mac_lan_wifiClients] AS [wc]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, 'wc.[mac] LIKE %s', '%' . $fts . '%');
      array_push($q, 'OR wc.[hostName] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

		// -- special queries
		$qv = $this->queryValues ();

		if (isset ($qv['ssids']))
		{
			$ssids = [];
			foreach ($qv['ssids'] as $ssidNdx => $nonValue)
			{
				if (isset($this->ssids[$ssidNdx]))
					$ssids[] = $this->ssids[$ssidNdx]['id'];
			}

			if (count($ssids))
				array_push ($q, " AND wc.[ssid] IN %in", $ssids);
		}

		array_push($q, ' ORDER BY [inactive], [mac], [ndx]');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- ssids
		$ssids = [];
		foreach ($this->ssids as $ssidNdx => $ssid)
		{
			$ssids[$ssidNdx] = $ssid['id'];
			if ($ssid['fn'] !== $ssid['id'])
				$ssids[$ssidNdx] .= ' ('.$ssid['fn'].')';
		}
		$this->qryPanelAddCheckBoxes($panel, $qry, $ssids, 'ssids', 'SSID');

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * class FormWifiClient
 */
class FormWifiClient extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->readOnly = TRUE;

		$this->openForm ();
			$this->addColumnInput ('mac');
			//$this->addColumnInput ('ports');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailWifiClient
 */
class ViewDetailWifiClient extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('mac.lan.dc.DocumentCardMac');
	}

	public function createToolbar ()
	{
		return [];
	}
}

