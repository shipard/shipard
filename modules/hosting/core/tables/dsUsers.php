<?php

namespace hosting\core;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable, \E10\utils;


/**
 * class TableDSUsers
 */
class TableDSUsers extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('hosting.core.dsUsers', 'hosting_core_dsUsers', 'Zdroje dat uživatelů');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		if (!isset($recData['created']) || utils::dateIsBlank($recData['created']))
			$recData['created'] = new \DateTime();
	}

	public function checkAfterSave2 (&$recData)
	{
		if (!$recData['dsUsersOptions'])
			$this->createUdsOptions($recData);

		parent::checkAfterSave2($recData);
	}

	public function upload ()
	{
		$uploadString = $this->app()->testGetData ();
		$uploadData = json_decode($uploadString, TRUE);
		if ($uploadData === FALSE)
		{
			error_log ("TableDSUsers::update parse data error: ".json_encode($uploadData));
			return 'FALSE';
		}

		$dsid = $uploadData['dsid'];
		$dsidRecData = $this->db()->query ('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsid)->fetch();
		if (!$dsidRecData)
		{
			error_log ("TableUsersds::invalid dsid '{$dsid}': ".json_encode($uploadData));
			return 'FALSE';
		}

		foreach ($uploadData['users'] as $u)
		{
			$userRecData = $this->db()->query ('SELECT * FROM [e10_persons_persons] WHERE [loginHash] = %s', $u['loginHash'])->fetch();
			if (!$dsidRecData)
			{
				error_log ("TableUsersds::invalid loginHash '{$u['loginHash']}': ".json_encode($u));
				continue;
			}

			$userdsRecData = $this->db()->query ('SELECT * FROM [hosting_core_dsUsers] WHERE [user] = %i AND [dataSource] = %i',
																						$userRecData['ndx'], $dsidRecData['ndx'])->fetch();

			if (!$userdsRecData)
			{
				error_log ("TableUsersds::invalid user #{$userRecData['ndx']} in datasource #{$dsidRecData['ndx']}/{$dsidRecData['gid']}: ".json_encode($u));
				continue;
			}

			$this->db()->query ('UPDATE [hosting_core_dsUsers] set lastLogin = %t WHERE ndx = %i AND (lastLogin < %t OR lastLogin IS NULL)',
													$u['time'], $userdsRecData['ndx'], $u['time']);
		}
		$this->app()->cache->invalidateItem('lib.cacheItems.HostingStats');

		return 'OK';
	}

	public function addUsersDSLink($linkedDataSource)
	{
		$this->db()->query('INSERT INTO [hosting_core_dsUsers]', $linkedDataSource);
		$newLinkedDSNdx = intval ($this->db()->getInsertId ());

		$newUDSOptions = ['uds' => $newLinkedDSNdx];
		$this->db()->query('INSERT INTO [hosting_core_dsUsersOptions]', $newUDSOptions);
		$newUDSOptionsNdx = intval ($this->db()->getInsertId ());

		$this->db()->query('UPDATE [hosting_core_dsUsers] SET [dsUsersOptions] = %i', $newUDSOptionsNdx, ' WHERE [ndx] = %i', $newLinkedDSNdx);
	}

	public function createUdsOptions ($recData)
	{
		$newUDSOptions = ['uds' => $recData['ndx']];

		$this->db()->query('INSERT INTO [hosting_core_dsUsersOptions]', $newUDSOptions);
		$newUDSOptionsNdx = intval ($this->db()->getInsertId ());

		$this->db()->query('UPDATE [hosting_core_dsUsers] SET [dsUsersOptions] = %i', $newUDSOptionsNdx, ' WHERE [ndx] = %i', $recData['ndx']);
	}
}


/**
 * class ViewDetailDSUser
 */
class ViewDetailDSUser extends TableViewDetail
{
	public function createToolbar ()
	{
		return [];
	}
}


/**
 * class ViewDSUsers
 */
class ViewDSUsers extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('user'))
		{
			$this->addAddParam ('user', $this->queryParam ('user'));
		}
		else
		if ($this->queryParam ('dataSource'))
		{
			$this->addAddParam ('dataSource', $this->queryParam ('dataSource'));
		}

		$this->setMainQueries();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'system/iconUser';
		if ($this->queryParam ('dataSource'))
		{
			$listItem ['t1'] = $item['userName'];
			$listItem ['t2'] = $item['userEmail'];

			$listItem ['i2'] = [['icon' => 'system/actionPlay', 'text' => utils::datef ($item['created'], '%D')]];

			if ($item['lastLogin'])
				$listItem ['i2'][] = ['icon' => 'system/actionLogIn', 'text' => utils::datef ($item['lastLogin'], '%D, %T')];

			$listItem ['i1'] = ['text' => '#'.$item['userId'], 'class' => 'id'];
		}
		else
		{
			$listItem ['t1'] = ' '.$item['dsName'];
		}
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		if ($this->queryParam ('user'))
		{
			$q = "SELECT usersds.*, ds.name AS dsName, ds.docState AS docState, ds.docStateMain AS docStateMain FROM [hosting_core_dsUsers] as usersds " .
					 "LEFT JOIN hosting_core_dataSources as ds ON usersds.dataSource = ds.ndx WHERE usersds.user = %i" . $this->sqlLimit();
			$this->runQuery ($q, $this->queryParam ('user'));
		}
		else
		{
			$q[] = 'SELECT usersds.*, users.fullName AS userName, users.ndx AS userNdx, users.id AS userId, users.login AS userEmail';
			array_push($q, ' FROM [hosting_core_dsUsers] AS usersds');
			array_push($q, ' LEFT JOIN e10_persons_persons AS users ON usersds.user = users.ndx');
			array_push($q, ' WHERE usersds.dataSource = %i', $this->queryParam ('dataSource'));

			if ($fts != '')
				array_push ($q, " AND (users.[fullName] LIKE %s)", '%'.$fts.'%');

			if ($mainQuery == 'active' || $mainQuery == '')
				array_push ($q, " AND usersds.[docStateMain] < 4");
			if ($mainQuery == 'archive')
				array_push ($q, " AND usersds.[docStateMain] = 5");
			if ($mainQuery == 'trash')
				array_push ($q, " AND usersds.[docStateMain] = 4");

			array_push ($q, $this->sqlLimit());
			$this->runQuery ($q);
		}
	}
}


/**
 * class FormDSUser
 */
class FormDSUser extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

		$this->addColumnInput ("user");
		$this->addColumnInput ("datasource");

		$this->closeForm ();
	}

	public function createHeaderCode ()
	{
		$item = $this->recData;
		$info = '';//$item ['email'];
		return $this->defaultHedearCode ("x-user", ''/*$item ['fullName']*/, $info);
	}
} // class FormUsersDataSource


