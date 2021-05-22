<?php

namespace e10pro\canteen;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableMenuFoods
 * @package e10pro\canteen
 */
class TableMenuFoods extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.menuFoods', 'e10pro_canteen_menuFoods', 'Jídla na jídelním lístku');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!utils::dateIsBlank($recData['date']))
			$hdr ['info'][] = ['class' => 'title', 'value' => [
					['text' => utils::datef($recData ['date'], '%n %D')],
					['text' => strval($recData ['foodIndex']), 'class' => 'pull-right']
				]
			];

		$hdr ['info'][] = ['class' => 'title', 'value' => ($recData ['foodName'] === '') ? '---' : $recData ['foodName']];

		return $hdr;
	}

	public function allergens ($recData, $reverse = FALSE, $labelClass='label label-default')
	{
		$allergens = [
			1 => ['text' => 'Lepek'],
			2 => ['text' => 'Korýši'],
			3 => ['text' => 'Vejce'],
			4 => ['text' => 'Ryby'],
			5 => ['text' => 'Arašídy'],
			6 => ['text' => 'Sója'],
			7 => ['text' => 'Mléko'],
			8 => ['text' => 'Skořápkové plody'],
			9 => ['text' => 'Celer'],
			10 => ['text' => 'Hořčice'],
			11 => ['text' => 'Sezam'],
			12 => ['text' => 'Oxid siřičitý a siřičitany'],
			13 => ['text' => 'Vlčí bob'],
			14 => ['text' => 'Měkkýši'],
		];

		$class = $labelClass;
		if ($reverse)
			$class .= ' pull-right';

		$a = [];
		for ($i = 1; $i < 15; $i++)
		{
			if ($recData['allergen'.$i])
				$a[] = ['text' => strval($i), 'title' => $allergens[$i]['text'], 'class' => $class];
		}

		if ($reverse)
			return array_reverse($a);

		return $a;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		/** @var \e10pro\canteen\TableCanteens $tableCanteens */
		$tableCanteens = $this->app()->table('e10pro.canteen.canteens');
		$canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$recData['canteen'], NULL);
		$addFoodsList = $tableCanteens->addFoodsList ($canteenCfg, 0, $recData['date']);
		if (count($addFoodsList))
		{
			$sci = ['columns' => []];
			foreach ($addFoodsList as $afNdx)
			{
				$af = $canteenCfg['addFoods'][$afNdx];
				$c = ['id' => 'addFoodName_'.$afNdx, 'name' => $af['fn'], 'type' => 'string', 'len' => 90];
				$sci['columns'][] = $c;
			}

			return $sci;
		}

		return parent::subColumnsInfo($recData, $columnId);
	}
}


/**
 * Class ViewMenuFoods
 * @package e10pro\canteen
 */
class ViewMenuFoods extends TableView
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
		$listItem ['t1'] = utils::datef($item['date'], '%n %D');
		$listItem ['i1'] = strval($item['foodIndex']);
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t2'] = [];
		$listItem ['t2'][] = ['text' => ($item['foodName'] === '') ? '---' : $item['foodName'], 'icon' => 'icon-cutlery'];
		$listItem ['t2'][] = ['text' => ($item['soupName'] === '') ? '---' : $item['soupName'], 'icon' => 'icon-spoon'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_canteen_menuFoods]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [foodName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [soupName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->defaultQuery($q);

		$this->queryMain ($q, '', ['[date] DESC', '[foodIndex]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewMenuFoodsComboOrder
 * @package e10pro\canteen
 */
class ViewMenuFoodsComboOrder extends ViewMenuFoods
{
	public function defaultQuery(&$q)
	{
		if ($this->queryParam ('date'))
			array_push ($q, ' AND [date] = %d', $this->queryParam ('date'));
		if ($this->queryParam ('canteen'))
			array_push ($q, ' AND [canteen] = %i', $this->queryParam ('canteen'));
		if ($this->queryParam ('menu'))
			array_push ($q, ' AND [menu] = %i', $this->queryParam ('menu'));
	}
}


/**
 * Class FormMenuFood
 * @package e10pro\canteen
 */
class FormMenuFood extends TableForm
{
	var $canteenCfg = NULL;

	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('maximize', 1);

		$this->checkSoup();

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Jídlo', 'icon' => 'icon-bars'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					if ($this->canteenCfg['lunchMenuSoup'] != 2)
						$this->addColumnInput ('soupName');
					$this->addColumnInput ('foodName');

					$this->addSubColumns ('addFoods');

					$this->addSeparator(self::coH2);
					$this->addStatic(['text' => 'Alergeny', 'class' => 'h1 padd5 text-center']);
					$this->layoutOpen (self::ltHorizontal);
						$this->layoutOpen (self::ltForm);
							$this->addColumnInput ('allergen1', self::coRight);
							$this->addColumnInput ('allergen2', self::coRight);
							$this->addColumnInput ('allergen3', self::coRight);
							$this->addColumnInput ('allergen4', self::coRight);
							$this->addColumnInput ('allergen5', self::coRight);
							$this->addColumnInput ('allergen6', self::coRight);
							$this->addColumnInput ('allergen7', self::coRight);
						$this->layoutClose('width50');
						$this->layoutOpen (self::ltForm);
							$this->addColumnInput ('allergen8', self::coRight);
							$this->addColumnInput ('allergen9', self::coRight);
							$this->addColumnInput ('allergen10', self::coRight);
							$this->addColumnInput ('allergen11', self::coRight);
							$this->addColumnInput ('allergen12', self::coRight);
							$this->addColumnInput ('allergen13', self::coRight);
							$this->addColumnInput ('allergen14', self::coRight);
						$this->layoutClose();
					$this->layoutClose();
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('foodIndex');
					$this->addColumnInput ('date');
					$this->addColumnInput ('canteen');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}

	function checkSoup()
	{
		if (!$this->recData['canteen'])
			return;
		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->recData['canteen'], NULL);

		if (!$this->canteenCfg)
			return;
		if ($this->recData['foodIndex'] < 2 || $this->canteenCfg['lunchMenuSoup'] != 0)
			return;
		if (utils::dateIsBlank($this->recData['date']))
			return;
		$prevFood = $this->app()->db()->query ('SELECT * FROM [e10pro_canteen_menuFoods] WHERE [date] = %d', $this->recData['date'], ' AND [canteen] = %i', $this->recData['canteen'], ' AND [foodIndex] = 1')->fetch();
		if (!$prevFood)
			return;

		if ($prevFood['soupName'] !== '')
			$this->recData['soupName'] = $prevFood['soupName'];
	}
}

