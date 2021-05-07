<?php

namespace e10pro\canteen\libs;

use e10\TableForm, e10\Wizard, e10\utils;


/**
 * Class WizardModifyFoodOrder
 * @package e10pro\canteen\libs
 */
class WizardModifyFoodOrder extends Wizard
{
	/** @var \e10pro\canteen\TableFoodOrders */
	var $tableFoodOrders;
	/** @var \e10pro\canteen\TableCanteens */
	var $tableCanteens;

	/** @var \e10\persons\TablePersons */
	var $tablePersons;

	var $orderNdx = 0;
	var $recDataOrder = NULL;
	var $recDataPerson;

	var $canteenCfg = NULL;

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->doIt();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1:
							$this->orderNdx = $this->recData['orderNdx'];
							$this->loadInfo();
							$this->renderFormDone (); break;
		}
	}

	public function addParams ()
	{
		$this->recData['orderNdx'] = $this->orderNdx;
		$this->addInput('orderNdx', '', self::INPUT_STYLE_STRING,TableForm::coHidden, 20);


		$this->addStatic(['text' => 'Jídlo', 'class' => 'h2 padd5']);
		$foods = $this->loadFoods();
		$this->recData['foodNdx'] = $this->recDataOrder['food'];
		$this->addInputEnum2('foodNdx', ' ', $foods, TableForm::INPUT_STYLE_RADIO);

		$this->addSeparator();

		$enabledAddFoods = $this->tableCanteens->addFoodsList($this->canteenCfg, $this->recDataOrder['personOrder'], $this->recDataOrder['date']);
		$orderAddFoods = json_decode($this->recDataOrder['addFoods'], TRUE);
		if (!$orderAddFoods)
			$orderAddFoods = [];

		$idx = 1;
		foreach ($enabledAddFoods as $eafNdx)
		{
			$afCfg = $this->canteenCfg['addFoods'][$eafNdx];
			$ordered = 0;
			if ($orderAddFoods && isset($orderAddFoods['addFood_'.$eafNdx]))
				$ordered = intval($orderAddFoods['addFood_'.$eafNdx]);
			elseif (isset($this->recDataOrder['addFood'.$idx]) && $this->recDataOrder['addFood'.$idx] == 3)
				$ordered = 1;

			$this->addCheckBox('addFood_'.$eafNdx, $afCfg['fn'], '1', self::coRight);
			$this->recData['addFood_'.$eafNdx] = $ordered;

			$idx++;
		}
	}

	public function renderFormWelcome ()
	{
		$this->orderNdx = $this->app->testGetParam('order-ndx');
		$this->loadInfo();

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addParams();
		$this->closeForm ();
	}

	public function doIt ()
	{
		$this->orderNdx = $this->recData['orderNdx'];
		$this->loadInfo();

		$update = [
			'ndx' => $this->orderNdx,
			'food' => $this->recData['foodNdx'],
		];

		$addFoods = [];
		foreach ($this->recData as $k => $v)
		{
			if (substr($k, 0, 8) !== 'addFood_')
				continue;
			$addFoods[$k] = $v;
		}
		$update['addFoods'] = json_encode($addFoods);

		$this->tableFoodOrders->dbUpdateRec($update);
		$this->tableFoodOrders->docsLog($this->orderNdx);

		// -- close wizard
		$this->stepResult ['close'] = 1;
	}

	function loadInfo()
	{
		$this->tableFoodOrders = $this->app()->table('e10pro.canteen.foodOrders');
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->tableCanteens = $this->app()->table('e10pro.canteen.canteens');

		$this->recDataOrder = $this->tableFoodOrders->loadItem($this->orderNdx);
		$this->canteenCfg = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->recDataOrder['canteen']);

		$this->recDataPerson = $this->tablePersons->loadItem($this->recDataOrder['personOrder']);
	}

	function loadFoods()
	{
		$enum = [];
		$enum [0] = 'Bez oběda';

		$q = [];
		array_push($q, 'SELECT * FROM [e10pro_canteen_menuFoods]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND canteen = %i', $this->recDataOrder['canteen']);
		array_push($q, ' AND [menu] = %i', $this->recDataOrder['menu']);
		array_push($q, ' AND [date] = %d', $this->recDataOrder['date']);
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' ORDER BY foodIndex, ndx');

		$rows = $this->app()->db()->query($q);
		foreach ($rows as $r)
		{
			$enum[$r['ndx']] = $r['foodName'];
		}

		return $enum;
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'icon-user-circle-o';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Upravit objednávku jídla'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $this->recDataPerson['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => utils::datef($this->recDataOrder['date'], '%d')];

		return $hdr;
	}
}
