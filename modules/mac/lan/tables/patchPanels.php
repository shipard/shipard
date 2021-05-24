<?php

namespace mac\lan;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewPanel;


/**
 * Class TablePatchPanels
 * @package mac\lan
 */
class TablePatchPanels extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.patchPanels', 'mac_lan_patchPanels', 'Patch panely');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $h;
	}

	public function createPatchPanelFromKind ($data)
	{
		$newDevice = ['fullName' => $data['fullName'], 'patchPanelKind' => $data['patchPanelKind'], 'docState' => 1000, 'docStateMain' => 0];

		if (isset($data['rack']))
			$newDevice['rack'] = $data['rack'];

		$pk = $this->dbInsertRec($newDevice);

		$portNumber = 1;
		$portNumberForId = 1;
		$ppk = $this->app()->cfgItem('mac.lan.patchPanels.kinds.'.$data['patchPanelKind'], NULL);
		$portsCount = intval($ppk['portsCount']);
		for ($pn = 0; $pn < $portsCount; $pn++)
		{
			$newPort = ['patchPanel' => $pk, 'portNumber' => $portNumber, 'rowOrder' => $portNumber * 100];

			if ($ppk['id'] === 'fo24' || $ppk['id'] === 'fo10')
			{
				if ($portNumber % 2 === 1)
				{
					$newPort['portId'] = $portNumberForId.'A';
				}
				else
				{
					$newPort['portId'] = $portNumberForId.'B';
					$portNumberForId++;
				}
			}
			elseif ($ppk['id'] === 'fo12x2')
			{
				$newPort['portId'] = strval($portNumberForId).'A/B';
				$portNumberForId++;
			}
			else
			{
				$newPort['portId'] = strval($portNumberForId);
				$portNumberForId++;
			}

			$this->db()->query('INSERT INTO [mac_lan_patchPanelsPorts]', $newPort);

			$portNumber++;
		}


		$this->docsLog($pk);

		return $pk;
	}
}


/**
 * Class ViewPatchPanels
 * @package mac\lan
 */
class ViewPatchPanels extends TableView
{
	var $patchPanelsKinds;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();

		$this->patchPanelsKinds = $this->app()->cfgItem('mac.lan.patchPanels.kinds');
	}

	public function renderRow ($item)
	{
		$ppkCfg = $this->patchPanelsKinds[$item['patchPanelKind']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => $item['id'], 'class' => 'id'];

		if ($item['rackName'])
			$listItem ['t2'] = ['text' => $item['rackName'], 'suffix' => strval($item['rackPos']), 'icon' => 'icon-window-maximize'];
		else
			$listItem ['t2'] = ['text' => '!!!', 'icon' => 'icon-window-maximize', 'class' => 'e10-error'];

		$listItem['i2'] = ['text' => $ppkCfg['sn'], 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT pp.*, lans.shortName as lanShortName, [racks].fullName AS [rackName]";
		array_push($q, ' FROM [mac_lan_patchPanels] AS [pp]');
		array_push($q, ' LEFT JOIN mac_lan_racks AS racks ON pp.rack = racks.ndx');
		array_push($q, ' LEFT JOIN mac_lan_lans AS lans ON racks.lan = lans.ndx');
		array_push($q, ' WHERE 1');

		$this->rackQuery($q);

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (pp.[fullName] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, 'pp.', ['pp.[rackPos]', 'pp.[fullName]', 'pp.[ndx]']);

		$this->runQuery ($q);
	}

	protected function rackQuery(&$q)
	{
	}

	public function createToolbar ()
	{
		$t = parent::createToolbar();
		unset ($t[0]);

		return $t;
	}
}


/**
 * Class ViewPatchPanelsTree
 * @package mac\lan
 */
class ViewPatchPanelsTree extends ViewPatchPanels
{
	var $racksParam = NULL;
	var $racks = [];

	public function init ()
	{
		$this->usePanelLeft = TRUE;
		$this->linesWidth = 40;

		$this->racks['0'] = $ic = [['text' => 'Vše', 'icon' => 'icon-file-o', ]];
		$this->loadRacks();

		$this->racksParam = new \E10\Params ($this->app);
		$this->racksParam->addParam('switch', 'racks', ['title' => '', 'switch' => $this->racks, 'list' => 1, 'defaultValue' => '0']);
		$this->racksParam->detectValues();

		parent::init();
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->racksParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	function loadRacks()
	{
		$q [] = "SELECT racks.*, places.fullName as placeFullName, lans.shortName as lanShortName FROM [mac_lan_racks] AS racks";
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON racks.place = places.ndx');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON racks.lan = lans.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' ORDER BY lans.shortName, racks.fullName');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$rackNdx = $r['ndx'];
			$lanNdx = $r['lan'];
			if (!isset($this->racks['L'.$lanNdx]))
			{
				$ic = [['text' => $r['lanShortName'], 'icon' => 'icon-sitemap', 'subItems' => []]];
				$this->racks['L'.$lanNdx] = $ic;
			}

			$ic = [['text' => $r['fullName'], 'icon' => 'icon-window-maximize', 'addParams' => ['rack' => $rackNdx]]];
			$this->racks['L'.$lanNdx][0]['subItems'][$rackNdx] = $ic;
		}
	}

	protected function rackQuery(&$q)
	{
		$rackId = $this->queryParam('racks');

		if (!$rackId || !isset($rackId[0]) | $rackId == '0')
			return;

		if ($rackId[0] === 'L')
		{
			array_push($q, ' AND [racks].[lan] = %i', intval(substr($rackId, 1)));
			return;
		}

		array_push($q, ' AND [pp].[rack] = %i',$rackId);
	}
}


/**
 * Class FormPatchPanel
 * @package mac\lan
 */
class FormPatchPanel extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Porty', 'icon' => 'icon-plug'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];

		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
				$this->addColumnInput ('fullName');
				$this->addColumnInput ('id');
				$this->addColumnInput ('rack');
				$this->addColumnInput ('rackPos');
				$this->addColumnInput ('patchPanelKind');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addListViewer ('ports', 'formList');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailPatchPanel
 * @package mac\lan
 */
class ViewDetailPatchPanel extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.dc.PatchPanel');
	}
}

