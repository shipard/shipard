<?php

namespace e10\persons\libs\viewers;

use \e10\TableView, e10\utils;


/**
 * Class AddressPersons
 * @package e10\persons\libs\viewers
 */
class AddressPersons extends TableView
{
	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [address].*, ';
		array_push ($q, ' persons.fullName as personFullName ');
		array_push ($q, ' FROM [e10_persons_address] AS [address]');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON [address].recid = [persons].ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [address].[tableid] = %s', 'e10.persons.persons');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [address].city LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [address].street LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [address].specification LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [persons].fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push($q, ' ORDER BY [address].[city] DESC, [address].[street], [address].[ndx] ' . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['personFullName'];
		$listItem ['t2'][] = $item['street'];

		return $listItem;
	}
}
