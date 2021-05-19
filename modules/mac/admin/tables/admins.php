<?php

namespace mac\admin;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils;


/**
 * Class TableAdmins
 * @package mac\admin
 */
class TableAdmins extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.admin.admins', 'mac_admin_admins', 'Správci');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}
}


/**
 * Class ViewAdmins
 * @package mac\admin
 */
class ViewAdmins extends TableView
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
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $item['login'];
//		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [admins].* FROM [mac_admin_admins] AS [admins]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [name] LIKE %s', '%'.$fts.'%',
				' OR [login] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[admins].', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailAdmin
 * @package mac\admin
 */
class ViewDetailAdmin extends TableViewDetail
{
}


/**
 * Class FormAdmin
 * @package mac\admin
 */
class FormAdmin extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		//$tabs ['tabs'][] = ['text' => 'Kamery', 'icon' => 'icon-video-camera'];
		//$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('login');
					$this->addColumnInput ('name');
				$this->closeTab ();
		//		$this->openTab (TableForm::ltNone);
		//		$this->addList ('cameras');
		//		$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
