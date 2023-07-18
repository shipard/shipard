<?php

namespace mac\lan;

use \Shipard\Viewer\TableViewGrid, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Utils\Utils;


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

	public function init ()
	{
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

    $listItem ['hostName'] = $item ['hostName'];
    $listItem ['rssi'] = $item ['rssi'];
    $listItem ['ssid'] = $item ['ssid'];
    $listItem ['ap'] = $item ['apId'];
    $listItem ['cch'] = $item ['cch'];

    $listItem ['rxtx'] = [
      ['text' => $item ['rxRate'], 'prefix' => 'rx:', 'class' => 'e10-small block'],
      ['text' => $item ['txRate'], 'prefix' => 'tx:', 'class' => 'e10-small block'],
    ];

		$listItem ['icon'] = $this->table->tableIcon ($item);

    if ($item['inactive'])
      $listItem ['class'] = 'e10-off';

    //$listItem['_options']['cellCss'] = ['subject' => $css];

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

		array_push($q, ' ORDER BY [inactive], [mac], [ndx]');
		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
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

