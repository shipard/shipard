<?php

namespace e10\web;

require_once __SHPD_MODULES_DIR__ . 'e10/web/web.php';

use \e10\TableView, \e10\TableForm, \e10\DbTable, \e10\TableViewDetail, \e10\DataModel;


/**
 * Class TableScripts
 * @package e10\web
 */
class TableScripts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.scripts', 'e10_web_scripts', 'Skripty pro web');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset ($recData['gid']) && $recData['gid'] === '')
		{
			$recData['gid'] = strval(base_convert(mt_rand(1000000, 9999999), 10, 36)).strval(base_convert(time(), 10, 36));
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}
}


/**
 * Class ViewScripts
 * @package e10\web
 */
class ViewScripts extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['gid'], 'class' => 'gid'];
		$listItem ['t2'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT *';
		array_push ($q, ' FROM [e10_web_scripts] AS [scripts]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' scripts.[name] LIKE %s', '%'.$fts.'%',
				' OR scripts.[id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[scripts].', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailScriptPreview
 * @package e10\web
 */
class ViewDetailScriptPreview extends TableViewDetail
{
	public function createDetailContent ()
	{
		$code = 'Test 1';
		$this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => $code]);
	}
}


/**
 * Class ViewDetailScriptSource
 * @package e10\web
 */
class ViewDetailScriptSource extends TableViewDetail
{
	public function createDetailContent ()
	{
		$code = 'Test 2';
		$this->addContent(['type' => 'text', 'subtype' => 'code', 'text' => $code]);
	}
}


/**
 * Class FormScript
 * @package e10\web
 */
class FormScript extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Skript', 'icon' => 'formScript'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('code', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('id');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
