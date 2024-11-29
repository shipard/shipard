<?php

namespace mac\lan;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Report\FormReport;
use \Shipard\Utils\Utils;


/**
 * class TableWlans
 */
class TableWlans extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.wlans', 'mac_lan_wlans', 'WiFi sítě');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['wpaPassphrase']) || $recData['wpaPassphrase'] === '')
			$recData['wpaPassphrase'] = Utils::createToken(16, TRUE);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (!isset($recData['wpaPassphrase']) || $recData['wpaPassphrase'] === '')
			$recData['wpaPassphrase'] = Utils::createToken(16, TRUE, TRUE);
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
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewWlans
 */
class ViewWlans extends TableView
{
	/** @var \mac\lan\TableLans */
	var $tableLans;

	public function init ()
	{
		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableLans->setViewerBottomTabs($this);

		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['i1'] = ['text' => $item['id']];

		$listItem ['t1'] = $item['fullName'];
		if ($item['lanShortName'])
			$listItem ['i2'] = ['text' => $item['lanShortName'], 'icon' => 'system/iconSitemap'];
		else
			$listItem ['i2'] = ['text' => '!!!', 'icon' => 'system/iconSitemap'];

		$listItem ['t2'] = [['text' => $item['ssid'], 'icon' => 'tables/mac.lan.wlans', 'class' => '']];

		if ($item['vlan'])
			$listItem ['t2'][] = ['text' => $item['vlanName'], 'suffix' => Utils::nf($item['vlanNum']), 'icon' => 'tables/mac.lan.vlans', 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT wlans.*, lans.shortName as lanShortName,');
		array_push ($q, ' [vlans].fullName AS vlanName, [vlans].[num] AS [vlanNum]');
		array_push ($q, ' FROM [mac_lan_wlans] AS wlans');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON wlans.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_vlans AS vlans ON wlans.vlan = vlans.ndx');
		array_push ($q, ' WHERE 1');

		$lan = intval($this->bottomTabId());
		if ($lan)
			array_push($q,' AND [wlans].[lan] = %i', $lan);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (',
				'wlans.[id] LIKE %s', '%'.$fts.'%',
				' OR wlans.[fullName] LIKE %s', '%'.$fts.'%',
				' OR wlans.[ssid] LIKE %s', '%'.$fts.'%',
				')');
		}

		$this->queryMain ($q, 'wlans.', ['[id]', '[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailWlan
 */
class ViewDetailWlan extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.Wlan');
	}
}


/**
 * class FormWlan
 */
class FormWlan extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs,TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('ssid');
					$this->addColumnInput ('wpaPassphrase');
					$this->addColumnInput ('lan');
					$this->addColumnInput ('vlan');
					$this->addColumnInput ('onAPs');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ReportWlanSticker
 */
class ReportWlanSticker extends FormReport
{
	function init ()
	{
		$this->reportId = 'reports.default.mac.lan.wlanSticker';
		$this->reportTemplate = 'reports.default.mac.lan.wlanSticker';
	}

	public function loadData ()
	{
		parent::loadData();

		$s = ':;"';
		$this->data['qrCodeData'] = 'WIFI:S:'.addcslashes($this->recData['ssid'], $s).';T:WPA;P:'.addcslashes($this->recData['wpaPassphrase'], $s).';;';
	}
}
