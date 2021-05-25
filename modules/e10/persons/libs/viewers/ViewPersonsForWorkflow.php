<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView, e10\utils;


class ViewPersonsForWorkflow extends TableView
{
	public $mainGroup = 0;
	public $properties = [];
	public $addresses = [];
	protected $loadAddresses = FALSE;

	public function init ()
	{
		if ($this->queryParam ('systemGroup'))
			$this->setMainGroup ($this->queryParam ('systemGroup'));

		if ($this->mainGroup)
			$this->addAddParam ('maingroup', $this->mainGroup);
	}

	public function icon ($recData, $iconSet = NULL)
	{
		return $this->table->icon ($recData, $iconSet);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] =	'(SELECT ndx, 999 as company, 0 as gender, name as fullName, \'\' AS id FROM [e10_persons_groups] WHERE 1';
		if ($fts != '')
		{
			array_push ($q, " AND [name] LIKE %s", '%'.$fts.'%');
		}
		array_push ($q, ' AND docStateMain <= 2');
		array_push ($q, ' ORDER BY [name]');

		array_push ($q, ') UNION ');

		array_push ($q, '(SELECT ndx, company, gender, fullName, id FROM [e10_persons_persons] WHERE 1');
		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND ([fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, " EXISTS (SELECT ndx FROM e10_base_properties WHERE e10_persons_persons.ndx = e10_base_properties.recid AND valueString LIKE %s AND tableid = %s)", '%'.$fts.'%', 'e10.persons.persons');
			array_push ($q, ") ");
		}
		array_push ($q, " AND [docStateMain] < 4");
		array_push ($q, ' ORDER BY [lastName], [firstName] ');


		array_push ($q, ")" . $this->sqlLimit ());


		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		/*
		if (!count ($this->pks))
			return;

		$this->properties = $this->table->loadProperties ($this->pks);

		// -- addresses
		if ($this->loadAddresses)
		{
			$q = "SELECT * FROM [e10_persons_address] WHERE tableid = %s AND recid IN %in";
			$addrs = $this->table->db()->query ($q, 'e10.persons.persons', $this->pks);
			forEach ($addrs as $a)
				$this->addresses [$a ['recid']] = $a['street'] . ', ' . $a['city'];
		}
		 *
		 */
	}

	function decorateRow (&$item)
	{
		/*
		if (isset ($this->properties [$item ['pk']]['groups']))
			$item ['i2'] = $this->properties [$item ['pk']]['groups'];

		if (isset ($this->properties [$item ['pk']]['ids']))
			$item ['t2'] = $this->properties [$item ['pk']]['ids'];
		else
		if (isset ($this->properties [$item ['pk']]['contacts']))
			$item ['t2'] = $this->properties [$item ['pk']]['contacts'];
		 *
		 */
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		if ($item['company'] === 999)
		{
			$listItem ['icon'] = 'e10-persons-groups';
			$listItem ['table'] = 'e10.persons.groups';
		}
		else
		{
			$listItem ['icon'] = $this->icon ($item);
			$listItem ['table'] = 'e10.persons.persons';
		}
		$listItem ['t1'] = $item['fullName'];

		if ($item['id'] && $item['id'] !== '')
			$listItem ['i1'] = ['text' => '#'.$item['id'], 'class' => 'id'];

		return $listItem;
	}

	public function setMainGroup ($group)
	{
		$groupsMap = $this->table->app()->cfgItem ('e10.persons.groupsToSG', FALSE);
		if ($groupsMap && isset ($groupsMap [$group]))
			$this->mainGroup = $groupsMap [$group];
	}
}
