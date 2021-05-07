<?php

namespace e10pro\bume;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableBulkPosts
 * @package e10pro\wkf
 */
class TableBulkPosts extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10pro.bume.bulkPosts', 'e10pro_wkf_bulkPosts', 'Odeslaná hromadná pošta');
	}
}


/**
 * Class ViewBulkPosts
 * @package e10pro\wkf
 */
class ViewBulkPosts extends TableView
{
	var $virtualGroups;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['personName'];
		$listItem ['t2'] = $item['email'];

		$props = [];

		if ($item['sent'])
			$props [] = ['icon' => 'icon-paper-plane-o', 'text' => utils::datef ($item['sentDate'], '%D, %T')];
		else
			$props [] = ['icon' => 'icon-hourglass-half', 'text' => 'Čeká se na odeslání'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT posts.*, persons.fullName AS personName FROM [e10pro_wkf_bulkPosts] AS posts ';
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON posts.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND posts.[bulkMail] = %i', $this->queryParam('bulkMail'));

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([email] LIKE %s OR persons.[fullName] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%');

		array_push ($q, ' ORDER BY persons.lastName, persons.fullName, ndx');
		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}
}
