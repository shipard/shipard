<?php

namespace mac\access;

use \e10\TableForm, \e10\DbTable, \e10\TableView, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableLevels
 * @package mac\access
 */
class TableLevels extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.levels', 'mac_access_levels', 'Úrovně přístupu');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}
}


/**
 * Class ViewLevels
 * @package mac\access
 */
class ViewLevels extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$listItem ['t2'] = $item['id'];

		$listItem ['i2'] = [];
		if ($item['enableRoomAccess'])
			$listItem ['i2'][] = ['icon' => 'icon-map-marker', 'text' => 'Povoluje přístup k pokojům', 'class' => 'label label-info'];
		if ($item ['order'])
			$listItem ['i2'][] = ['icon' => 'icon-sort', 'text' => utils::nf ($item ['order'], 0), 'class' => ''];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [mac_access_levels]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormLevel
 * @package mac\access
 */
class FormLevel extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);
		$assignTagsToRooms = intval($this->app()->cfgItem ('options.macAccess.useAssignTagsToRooms', 0));

		$tabs ['tabs'][] = ['text' => 'Přístup', 'icon' => 'icon-empire'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('order');
					if ($assignTagsToRooms)
						$this->addColumnInput ('enableRoomAccess');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('cfg', 'formList');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailLevel
 * @package mac\access
 */
class ViewDetailLevel extends TableViewDetail
{
}

