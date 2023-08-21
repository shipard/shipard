<?php

namespace e10\persons;

use e10\utils;


/**
 * Class ViewUsers
 * @package e10\persons
 */
class ViewUsers extends \e10\persons\ViewPersons
{
	var $roles;
	var $loginHashDuplicities = [];

	public function init ()
	{
		parent::init();

		$bt = [
			['id' => 'users', 'title' => 'Uživatelé', 'active' => 1],
			['id' => 'robots', 'title' => 'Roboti', 'active' => 0, 'addParams' => ['personType' => 3]]
		];

		$this->setBottomTabs ($bt);
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

	public function qryFullTextExt (array &$q)
	{
		$fts = $this->fullTextSearch ();
		if ($fts !== '')
		{
			array_push ($q, ' OR [persons].[login] LIKE %s', '%'.$fts.'%');
		}
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow($item);

		$listItem ['t2'] = [];
		$listItem ['t2'][] = ['text' => $item['login'], 'icon' => 'system/actionLogIn', 'class' => ''];
		$listItem ['loginHash'] = $item['loginHash'];

		$roles = [];
		if ($item['accountState'] == 0)
			$roles[] = ['class' => 'label label-danger', 'text' => 'Neaktivováno', 'icon' => 'system/iconWarning'];

		if ($item['roles'] === '')
		{
			$roles[] = ['class' => 'label label-danger', 'text' => 'Uživatel nemá přiřazeny role', 'icon' => 'system/iconWarning'];
		}
		else
		{
			$rolesIds = explode('.', $item['roles']);
			foreach ($rolesIds as $roleId)
			{
				if ($roleId === '')
					continue;
				if (isset($this->roles[$roleId]) && isset($this->roles[$roleId]['name']))
					$roles[] = ['class' => 'label label-info', 'text' => $this->roles[$roleId]['name']];
				else
					$roles[] = ['class' => 'label label-danger', 'text' => 'Chyba: `'.$roleId.'`'];
			}
		}
		$listItem ['t3'] = $roles;
		return $listItem;
	}

	function decorateRow (&$item)
	{
		parent::decorateRow($item);

		if (isset($this->loginHashDuplicities[$item['loginHash']]))
		{
			$item['t2'][0]['class'] .= ' e10-error';
			$item['t2'][0]['icon'] = 'system/iconWarning';
			$item['t2'][0]['suffix'] = 'Duplicitní email!';
		}

		if (count($item['t2']) > 1 && $item['t2'][0]['text'] === $item['t2'][1]['text'])
			unset ($item['t2'][1]);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		parent::selectRows2();

		$q[] = 'SELECT count(*) as cnt, [loginHash] FROM [e10_persons_persons]';
		array_push ($q, ' WHERE loginHash != %s', '', ' AND [docStateMain] IN %in', [0, 2]);
		array_push ($q, ' GROUP BY [loginHash] HAVING cnt > 1');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->loginHashDuplicities[$r['loginHash']] = $r['cnt'];
		}
	}
}
