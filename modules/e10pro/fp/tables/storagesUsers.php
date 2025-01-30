<?php

namespace e10pro\fp;

use \Shipard\Viewer\TableViewGrid, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableStoragesUsers
 */
class TableStoragesUsers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.fp.storagesUsers', 'e10pro_fp_storagesUsers', 'Uživatelé úložišť');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewStoragesUsers
 */
class ViewStoragesUsers extends TableViewGrid
{
	public function init ()
	{
		parent::init();

		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$g = [
			'storage' => 'Úložiště',
			'user' => 'Uživatel',
			'note' => 'Poznámka',
		];
		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['storage'] = $item['storageFullName'];
		$listItem ['user'] = $item['userFullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [su].* ');
		array_push ($q, ', [storages].[fullName] AS [storageFullName]');
    array_push ($q, ', [users].[fullName] AS [userFullName]');
		array_push ($q, ' FROM [e10pro_fp_storagesUsers] AS [su]');
		array_push ($q, ' LEFT JOIN [e10pro_fp_storages] AS [storages] ON [su].[storage] = [storages].[ndx]');
    array_push ($q, ' LEFT JOIN [e10_users_users] AS [users] ON [su].[user] = [users].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [storages].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [users].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[su].', ['[storages].[fullName]', '[users].[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormStoragesUser
 */
class FormStoragesUser extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('storage');
			$this->addColumnInput ('user');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailStoragesUser
 */
class ViewDetailStoragesUser extends TableViewDetail
{
}
