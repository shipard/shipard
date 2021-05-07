<?php

namespace mac\iot;
use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils;


/**
 * Class TableUsers
 * @package mac\iot
 */
class TableUsers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.users', 'mac_iot_users', 'IoT Uživatelé');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}
}


/**
 * Class ViewUsers
 * @package mac\iot
 */
class ViewUsers extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $item['login'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [users].* FROM [mac_iot_users] AS [users]';
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

		$this->queryMain ($q, '[users].', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailUser
 * @package mac\iot
 */
class ViewDetailUser extends TableViewDetail
{
}


/**
 * Class FormUser
 * @package mac\iot
 */
class FormUser extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Uživatel', 'icon' => 'icon-user-o'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('userType');
					$this->addColumnInput ('login');
					$this->addColumnInput ('name');
					$this->addColumnInput ('password');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					//$this->addList ('');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
