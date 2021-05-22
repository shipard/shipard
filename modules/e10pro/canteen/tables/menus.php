<?php

namespace e10pro\canteen;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableMenus
 * @package e10pro\canteen
 */
class TableMenus extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.menus', 'e10pro_canteen_menus', 'Jídelní listky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'fullName', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function upload ()
	{
		$uploadString = $this->app()->postData();
		$uploadData = json_decode($uploadString, TRUE);

		if ($uploadData === FALSE)
		{
			error_log ("e10pro.canteen.menus::update parse data error: ".json_encode($uploadData));
			return 'FALSE';
		}

		$canteenNdx = isset($uploadData['canteen']) ? $uploadData['canteen'] : 1;

		$canteen = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$canteenNdx);
		if (!$canteen)
		{
			error_log ("e10pro.canteen.menus::invalid canteen '{$canteenNdx}': ".json_encode($uploadData));
			return 'FALSE';
		}

		foreach ($uploadData['foods'] as $oneFood)
		{
			$this->importFood($canteenNdx, $oneFood);
		}

		return 'OK';
	}

	function importFood ($canteenNdx, $food)
	{
		// -- search menu
		$qm[] = 'SELECT * FROM [e10pro_canteen_menus]';
		array_push($qm, ' WHERE 1');
		array_push($qm, ' AND [canteen] = %i', $canteenNdx);
		array_push($qm, ' AND [dateFrom] <= %d', $food['date']);
		array_push($qm, ' AND [dateTo] >= %d', $food['date']);
		array_push($qm, ' AND [docState] != %i', 9800);

		$existedMenu = $this->db()->query($qm)->fetch();
		if (!$existedMenu)
			return;

		// -- search food
		$qf[] = 'SELECT * FROM [e10pro_canteen_menuFoods]';
		array_push($qf, ' WHERE 1');
		array_push($qf, ' AND [canteen] = %i', $canteenNdx);
		array_push($qf, ' AND [menu] = %i', $existedMenu['ndx']);
		array_push($qf, ' AND [date] = %d', $food['date']);
		array_push($qf, ' AND [foodIndex] = %i', $food['foodIndex']);
		array_push($qf, ' AND [docState] = %i', 1000);

		$existedFood = $this->db()->query($qf)->fetch();
		if (!$existedFood)
			return;

		$updateData = ['foodName' => $food['foodName'], 'soupName' => $food['soupName']];

		if (isset($food['allergens']))
		{
			for ($i = 1; $i < 15; $i++)
			{
				$updateData['allergen'.$i] = 0;
				if (in_array($i, $food['allergens']))
					$updateData['allergen'.$i] = 1;
			}
		}

		if ($food['foodName'] !== '' && $food['soupName'] !== '' && isset($food['allergens']) && count($food['allergens']))
		{
			$updateData['docState'] = 4000;
			$updateData['docStateMain'] = 2;
		}

		$this->db()->query('UPDATE [e10pro_canteen_menuFoods] SET ', $updateData, ' WHERE ndx = %i', $existedFood['ndx']);
	}
}


/**
 * Class ViewMenus
 * @package e10pro\canteen
 */
class ViewMenus extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item['dateFrom'])
			$props[] = ['text' => utils::dateFromTo ($item['dateFrom'], $item['dateTo'], NULL), 'icon' => 'icon-calendar', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_canteen_menus]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[dateFrom] DESC', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormMenu
 * @package e10pro\canteen
 */
class FormMenu extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
//		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Menu', 'icon' => 'icon-file-text-o'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('canteen');
					$this->addColumnInput ('dateFrom');
					$this->addColumnInput ('dateTo');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

