<?php

namespace e10pro\canteen;

use \e10\TableView;


/**
 * Class ViewerMenuComboFood
 * @package e10pro\canteen
 */
class ViewerMenuComboFood extends TableView
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
		$listItem ['t1'] = $item['foodName'];
		$listItem ['t2'] = $this->table->allergens($item);

		$listItem ['data-cc']['foodName'] = $item['foodName'];
		for ($i = 1; $i < 15; $i++)
		{
			if ($item['allergen'.$i])
				$listItem ['data-cc']['allergen'.$i] = '1';
			else
				$listItem ['data-cc']['allergen'.$i] = '0';
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT DISTINCT foodName,';
		array_push ($q, ' allergen1, allergen2, allergen3, allergen4, allergen5, allergen6, allergen7, allergen8,');
		array_push ($q, ' allergen9, allergen10, allergen11, allergen12, allergen13, allergen14');
		array_push ($q, ' FROM [e10pro_canteen_menuFoods]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND docStateMain != 0');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [foodName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[foodName]']);
		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}
