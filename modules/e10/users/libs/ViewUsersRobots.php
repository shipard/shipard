<?php

namespace e10\users\libs;
use \Shipard\Viewer\TableView;


/**
 * ViewUsersRobots
 */
class ViewUsersRobots extends TableView
{
	var $accountStates;
	var $mainRoles;

	public function init ()
	{
		$this->accountStates = $this->app()->cfgItem('e10.users.accountStates');

		$this->setMainQueries();
    $this->addAddParam ('userType', 1);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = [
			['text' => $item['login'], 'class' => 'label label-default', 'icon' => 'user/signIn']
		];
		if ($item['login'] !== $item['email'] && $item['email'] !== '')
			$listItem ['t2'][] = ['text' => $item['email'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];

		$listItem ['i2'] = [
			['text' => $this->accountStates[$item['accState']]['fn'] ?? '!!!', 'class' => 'label label-default'],
		];
		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [users].*');
		array_push ($q, ' FROM [e10_users_users] AS [users]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [userType] = %i', 1);

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [users].[fullName] LIKE %s', '%' . $fts . '%');
      array_push($q, ' OR [users].[login] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

		$this->queryMain ($q, '[users].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$q = [];
		array_push($q, 'SELECT links.*, [roles].fullName as roleName');
		array_push($q, ' FROM e10_base_doclinks AS [links]');
		array_push($q, ' LEFT JOIN e10_users_roles AS [roles] ON links.dstRecId = [roles].ndx');
		array_push($q, ' WHERE dstTableId = %s', 'e10.users.roles');
		array_push($q, ' AND srcTableId = %s', 'e10.users.users');
		array_push($q, ' AND links.srcRecId IN %in', $this->pks);

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$this->mainRoles[$r['srcRecId']][] = ['text' => $r['roleName'], 'class' => 'label label-default'];
		}
	}

	function decorateRow (&$item)
	{
		if (isset($this->mainRoles [$item ['pk']]))
		{
			$item ['t3'] = $this->mainRoles [$item ['pk']];
		}
	}
}


