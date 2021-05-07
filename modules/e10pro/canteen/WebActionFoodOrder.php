<?php

namespace e10pro\canteen;
use \e10\web\WebAction, \e10\utils;


/**
 * Class WebActionFoodOrder
 * @package e10pro\canteen
 */
class WebActionFoodOrder extends WebAction
{
	var $canteenNdx = 0;
	var $canteenCfg;

	var $menuNdx = 0;
	var $date = NULL;
	var $foodNdx = 0;
	var $personNdx = 0;
	var $orderNumber = 0;
	var $personOptionsFoodNdx = 0;

	function doIt()
	{
		/** @var \e10pro\canteen\TableFoodOrders $tableFoodOrders */
		$tableFoodOrders = $this->app()->table('e10pro.canteen.foodOrders');

		$q[] = 'SELECT * FROM [e10pro_canteen_foodOrders]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [menu] = %i', $this->menuNdx);
		array_push($q, ' AND [date] = %d', $this->date);
		array_push($q, ' AND [personOrder] = %i', $this->personNdx);
		array_push($q, ' AND [orderNumber] = %i', $this->orderNumber);
		array_push($q, ' AND [docState] != %i', 9800);

		$exist = $this->db()->fetch($q);
		if ($exist)
		{
			$addFoods = json_decode($exist['addFoods'], TRUE);
			if (!$addFoods)
				$addFoods = [];

			$update = [];

			if (isset($this->params['food']))
				$update['food'] = $this->foodNdx;

			if ($exist['orderState'] === 0)
				$update['firstChoiceFood'] = $this->foodNdx;

			foreach ($this->params as $pk => $pv)
			{
				if (substr($pk, 0, 9) !== 'add-food-')
					continue;
				$afNdx = intval(substr($pk, 9));
				if (!$afNdx)
					continue;
				$addFoods['addFood_'.$afNdx] = intval($pv);
			}

			if (count($addFoods))
				$update['addFoods'] = json_encode($addFoods);

			$this->db()->query('UPDATE [e10pro_canteen_foodOrders] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
			$tableFoodOrders->docsLog($exist['ndx']);
			$this->setSuccess();
			return;
		}

		$newItem = [
			'canteen' => $this->canteenNdx,
			'menu' => $this->menuNdx,
			'date' => $this->date,
			'food' => $this->foodNdx,
			'firstChoiceFood' => $this->foodNdx,
			'personOrder' => $this->personNdx,
			'orderNumber' => $this->orderNumber,
			'docState' => 4000,
			'docStateMain' => 2,
		];

		$addFoods = [];
		foreach ($this->params as $pk => $pv)
		{
			if (substr($pk, 0, 9) !== 'add-food-')
				continue;
			$afNdx = intval(substr($pk, 9));
			if (!$afNdx)
				continue;
			$addFoods['addFood_'.$afNdx] = intval($pv);
		}
		if (count($addFoods))
			$newItem['addFoods'] = json_encode($addFoods);

		if ($this->personOptionsFoodNdx !== 0)
		{
			$personOptionsFood = $this->db()->query ('SELECT * FROM [e10pro_canteen_personsOptionsFoods] WHERE ndx = %i', $this->personOptionsFoodNdx)->fetch();
			if ($personOptionsFood)
			{
				$newItem['taking'] = $personOptionsFood['taking'];
			}
		}

		$this->db()->query('INSERT INTO [e10pro_canteen_foodOrders] ', $newItem);
		$newNdx = $this->db()->getInsertId();
		$tableFoodOrders->docsLog($newNdx);

		$this->setSuccess();
	}

	public function run ()
	{
		$this->canteenNdx = intval($this->params['canteen']);
		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx, []);

		$this->menuNdx = intval($this->params['menu']);
		$this->foodNdx = intval($this->params['food']);
		$this->date = utils::createDateTime($this->params['date']);
		$this->orderNumber = intval($this->params['order-number']);
		$this->personOptionsFoodNdx = intval($this->params['person-options-food']);

		$this->personNdx = $this->app()->userNdx();

		$this->doIt();
	}
}
