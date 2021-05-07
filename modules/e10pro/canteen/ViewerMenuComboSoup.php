<?php

namespace e10pro\canteen;

use \e10\TableView;


/**
 * Class ViewerMenuComboSoup
 * @package e10pro\canteen
 */
class ViewerMenuComboSoup extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['soupName'];
		$listItem ['data-cc']['soupName'] = $item['soupName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT DISTINCT soupName';
		array_push ($q, ' FROM [e10pro_canteen_menuFoods]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND docStateMain != 0');
		array_push ($q, ' AND soupName != %s', '');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [soupName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[soupName]']);
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}
