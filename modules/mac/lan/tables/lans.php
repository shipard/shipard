<?php

namespace mac\lan;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableLans
 * @package mac\lan
 */
class TableLans extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("mac.lan.lans", "mac_lan_lans", "Počítačové sítě");
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['docStateMain'] > 1)
		{
			$une = new \mac\lan\libs\NodeServerCfgUpdater($this->app());
			$une->init();
			$une->update();
		}

		parent::checkAfterSave2($recData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = array();

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		if ($recData ['owner'])
		{
			$owner = $this->loadItem($recData ['owner'], 'e10_persons_persons');
			$hdr ['info'][] = ['class' => 'normal', 'value' => $owner ['fullName']];
		}
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function upload ()
	{
		$reUploadFrom = $this->app()->testGetParam('reupload-from');

		$test = $this->app()->requestPath(2);
		if ($test === 'ping')
		{
			$deviceUID = $this->app()->requestPath(3);
			/** @var \mac\lan\TableWatchdogs $tableWatchdogs */
			$tableWatchdogs = $this->app()->table('mac.lan.watchdogs');
			if ($tableWatchdogs->pingFromDevice('ping-pong', $deviceUID, ''))
				return 'OK';

			return 'FAIL';
		}

		$uploadString = $this->app()->postData();

		if (substr($uploadString, 0, 16) === ';;;shipard-agent')
		{
			$iqp = new \mac\swlan\libs\InfoQueueParser($this->app());
			$iqp->reUploadFrom = $reUploadFrom;
			$iqp->setSrcText($uploadString);
			$iqp->parse();
			$iqp->saveAll();

			if ($reUploadFrom === '')
				$this->reUpload($uploadString);

			return 'OK';
		}

		if ($reUploadFrom !== '')
		{
			return 'OK';
		}

		$uploadData = json_decode($uploadString, TRUE);
		if ($uploadData === FALSE)
		{
			error_log ("mac.lan.lans::upload parse data error: ".json_encode($uploadString));
			return 'FALSE';
		}

		$udr = new \mac\lan\UploadDataReceiver($this->app());
		$udr->setData($uploadData);
		$res = $udr->run();

		if ($reUploadFrom === '')
			$this->reUpload($uploadString);

		return $res;
	}

	function reUpload($uploadString)
	{
		$reUploadTo = $this->app()->cfgItem('mac.lan.reupload.to', NULL);

		if (!$reUploadTo)
			return;

		foreach ($reUploadTo as $ru)
		{
			$url = $ru['url'];
			$url .= $this->app()->requestPath().'?reupload-from='.$ru['from'];

			utils::http_post($url, $uploadString);
		}
	}

	public function setViewerBottomTabs($viewer)
	{
		$rows = $this->app()->db->query ('SELECT * FROM [mac_lan_lans] WHERE [docState] != 9800 ORDER BY [order], [fullName]');
		$bt = [];
		$active = 1;
		foreach ($rows as $r)
		{
			$addParams = ['lan' => $r['ndx']];
			$bt [] = ['id' => $r['ndx'], 'title' => $r['shortName'], 'active' => $active, 'addParams' => $addParams];
			$active = 0;
		}
		$bt[] = ['id' => '0', 'title' => 'Vše', 'active' => 0];

		if (count($bt) > 2)
			$viewer->setBottomTabs($bt);
		else
			$viewer->addAddParam ('lan', $bt[0]['addParams']['lan']);
	}
}


/**
 * Class ViewLans
 * @package mac\lan
 */
class ViewLans extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		$props = [];
		if ($item['routerId'])
			$props[] = ['text' => $item['routerId'], 'suffix' => $item['routerName'], 'icon' => 'deviceTypes/router', 'class' => 'label label-default'];

		if ($item['ipv6Enabled'])
			$props[] = ['text' => 'ipv6', 'icon' => 'system/iconCheck', 'class' => 'label label-success'];

		if (count($props))
			$listItem ['t2'] = $props;
		else
			$listItem ['t2'] = '-';

		if ($item['ownerFullName'])
			$listItem ['t3'] = $item['ownerFullName'];

		$listItem ['i1'] = ['text' => $item['shortName'], 'class' => 'id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT lans.*, persons.fullName as ownerFullName, routers.fullName AS routerName, routers.id AS routerId';
		array_push ($q, ' FROM [mac_lan_lans] AS lans');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON lans.owner = persons.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS routers ON lans.mainRouter = routers.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND (lans.[fullName] LIKE %s', '%'.$fts.'%',
						'OR lans.[shortName] LIKE %s', '%'.$fts.'%',
						'OR persons.[fullName] LIKE %s', '%'.$fts.'%',
			')');

		// -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND lans.[docStateMain] < 4");

		// koš
		if ($mainQuery == 'trash')
			array_push ($q, " AND lans.[docStateMain] = 4");

		array_push ($q, ' ORDER BY [fullName] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormLan
 * @package mac\lan
 */
class FormLan extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$useDocumentation = 	intval($this->app()->cfgItem ('options.macLAN.useDocumentation', 0));

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		if ($useDocumentation)
			$tabs ['tabs'][] = ['text' => 'Dokumen-tace', 'icon' => 'formWiki'];
		$tabs ['tabs'][] = ['text' => 'Nálepka racku', 'icon' => 'formRackSticker'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openTabs ($tabs);
			$this->openTab ();
				$this->addColumnInput ('fullName');
				$this->addColumnInput ('shortName');

				$this->addSeparator(self::coH2);
				$this->addColumnInput ('domain');
				$this->addColumnInput ('mainServerLanControl');
				$this->addColumnInput ('mainServerCameras');
				$this->addColumnInput ('mainServerIoT');
				$this->addColumnInput ('mainRouter');
				$this->addColumnInput ('robotUser');

				$this->addColumnInput ('lcUserMikrotik');

				$this->addSeparator(self::coH2);
				$this->addColumnInput ('vlanManagement');
				$this->addColumnInput ('vlanAdmins');
				$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->addColumnInput ('ipv6Enabled');

				$this->addSeparator(self::coH2);
				$this->addColumnInput ('defaultMacDataSource');
				$this->addColumnInput ('lanMonDashboardsUrl');
				$this->addColumnInput ('iotStoreDataSource');

				$this->addSeparator(self::coH2);
				$this->addColumnInput ('owner');

				$this->addSeparator(self::coH2);
				$this->addColumnInput ('order');

				$this->addSeparator(self::coH2);
				$this->addColumnInput ('alertsDeliveryTarget');
			$this->closeTab ();

			if ($useDocumentation)
			{
				$this->openTab();
					$this->addColumnInput('wiki');
					$this->addColumnInput('wikiSection');
				$this->closeTab();
			}

			$this->openTab (TableForm::ltNone);
				$this->addInputMemo('rackLabelText', NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();
		$this->closeTabs ();
		$this->closeForm ();
	}
} // class FormLan


/**
 * Class ViewDetailLanPreview
 * @package mac\lan
 */
class ViewDetailLanPreview extends TableViewDetail
{
}

