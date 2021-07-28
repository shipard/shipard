<?php

namespace e10pro\canteen\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class ServingFoodOrdersWidget
 * @package e10pro\canteen\dataView
 */
class ServingFoodOrdersWidget extends DataView
{
	var $today;
	var $canteenNdx = 1;
	var $canteen = NULL;

	var $paramTaking = FALSE;

	var $foodTakings;

	protected function init()
	{
		$this->classId = 'e10pro.canteen.dataView.ServingFoodOrdersWidget';
		$this->remoteElementId = 'e10pro-canteen-ServingFoodOrdersWidget';

		$this->requestParams['showAs'] = 'webAppWidget';

		$this->checkRequestParamsList('taking', TRUE);
		$this->paramTaking = $this->requestParam('taking', FALSE);

		$this->today = utils::today();

		$this->foodTakings = $this->app()->cfgItem ('e10pro.canteen.foodTakings');

		parent::init();
	}

	protected function loadData()
	{
		$this->loadDataFoods();
	}

	protected function loadDataFoods()
	{
		$this->data['orders'] = [];

		$q [] = 'SELECT [orders].*, personsOrder.fullName AS personOrderName,';
		array_push($q, ' foods.foodName AS foodName, foods.foodIndex AS foodIndex');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10pro_canteen_menuFoods AS foods ON orders.food = foods.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' WHERE 1');
		//array_push($q, ' AND [takeInProgress] = %i',1);

		if ($this->paramTaking !== FALSE)
			array_push($q, ' AND [taking] IN %in', $this->paramTaking);

		array_push($q, ' AND [orders].[food] != %i',0);
		array_push($q, ' AND [orders].[canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [orders].[date] = %d', $this->today);
		array_push($q, ' AND [orders].[docState] != %i', 9800);
		//array_push($q, ' ORDER BY [orders].[takeDone] DESC, [ndx]');
		array_push($q, ' ORDER BY personsOrder.lastName, personsOrder.firstName, [ndx]');
		$rows = $this->db()->query($q);
		$cnt = 0;
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$this->data['orders'][] = $item;

			$cnt++;
		}
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'webAppWidget')
			return $this->renderDataAsWidget();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsWidget()
	{
		$c = '';
		$c .= "<div class='e10-display-panel-middle'>";
		$c .= "<div class='row'><div class='col-12 e10-display-panel-title e10-display-cm-panelTitle'><i class='fa fa-angle-right'></i> ".utils::es('Jídla do krabičky').'</div></div>';

		foreach ($this->data['orders'] as $foodNdx => $food)
		{
			$classHandle = 'e10-display-cm-rest';
			if ($food['takeDone'])
				$classHandle = 'e10-display-cm-done';

			$c .= "<div class='row e10-display-row'>";
				$c .= "<div class='col-2 e10-display-row-handle $classHandle'>"."<span class='e10-display-panel-h3'>".utils::es($food['foodIndex']).'</span>'.'</div>';
				$c .= "<div class='col-8 e10-display-row-content e10-display-cm-rowContent'>";
					$c .= '<div class="e10-display-cm-rowTitle">'.utils::es($food['personOrderName']).'</div>';
					$c .= '<small>'.utils::es($food['foodName']).'</small>';
					$ft = $this->foodTakings[$food['taking']];
					$c .= "&nbsp; &nbsp;<span class='badge bg-info pull-right'>".utils::es($ft['name']).'</span>';
				$c .= '</div>';

				if ($food['takeInProgress'] === 1)
				{
					$c .= "<div class='col-2 e10-display-row-handle e10-display-cm-panelSubTitle'>";
					$c .= "<span class='e10-display-panel-h4'>";
					$c .= "<i class='fa fa-bolt'></i> ";
					$c .= '</span><br>Vydává se';
					$c .= '</div>';
				}
				elseif ($food['takeDone'] === 0)
				{
					$c .= "<div class='col-2 e10-display-row-handle e10-display-cm-panelSubTitle e10-web-action-call' data-web-action='e10-pro-canteen-take-order' data-web-action-order-ndx='{$food['ndx']}' data-web-action-order-state='1'>";
						$c .= "<span class='e10-display-panel-h4'>";
						$c .= "<i class='fa fa-check-circle'></i> ";
						$c .= '</span><br>Vydat';
					$c .= '</div>';
				}
				elseif ($food['takeDone'] === 1)
				{
					$c .= "<div class='col-2 e10-display-row-handle e10-display-cm-panelSubTitle e10-web-action-call' data-web-action='e10-pro-canteen-take-order' data-web-action-order-ndx='{$food['ndx']}' data-web-action-order-state='2'>";
						$c .= "<span class='e10-display-panel-h4'>";
						$c .= "<i class='fa fa-times'></i> ";
						$c .= '</span><br>Zpět';
					$c .= '</div>';
				}
			$c .= '</div>';
		}

		$c .= '</div>';

		return $c;
	}
}
