<?php

namespace e10pro\canteen\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class ServingFoodQueueWidget
 * @package e10pro\canteen\dataView
 */
class ServingFoodQueueWidget extends DataView
{
	var $today;
	var $canteenNdx = 1;
	var $canteen = NULL;


	protected function init()
	{
		$this->classId = 'e10pro.canteen.dataView.ServingFoodQueueWidget';
		$this->remoteElementId = 'e10pro-canteen-ServingFoodQueueWidget';

		$this->requestParams['showAs'] = 'webAppWidget';

		$this->today = utils::today();

		parent::init();
	}

	protected function loadData()
	{
		$this->loadDataQueue();
	}

	protected function loadDataQueue()
	{
		$this->data['front'] = [];

		$q [] = 'SELECT [orders].*, personsOrder.fullName AS personOrderName,';
		array_push($q, ' foods.foodName AS foodName, foods.foodIndex AS foodIndex');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10pro_canteen_menuFoods AS foods ON orders.food = foods.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [takeInProgress] = %i',1);
		array_push($q, ' AND [orders].[food] != %i',0);
		array_push($q, ' AND [orders].[canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [orders].[date] = %d', $this->today);
		array_push($q, ' AND [orders].[docState] != %i', 9800);
		array_push($q, ' ORDER BY [takeInProgressDateTime] DESC, [ndx]');
		$rows = $this->db()->query($q);
		$cnt = 0;
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$this->data['front'][] = $item;

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
		$c .= "<div class='row'><div class='col-12 e10-display-panel-title e10-display-cm-panelTitle'><i class='fa fa-angle-right'></i> ".utils::es('Jídlo k výdeji').'</div></div>';

		foreach ($this->data['front'] as $foodNdx => $food)
		{
			$c .= "<div class='row e10-display-row'>";
				$c .= "<div class='col-2 e10-display-row-handle e10-display-cm-rowHandle'>"."<span class='e10-display-panel-h2'>".utils::es($food['foodIndex']).'</span>'.'</div>';
				$c .= "<div class='col-8 e10-display-row-content e10-display-cm-rowContent'>";
					$c .= '<div class="e10-display-cm-rowTitle">'.utils::es($food['personOrderName']).'</div>';
					$c .= '<small>'.utils::es($food['foodName']).'</small>';
				$c .= '</div>';

				$c .= "<div class='col-2 e10-display-row-handle e10-display-cm-panelSubTitle e10-web-action-call' data-web-action='e10-pro-canteen-take-order' data-web-action-order-ndx='{$food['ndx']}' data-web-action-order-state='1'>";
					$c .= "<span class='e10-display-panel-h3'>";
					$c .= "<i class='fa fa-check-circle'></i> ";
					$c .= '</span><br>Hotovo';
				$c .= '</div>';

			$c .= '</div>';
		}

		$c .= '</div>';

		$c .= '<script>setTimeout (function(){callWebAction({"action": "e10-pro-canteen-check-queue", "canteen": 1})}, 60000);</script>';

		return $c;
	}
}
