<?php

namespace swdev\icons;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \e10\utils, \e10\str;


/**
 * Class TableAppIconsMapping
 * @package swdev\icons
 */
class TableAppIconsMapping extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.icons.appIconsMapping', 'swdev_icons_appIconsMapping', 'Namapování aplikačních ikon');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader($recData, $options);

		if ($recData['appIcon'])
		{
			$appIcon = $this->app()->loadItem($recData['appIcon'], 'swdev.icons.appIcons');
			if ($appIcon)
			{
				$h ['info'][] = ['class' => 'title', 'value' => $appIcon ['fullName']];
				$h ['info'][] = ['class' => 'info', 'value' => $appIcon ['id']];
			}
		}
		return $h;
	}
}


/**
 * Class ViewAppIconsMapping
 * @package swdev\icons
 */
class ViewAppIconsMapping extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];


		$listItem ['icon'] = $this->table->tableIcon($item);
		//$listItem ['t1'] = $item['shortName'];
		//$listItem ['t2'] = $item['fullName'];
		$listItem ['i2'] = $item['id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT appIconsMapping.* ';
		array_push ($q, ' FROM [swdev_icons_appIconsMapping] AS [appIconsMapping]');
		array_push ($q, ' WHERE 1');


		$this->queryMain ($q, '[appIconsMapping].', ['[appIcons].[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormAppIconMapping
 * @package swdev\icons
 */
class FormAppIconMapping extends TableForm
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
					$this->addColumnInput ('setIcon');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('appIcon');
					$this->addColumnInput ('iconSet');
				$this->closeTab ();
			$this->closeTabs ();
			$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'swdev.icons.appIconsMapping')
		{
			$cp = [];
			if ($srcColumnId === 'setIcon')
				$cp = ['iconsSet' => $recData ['iconSet']];
			if (count($cp))
				return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * Class ViewDetailAppIconMapping
 * @package swdev\icons
 */
class ViewDetailAppIconMapping extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
