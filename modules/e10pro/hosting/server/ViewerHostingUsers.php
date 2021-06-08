<?php

namespace e10pro\hosting\server;

use \e10\utils;


/**
 * Class ViewerHostingUsers
 * @package e10pro\hosting\server
 */
class ViewerHostingUsers extends \e10\persons\ViewUsers
{
	var $usersDataSources = [];
	var $lastLogins = [];
	var $now;

	public function init ()
	{
		$this->now = new \DateTime();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow($item);

		$listItem ['t2'] = [];
		$listItem ['t2'][] = ['text' => $item['login'], 'icon' => 'icon-sign-in', 'class' => ''];
		$listItem ['loginHash'] = $item['loginHash'];

		$roles = [];
		if ($item['accountState'] == 0)
			$roles[] = ['class' => 'label label-danger', 'text' => 'Neaktivováno', 'icon' => 'system/iconWarning'];

		if ($item['roles'] === '')
		{
			$roles[] = ['class' => 'label label-danger', 'text' => 'Uživatel nemá přiřazeny role', 'icon' => 'system/iconWarning'];
		}
		elseif ($item['roles'] !== 'guest')
		{
			$rolesIds = explode('.', $item['roles']);
			foreach ($rolesIds as $roleId)
			{
				if ($roleId === '')
					continue;
				$roles[] = ['class' => 'label label-info', 'text' => $this->roles[$roleId]['name']];
			}
		}
		$listItem ['t3'] = $roles;
		return $listItem;
	}

	function decorateRow (&$item)
	{
		parent::decorateRow($item);

		$pk = $item ['pk'];

		if (count($item['t3']))
			$item['t3'][] = ['text' => '', 'class' => 'break'];

		if (isset($this->lastLogins[$pk]))
		{
			$old = utils::dateDiff($this->lastLogins[$pk], $this->now);

			if ($old > 180)
				$item['t3'][] = [
					'text' => utils::datef($this->lastLogins[$pk], '%S, %T'), 'suffix' => utils::nf($old).' dnů',
					'icon' => 'icon-clock-o', 'class' => 'label label-warning'
				];
			else
				$item['t3'][] = ['text' => utils::datef($this->lastLogins[$pk], '%S, %T'), 'icon' => 'icon-clock-o', 'class' => 'label label-default'];
		}
		else
			$item['t3'][] = ['text' => 'Nikdy', 'icon' => 'icon-clock-o', 'class' => 'label label-warning'];


		if (isset($this->usersDataSources[$pk]))
		{
			$cnt = 0;
			foreach ($this->usersDataSources[$pk] as $uds)
			{
				$item['t3'][] = $uds;
				$cnt++;
				if ($cnt === 3)
				{
					$plusCnt = count($this->usersDataSources[$pk]) - $cnt;
					if ($plusCnt)
						$item['t3'][] = ['text' => '+'.utils::nf ($plusCnt), 'icon' => 'icon-database', 'class' => 'label label-default'];
					break;
				}
			}
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		parent::selectRows2();

		$q[] = 'SELECT usersds.*, ds.name AS dsName, ds.shortName AS dsShortName, ds.docStateMain AS dsDocStateMain';
		array_push ($q, ' FROM [e10pro_hosting_server_usersds] AS usersds');
		array_push ($q, ' LEFT JOIN [e10pro_hosting_server_datasources] AS ds ON usersds.datasource = ds.ndx');
		array_push ($q, ' WHERE usersds.[user] IN %in', $this->pks);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$pk = $r['user'];
			$dsClass= 'label-success';
			if ($r['docStateMain'] !== 2)
				$dsClass= 'label-danger';
			if ($r['dsDocStateMain'] !== 2)
				$dsClass= 'label-warning';

			$this->usersDataSources[$pk][] = [
				'text' => ($r['dsShortName'] != '' ? $r['dsShortName'] : $r['dsName']),
				'icon' => 'icon-database', 'class' => 'label '.$dsClass
			];

			if (!isset($this->lastLogins[$pk]))
				$this->lastLogins[$pk] = $r['lastLogin'];
			elseif ($this->lastLogins[$pk] < $r['lastLogin'])
				$this->lastLogins[$pk] = $r['lastLogin'];
		}
	}

	public function defaultQuery (&$q)
	{
		$this->roles = $this->table->app()->cfgItem ('e10.persons.roles');

		array_push ($q, ' AND (');
		array_push ($q, ' [roles] != %s', '');
		array_push ($q, ' OR [login] != %s', '');
		array_push ($q, ')');

		$bt = $this->bottomTabId ();
		if ($bt === 'robots')
			array_push ($q, ' AND [personType] = %i', 3);
		else
			array_push ($q, ' AND [personType] != %i', 3);
	}
}
