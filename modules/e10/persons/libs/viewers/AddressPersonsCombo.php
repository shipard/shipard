<?php

namespace e10\persons\libs\viewers;

use \e10\TableView, e10\utils;


/**
 * Class AddressPersonsCombo
 * @package e10\persons\libs\viewers
 */
class AddressPersonsCombo extends TableView
{
	var $personNdx = 0;

	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

		if ($this->queryParam ('person'))
		{
			$this->personNdx = intval($this->queryParam('person'));
			$this->addAddParam('tableid', 'e10.persons.persons');
			$this->addAddParam('recid', $this->personNdx);
		}

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [address].* ';
		//array_push ($q, ' persons.fullName as personFullName ');
		array_push ($q, ' FROM [e10_persons_address] AS [address]');
		//array_push ($q, ' LEFT JOIN e10_persons_persons as suppliers ON [orders].supplier = [suppliers].ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND [address].[tableid] = %s', 'e10.persons.persons');
		array_push ($q, ' AND [recid] = %i', $this->personNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [address].city LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [address].street LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [address].specification LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push($q, ' ORDER BY [address].[city] DESC, [address].[street], [address].[ndx] ' . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['city'];
		$listItem ['t2'][] = $item['street'];

		return $listItem;
	}
}
