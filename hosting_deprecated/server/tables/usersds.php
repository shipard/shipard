<?php

namespace E10pro\Hosting\Server;

use \E10\Application, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable, \E10\utils;

class TableUsersds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.hosting.server.usersds", "e10pro_hosting_server_usersds", "Zdroje dat uživatelů");
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		$recData['created'] = new \DateTime();
	}

	public function checkAfterSave2 (&$recData)
	{
		if (!$recData['udsOptions'])
			$this->createUdsOptions($recData);

		parent::checkAfterSave2($recData);
	}

	public function upload ()
	{
		$uploadString = Application::testGetData ();
		$uploadData = json_decode($uploadString, TRUE);
		if ($uploadData === FALSE)
		{
			error_log ("TableUsersds::update parse data error: ".json_encode($uploadData));
			return 'FALSE';
		}

		$dsid = $uploadData['dsid'];
		$dsidRecData = $this->db()->query ('SELECT * FROM [e10pro_hosting_server_datasources] WHERE [gid] = %i', $dsid)->fetch();
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

			$userdsRecData = $this->db()->query ('SELECT * FROM [e10pro_hosting_server_usersds] WHERE [user] = %i AND [datasource] = %i',
																						$userRecData['ndx'], $dsidRecData['ndx'])->fetch();

			if (!$userdsRecData)
			{
				error_log ("TableUsersds::invalid user #{$userRecData['ndx']} in datasource #{$dsidRecData['ndx']}: ".json_encode($u));
				continue;
			}

			$this->db()->query ('UPDATE [e10pro_hosting_server_usersds] set lastLogin = %t WHERE ndx = %i AND (lastLogin < %t OR lastLogin IS NULL)',
													$u['time'], $userdsRecData['ndx'], $u['time']);
			$this->db()->query ('UPDATE [e10pro_hosting_server_datasources] set lastLogin = %t WHERE ndx = %i AND (lastLogin < %t OR lastLogin IS NULL)',
													$u['time'], $dsidRecData['ndx'], $u['time']);
		}
		$this->app()->cache->invalidateItem('lib.cacheItems.HostingStats');

		return 'OK';
	}

	public function addUsersDSLink($linkedDataSource)
	{
		$this->db()->query('INSERT INTO [e10pro_hosting_server_usersds]', $linkedDataSource);
		$newLinkedDSNdx = intval ($this->db()->getInsertId ());

		$newUDSOptions = ['uds' => $newLinkedDSNdx];
		$this->db()->query('INSERT INTO [e10pro_hosting_server_udsOptions]', $newUDSOptions);
		$newUDSOptionsNdx = intval ($this->db()->getInsertId ());

		$this->db()->query('UPDATE [e10pro_hosting_server_usersds] SET [udsOptions] = %i', $newUDSOptionsNdx, ' WHERE [ndx] = %i', $newLinkedDSNdx);
	}

	public function createUdsOptions ($recData)
	{
		$newUDSOptions = ['uds' => $recData['ndx']];

		$this->db()->query('INSERT INTO [e10pro_hosting_server_udsOptions]', $newUDSOptions);
		$newUDSOptionsNdx = intval ($this->db()->getInsertId ());

		$this->db()->query('UPDATE [e10pro_hosting_server_usersds] SET [udsOptions] = %i', $newUDSOptionsNdx, ' WHERE [ndx] = %i', $recData['ndx']);
	}
}


/**
 * Základní detail Zdroje dat Uživatele
 *
 */

class ViewDetailUsersds extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$item = $this->item;
		$info = '';
		return $this->defaultHedearCode ("x-datasource", '', $info);
	}

	public function createToolbar ()
	{
		return array ();
	}
}


/*
 * FormUsersDataSource
 *
 */

class FormUsersDataSource extends TableForm
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


/**
 * Zdroje dat Uživatele
 *
 */

class ViewUsersDataSources extends \E10\TableView
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
		if ($this->queryParam ('datasource'))
		{
			$this->addAddParam ('datasource', $this->queryParam ('datasource'));
		}

		$this->setMainQueries();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'system/iconUser';
		if ($this->queryParam ('datasource'))
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
			$q = "SELECT usersds.*, ds.name AS dsName, ds.docState AS docState, ds.docStateMain AS docStateMain FROM [e10pro_hosting_server_usersds] as usersds " .
					 "LEFT JOIN e10pro_hosting_server_datasources as ds ON usersds.datasource = ds.ndx WHERE usersds.user = %i" . $this->sqlLimit();
			$this->runQuery ($q, $this->queryParam ('user'));
		}
		else
		{
			$q[] = 'SELECT usersds.*, users.fullName AS userName, users.ndx AS userNdx, users.id AS userId, users.login AS userEmail'.
						 ' FROM [e10pro_hosting_server_usersds] as usersds ' .
					 	 ' LEFT JOIN e10_persons_persons as users ON usersds.user = users.ndx';

			array_push($q, ' WHERE usersds.datasource = %i', $this->queryParam ('datasource'));

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
/*
	public function createToolbar ()
	{
		return array ();
	}*/
} // class ViewUsersDataSources

