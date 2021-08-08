<?php

namespace swdev\icons;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \e10\utils, \e10\str;


/**
 * Class TableAppIcons
 * @package swdev\translation
 */
class TableAppIcons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.icons.appIcons', 'swdev_icons_appIcons', 'Ikony aplikace');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['shortName']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];

		return $h;
	}
}


/**
 * Class ViewAppIcons
 * @package swdev\translation
 */
class ViewAppIcons extends TableView
{
	/** @var \e10\Params */
	var $groupsParam = NULL;

	var $defaultSet = NULL;

	public function init ()
	{
		parent::init();
		$this->setMainQueries ();

		$this->setPanels (TableView::sptQuery);


		$defaultSet = $this->db()->query('SELECT * FROM [swdev_icons_sets] ',
			'WHERE isPrimaryForAppIconsAdm = 1 LIMIT 1')->fetch();
		if ($defaultSet)
		{
			$this->defaultSet = $defaultSet->toArray();
			
		}
		else
			$this->defaultSet = ['ndx' => 0];

		// -- left panel; groups
		$this->usePanelLeft = TRUE;
		$this->linesWidth = 45;

		$groups = $this->db()->query('SELECT * FROM [swdev_icons_appIconsGroups] WHERE docState = %i', 4000, ' ORDER BY fullName');

		$enum = [];
		forEach ($groups as $g)
			$enum[$g['ndx']] = ['text' => $g['fullName'], 'addParams' => ['appIconGroup' => $g['ndx']]];
		$this->groupsParam = new \E10\Params ($this->app);
		$this->groupsParam->addParam('switch', 'group', ['title' => '', 'switch' => $enum, 'list' => 1]);
		$this->groupsParam->detectValues();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		if ($this->defaultSet['ndx'] && $item['setIconId'])
			$listItem ['svgIcon'] = $this->app()->dsRoot.'/sc/'.$this->defaultSet['pathSvgs'].'duotone'.'/'.$item['setIconId'].'.svg';
		else
		{
			$listItem ['icon'] = $this->table->tableIcon($item);
			$listItem ['class'] = 'e10-warning1';
		}

		$listItem ['t1'] = $item['shortName'];
		$listItem ['t2'] = $item['fullName'];
		$listItem ['i2'] = $item['id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [appIcons].*, [setsIcons].id AS setIconId';
		array_push ($q, ' FROM [swdev_icons_appIcons] AS [appIcons]');
		array_push ($q, ' LEFT JOIN [swdev_icons_appIconsMapping] AS [mi] ON appIcons.ndx = [mi].appIcon AND [mi].iconSet = %i', $this->defaultSet['ndx']);
		array_push ($q, ' LEFT JOIN [swdev_icons_setsIcons] AS [setsIcons] ON [mi].setIcon = [setsIcons].ndx');
		array_push ($q, ' WHERE 1');

		// -- dict
		$groupNdx = 0;
		if ($this->groupsParam)
			$groupNdx = intval($this->groupsParam->detectValues()['group']['value']);
		if ($groupNdx)
			array_push($q, ' AND [appIcons].[appIconGroup] = %i', $groupNdx);

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [appIcons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR [appIcons].[shortName] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR [appIcons].[id] LIKE %s', '%'.$fts.'%');
			array_push($q, ')');
		}

		$this->queryMain ($q, '[appIcons].', ['[appIcons].[fullName]', '[appIcons].[ndx]']);
		$this->runQuery ($q);
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		if (!$this->groupsParam)
			return;

		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->groupsParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class FormAppIcon
 * @package swdev\icons
 */
class FormAppIcon extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('appIconGroup');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailAppIcon
 * @package swdev\icons
 */
class ViewDetailAppIcon extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('swdev.icons.dc.AppIcon');
	}
}
