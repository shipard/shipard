<?php

namespace mac\lan;


use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \E10\DbTable, \e10\utils;
use \e10\base\libs\UtilsBase;

/**
 * Class TableSwLicenses
 * @package mac\lan
 */
class TableSwLicenses extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.swLicenses', 'mac_lan_swLicenses', 'Licence');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if (isset($recData['id']) && $recData['id'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['id'] = strval ($recData['ndx']);
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = array();

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewSwLicenses
 * @package mac\lan
 */
class ViewSwLicenses extends TableView
{
	var $classification;

	public function init ()
	{
		$this->setMainQueries ();
		parent::init();
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		$props = [];
		$props[] = ['text' => $item['id'], 'class' => 'label label-default'];
		if ($item['appName'])
			$props[] = ['text' => $item['appName'], 'icon' => 'icon-hourglass-o', 'class' => ''];

		if ($item['maxUsers'] !== 0)
			$props[] = ['text' => utils::nf($item['maxUsers']), 'icon' => 'system/iconUser', 'class' => 'pull-right'];
		if ($item['maxDevices'] !== 0)
			$props[] = ['text' => utils::nf($item['maxDevices']), 'icon' => 'deviceTypes/workStation', 'class' => 'pull-right'];

		$listItem ['t2'] = $props;

		$props = [];
		if ($item['licenseNumber'] !== '')
			$props[] = ['text' => $item['licenseNumber'], 'prefix' => '#'];
		else
			$props[] = ['text' => ' '];
		$listItem ['t3'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			if (!isset($item ['t3']))
				$item ['t3'] = [];

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);

		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT licenses.*, apps.shortName as appName FROM [mac_lan_swLicenses] AS licenses ';
		array_push ($q, ' LEFT JOIN [mac_lan_swApplications] as apps ON licenses.application = apps.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' licenses.[fullName] LIKE %s ', '%'.$fts.'%');
			array_push($q, ' OR licenses.[shortName] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR licenses.[licenseNumber] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR licenses.[invoiceNumber] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR licenses.[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ')');
		}
		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE licenses.ndx = recid AND tableId = %s', 'mac.lan.swLicenses');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}
		if (isset($qv['apps']))
		{
			array_push ($q, ' AND [application] IN %in', array_keys($qv['apps']));
		}

		$this->queryMain ($q, 'licenses.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks, 'label label-info pull-right');
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		// -- sw applications
		$qapps[] = 'SELECT apps.ndx as appNdx, apps.shortName as appName FROM mac_lan_swLicenses as licenses';
		array_push ($qapps, ' LEFT JOIN mac_lan_swApplications AS apps ON licenses.application = apps.ndx');
		array_push ($qapps, 'GROUP BY apps.ndx ORDER BY apps.shortName');
		$apps = $this->db()->query ($qapps)->fetchPairs ('appNdx', 'appName');
		$this->qryPanelAddCheckBoxes($panel, $qry, $apps, 'apps', 'Aplikace');

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormSwLicense
 * @package mac\lan
 */
class FormSwLicense extends TableForm
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
			$this->addColumnInput ('application');
			$this->addColumnInput ('id');

			$this->addColumnInput ('maxDevices');
			$this->addColumnInput ('maxUsers');

			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');

			$this->addColumnInput ('invoiceNumber');
			$this->addColumnInput ('licenseNumber');

			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
			$this->addList ('clsf', '', TableForm::loAddToFormLayout);
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addAttachmentsViewer();
		$this->closeTab ();

		$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSwLicense
 * @package mac\lan
 */
class ViewDetailSwLicense extends TableViewDetail
{
	public function createDetailContent ()
	{
		$card = new \mac\lan\DocumentCardSwLicense ($this->app());
		$card->setDocument($this->table(), $this->item);
		$card->createContent();
		foreach ($card->content['body'] as $cp)
			$this->addContent($cp);
	}
}

