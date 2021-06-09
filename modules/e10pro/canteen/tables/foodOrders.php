<?php

namespace e10pro\canteen;

use \e10\utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableFoodOrders
 * @package e10pro\canteen
 */
class TableFoodOrders extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.foodOrders', 'e10pro_canteen_foodOrders', 'Objednaná jídla');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['takeDone']) && $recData['takeDone'])
		{
			if (utils::dateIsBlank($recData['takeDateTime']) && !utils::dateIsBlank($recData['date']))
				$recData['takeDateTime'] = utils::createDateTime(utils::createDateTime($recData['date'])->format('Y-m-d').' 11:12:14');
		}
		elseif (isset($recData['takeDone']) && !$recData['takeDone'])
		{
			$recData['takeDateTime'] = NULL;
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!utils::dateIsBlank($recData['date']))
			$hdr ['info'][] = ['class' => 'title', 'value' => [
				['text' => utils::datef($recData ['date'], '%n %D')],
				//['text' => strval($recData ['foodIndex']), 'class' => 'pull-right']
			]
			];

		$hdr ['info'][] = ['class' => 'title', 'value' => 'TEST'];

		return $hdr;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		$canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$recData['canteen'], NULL);
		if ($canteenCfg && isset($canteenCfg['addFoods']) && count($canteenCfg['addFoods']))
		{
			$sci = ['columns' => []];
			foreach ($canteenCfg['addFoods'] as $afNdx => $af)
			{
				$c = ['id' => 'addFood_'.$afNdx, 'name' => $af['fn'], 'type' => 'logical'];
				$sci['columns'][] = $c;
			}

			return $sci;
		}

		return parent::subColumnsInfo($recData, $columnId);
	}
}


/**
 * Class ViewFoodOrders
 * @package e10pro\canteen
 */
class ViewFoodOrders extends TableView
{
	var $foodTakings;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->foodTakings = $this->app()->cfgItem ('e10pro.canteen.foodTakings');

		$mq = [
			['id' => 'active', 'title' => 'Aktivní'],
			['id' => 'rest', 'title' => 'Nevydaná', 'side' => 'left'],

			['id' => 'archive', 'title' => 'Archív'],
			['id' => 'all', 'title' => 'Vše'],
			['id' => 'trash', 'title' => 'Koš']
		];
		$this->setMainQueries ($mq);
	}

	public function renderRow ($item)
	{
		$listItem['pk'] = $item ['ndx'];
		$listItem['icon'] = $this->table->tableIcon ($item);

		$listItem['t1'] = $item['personOrderName'];

		$listItem['t3'] = $item['foodIndex'].'. '.$item['foodName'];
		$listItem['t2'] = [];
		$listItem['t2'][] = ['text' => utils::datef($item['date'], '%n %D'), 'icon' => 'system/iconCalendar', 'class' => 'label label-default'];
		$listItem['t2'][] = ['text' => $item['menuName'], 'icon' => 'icon-file-text-o', 'class' => 'label label-default'];

		if ($item['taking'])
		{
			$ft = $this->foodTakings[$item['taking']];
			$listItem['t2'][] = ['text' => $ft['name'], 'icon' => 'icon-gift', 'class' => 'label label-info'];
		}

		$listItem['i2'][] = ['text' => $item['canteenName'], 'icon' => 'icon-cutlery', 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT [orders].*, personsOrder.fullName AS personOrderName,';
		array_push ($q, ' foods.foodName AS foodName, foods.foodIndex AS foodIndex,');
		array_push ($q, ' canteens.shortName AS canteenName, menus.fullName AS menuName');
		array_push ($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push ($q, ' LEFT JOIN e10pro_canteen_canteens AS canteens ON orders.canteen = canteens.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS personsFee ON orders.personFee = personsFee.ndx');
		array_push ($q, ' LEFT JOIN e10pro_canteen_menus AS menus ON orders.menu = menus.ndx');
		array_push ($q, ' LEFT JOIN e10pro_canteen_menuFoods AS foods ON orders.food = foods.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [foodName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [soupName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR personsOrder.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		if ($mainQuery === 'rest')
		{
			$today = utils::today();
			array_push ($q, ' AND orders.[takeDone] = %i', 0);
			array_push ($q, ' AND orders.[food] != %i', 0);
			array_push ($q, ' AND orders.[date] < %d', $today);
			array_push ($q, ' AND orders.[docState] != %i', 9800);
			array_push ($q, ' ORDER BY [date], personsOrder.[lastName]');

			array_push ($q, $this->sqlLimit ());
		}
		else
			$this->queryMain ($q, 'orders.', ['[date] DESC', 'personsOrder.[lastName]', '[ndx]']);

		$this->runQuery ($q);
	}
}


/**
 * Class FormFoodOrder
 * @package e10pro\canteen
 */
class FormFoodOrder extends TableForm
{
	var $canteenCfg = NULL;

	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('maximize', 1);

		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->recData['canteen']);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Historie', 'icon' => 'system/formHistory'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab();
					$this->addColumnInput ('canteen');
					$this->addColumnInput ('menu');
					$this->addColumnInput ('date');
					$this->addColumnInput ('food');

					$this->addSubColumns ('addFoods');

					$this->addColumnInput ('personOrder');
					$this->addColumnInput ('personFee');
					$this->addColumnInput ('taking');
					$this->addColumnInput ('takeDone');
				$this->closeTab();
				$this->openTab(self::ltNone);
					$params = ['tableid' => $this->tableId(),'recid' => $this->recData['ndx']];
					$this->addViewerWidget('e10.base.docslog', 'e10.base.libs.ViewDocsLogDocHistory', $params);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10pro.canteen.foodOrders')
		{
			if ($srcColumnId === 'food')
			{
				$cp = [
					'date' => strval($allRecData ['recData']['date']),
					'menu' => strval($allRecData ['recData']['menu']),
					'canteen' => strval($allRecData ['recData']['canteen']),
				];
				return $cp;
			}
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}

