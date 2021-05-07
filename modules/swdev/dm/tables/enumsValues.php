<?php

namespace swdev\dm;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\TableViewPanel, \E10\DbTable, \E10\utils;


/**
 * Class TableEnumsValues
 * @package swdev\dm
 */
class TableEnumsValues extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('swdev.dm.enumsValues', 'swdev_dm_enumsValues', 'Hodnoty Enumů');
	}

	public function createHeader ($recData, $options)
	{
		$h = parent::createHeader ($recData, $options);
		$h ['info'][] = ['class' => 'title', 'value' => $recData ['text']];
		$h ['info'][] = ['class' => 'info', 'value' => $recData ['value']];

		return $h;
	}
}


/**
 * Class ViewEnumsValues
 * @package swdev\dm
 */
class ViewEnumsValues extends TableView
{
	var $classification;

	public function init ()
	{
		parent::init();

		$this->setMainQueries ();

		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['text'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = $item['value'];

		return $listItem;
	}


	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT enumsValues.*';

		array_push ($q, ' FROM [swdev_dm_enumsValues] AS enumsValues');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts !== '')
		{
			array_push($q, ' AND (');
			array_push($q,
				'enumsValues.[text] LIKE %s', '%'.$fts.'%',
				' OR enumsValues.[value] LIKE %s', '%'.$fts.'%'
			);

			if (strlen($fts) >= 4 && strval(intval($fts)) === $fts)
				array_push($q, ' OR enumsValues.ndx = %i', intval($fts));

			array_push($q, ')');
		}

		$this->queryMain ($q, 'enumsValues.', ['enumsValues.[value]', 'enumsValues.[columnId]', 'enumsValues.[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormEnumValue
 * @package swdev\dm
 */
class FormEnumValue extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Enum', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'cfg', 'icon' => 'icon-cogs'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('columnId');
					$this->addColumnInput ('value');
					$this->addColumnInput ('text');
					$this->addColumnInput ('srcLanguage');
				$this->closeTab ();
				$this->openTab (self::ltNone);
					$this->addInputMemo ('data', NULL, self::coFullSizeY|self::coReadOnly);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailEnumValue
 * @package swdev\dm
 */
class ViewDetailEnumValue extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
