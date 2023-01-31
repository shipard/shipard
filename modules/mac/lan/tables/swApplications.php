<?php

namespace mac\lan;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \e10\base\libs\UtilsBase;

/**
 * Class TableSwApplications
 * @package mac\lan
 */
class TableSwApplications extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.swApplications', 'mac_lan_swApplications', 'Aplikace');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if (!isset($recData['id']) || $recData['id'] === '')
			$recData['id'] = md5 ($this->app()->cfgItem('dsid').'-'.$recData['fullName'].'-'.time().mt_rand(10000000, 99999999));
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = array();

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewSwInstallPackages
 * @package mac\lan
 */
class ViewSwApplications extends TableView
{
	var $licenses;
	var $appTypes;
	var $classification;
	var $appInstall = [];
	var $availableLicenses = [];

	public function init ()
	{
		$this->setMainQueries ();
		parent::init();
		$this->setPanels (TableView::sptQuery);

		$this->licenses = $this->app()->cfgItem('mac.lan.sw.applications.licenses');
		$this->appTypes = $this->app()->cfgItem('mac.lan.sw.applications.types');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		$props = [];
		$props[] = ['text' => $this->appTypes[$item['type']]['name'], 'class' => 'label label-default'];
		$props[] = ['text' => $this->licenses[$item['license']]['name'], 'class' => 'label label-default'];
		$listItem ['t2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->appInstall[$item ['pk']]))
		{
			$item ['t2'][] = ['text' => $this->appInstall[$item ['pk']], 'class' => 'label label-primary pull-right', 'icon' => 'deviceTypes/notebook'];
		}
		if (isset($this->availableLicenses[$item ['pk']]))
		{
			$item ['t2'][] = ['text' => $this->availableLicenses[$item ['pk']], 'class' => 'label label-success pull-right', 'icon' => 'icon-certificate'];
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT applications.* FROM [mac_lan_swApplications] AS applications ';

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE devices.ndx = recid AND tableId = %s', 'mac.lan.devices');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		if (isset ($qv['types']))
			array_push ($q, " AND applications.[type] IN %in", array_keys($qv['types']));
		if (isset ($qv['licenses']))
			array_push ($q, " AND applications.[license] IN %in", array_keys($qv['licenses']));


		$this->queryMain ($q, 'applications.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks, 'label label-info pull-right');

		// -- app install counters
		$q = [];
		array_push ($q, 'SELECT device, i2 ');
		array_push ($q, ' FROM mac_lan_devicesProperties AS properties');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON properties.device = devices.ndx');
		array_push ($q, ' WHERE properties.[property] = 3 AND properties.[deleted] = 0 AND i2 != 0 AND i2 IN %in ', $this->pks);
		array_push ($q, ' AND devices.[docStateMain] = %i', 2);
		array_push ($q, ' GROUP BY i2, device');
		$appInstallRows = $this->db()->query ($q);
		foreach ($appInstallRows as $r)
		{
			if (isset($this->appInstall[$r['i2']]))
				$this->appInstall[$r['i2']]++;
			else
				$this->appInstall[$r['i2']] = 1;
		}

		// -- available licenses counters
		$availableLicensesRows = $this->db()->query ('SELECT SUM(maxDevices) AS cnt, application FROM mac_lan_swLicenses WHERE application IN %in GROUP BY application', $this->pks);
		foreach ($availableLicensesRows as $r)
		{
			$this->availableLicenses[$r['application']] = $r['cnt'];
		}
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		// -- app types
		$types = [];
		foreach ($this->app()->cfgItem('mac.lan.sw.applications.types') as $ndx => $k)
			$types[$ndx] = $k['name'];
		$this->qryPanelAddCheckBoxes($panel, $qry, $types, 'types', 'Typ aplikace');

		// -- app licences
		$licenses = [];
		foreach ($this->app()->cfgItem('mac.lan.sw.applications.licenses') as $ndx => $k)
			$licenses[$ndx] = $k['name'];
		$this->qryPanelAddCheckBoxes($panel, $qry, $licenses, 'licenses', 'Licenční ujednání');

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormSwApplication
 * @package mac\lan
 */
class FormSwApplication extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openTabs ($tabs, TRUE);
		$this->openTab ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('type');
			$this->addColumnInput ('license');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addAttachmentsViewer();
		$this->closeTab ();

		$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSwApplication
 * @package mac\lan
 */
class ViewDetailSwApplication extends TableViewDetail
{
	public function createDetailContent ()
	{
		$card = new \mac\lan\DocumentCardSwApplication ($this->app());
		$card->setDocument($this->table(), $this->item);
		$card->createContent();
		foreach ($card->content['body'] as $cp)
			$this->addContent($cp);
	}
}

