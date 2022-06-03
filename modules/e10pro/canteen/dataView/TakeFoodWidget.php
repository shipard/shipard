<?php

namespace e10pro\canteen\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class TakeFoodWidget
 * @package e10pro\canteen\dataView
 */
class TakeFoodWidget extends DataView
{
	var $canteenNdx = 1;
	var $canteen = NULL;

	var $ordersToTake = [];
	var $warningNotCookingOrders = FALSE;

	protected function init()
	{
		$this->requestParams['showAs'] = 'webAppWidget';
		$this->canteen = $this->app()->cfgItem ('e10pro.canteen.canteens.'.$this->canteenNdx);

		parent::init();
	}

	protected function loadData()
	{
		// -- user info
		$this->data['userName'] = $this->app()->user()->data('name');

		$this->updateOrdersOld();

		// -- foods orders
		$this->loadDataFoods();
		$this->loadNotCookingOrders();

		// -- update orders
		$this->updateOrdersNew();
	}

	protected function loadDataFoods()
	{
		$userNdx = $this->app()->userNdx();
		$today = utils::today();

		$this->data['foods'] = [];
		$foodTakings = $this->app()->cfgItem ('e10pro.canteen.foodTakings');


		$q [] = 'SELECT [orders].*, personsOrder.fullName AS personOrderName,';
		array_push($q, ' foods.foodName AS foodName, foods.foodIndex AS foodIndex,');
		array_push($q, ' menus.fullName AS menuName');
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10_persons_persons AS personsOrder ON orders.personOrder = personsOrder.ndx');
		array_push($q, ' LEFT JOIN e10pro_canteen_menus AS menus ON orders.menu = menus.ndx');
		array_push($q, ' LEFT JOIN e10pro_canteen_menuFoods AS foods ON orders.food = foods.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [orders].[food] != %i',0);
		array_push($q, ' AND [orders].[canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [orders].[date] = %d', $today);
		array_push($q, ' AND [orders].[personOrder] = %i', $userNdx);
		array_push($q, ' AND [orders].[docState] != %i', 9800);
		array_push($q, ' ORDER BY [orderNumber], [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'foodIndex' => $r['foodIndex'],
				'foodName' => $r['foodName'],
				'takeInProgress' => $r['takeInProgress'], 'takeDone' => $r['takeDone'],
			];

			if ($r['taking'])
			{
				$ft = $foodTakings[$r['taking']];
				$item['takingName'] = $ft['name'];
			}
			else
			{

			}

			if (!$r['takeInProgress'] && !$r['takeDone'] && $r['taking'] === 0)
			{
				$this->ordersToTake[] = $r['ndx'];
			}

			$this->data['foods'][] = $item;
		}
	}

	protected function updateOrdersOld()
	{
		// old: takeInProgress --> takeDone
		$limit = new \DateTime('-1 minute');
		$update = ['takeInProgress' => 0, 'takeDone' => 1, 'takeDateTime' => new \DateTime(), 'orderState' => 2];
		$this->db()->query ('UPDATE [e10pro_canteen_foodOrders] SET ', $update,
			' WHERE [takeInProgress] = %i', 1, ' AND [takeInProgressDateTime] < %t', $limit);
	}

	protected function updateOrdersNew()
	{
		// new: in progress
		$update = ['takeInProgress' => 1, 'takeInProgressDateTime' => new \DateTime()];
		$this->db()->query ('UPDATE [e10pro_canteen_foodOrders] SET ', $update, ' WHERE [ndx] IN %in', $this->ordersToTake);
	}

	protected function loadNotCookingOrders()
	{
		$this->warningNotCookingOrders = FALSE;

		$userNdx = $this->app()->userNdx();
		$today = utils::today();

		$q [] = 'SELECT COUNT(*) AS cnt';
		array_push($q, ' FROM [e10pro_canteen_foodOrders] AS [orders]');
		array_push($q, ' LEFT JOIN e10pro_canteen_menuFoods AS foods ON orders.food = foods.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [orders].[food] != %i',0);
		array_push($q, ' AND [foods].[notCooking] = %i',1);
		array_push($q, ' AND [orders].[canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [orders].[date] > %d', $today);
		array_push($q, ' AND [orders].[personOrder] = %i', $userNdx);
		array_push($q, ' AND [orders].[docState] != %i', 9800);

		$exist = $this->db()->query($q)->fetch();
		if ($exist && $exist['cnt'])
			$this->warningNotCookingOrders = TRUE;
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

		$c .= "<div class='row'>";
			$c .= "<div class='col-2 e10-display-panel-handle e10-display-cm-panelHandle'>";
				$c .= "<span class='e10-display-panel-h3'><i class='fa fa-user-circle'></i>".'</span>';
			$c .= '</div>';
			$c .= "<div class='col-10 e10-display-panel-subTitle e10-display-cm-panelSubTitle e10-display-panel-h3'><span class='d-flex align-items-end h-100'>".utils::es($this->data['userName']).'</span></div>';
		$c .= '</div>';

		foreach ($this->data['foods'] as $food)
		{
			$handleClass = 'e10-display-cm-rowHandle';
			$rowClass = 'e10-display-cm-rowContent';

			if ($food['takeDone'])
				$handleClass = 'e10-display-cm-failed';

			$c .= "<div class='row e10-display-row'>";
				$c .= "<div class='col-2 e10-display-row-handle $handleClass'>"."<span class='e10-display-panel-h2'>".utils::es($food['foodIndex']).'</span>'.'</div>';
				$c .= "<div class='col-10 e10-display-row-content $rowClass'>";
					$c .= '<div class="e10-display-cm-rowTitle">'.utils::es($food['foodName']).'</div>';
					if (isset($food['takingName']))
						$c .= "<span><i class='fa fa-gift'></i> ".utils::es($food['takingName']).'</span>';

					if ($food['takeInProgress'])
						$c .= "<div>".utils::es('Jídlo se vydává...').'</div>';
					if ($food['takeDone'])
						$c .= "<div>".utils::es('Jídlo už jste si vzali...').'</div>';

					$c .= '</div>';
			$c .= '</div>';
		}

		if ($this->warningNotCookingOrders)
		{
			$c .= "<div class='row e10-display-row'>";
				$c .= "<div class='col-12 e10-display-row-handle e10-display-cm-failed pt-3'>"."<span class='e10-display-panel-h3'><i class='fa fa-exclamation-triangle'></i>".'</span>'.'</div>';
				$c .= "<div class='col-12 e10-display-row-content e10-display-cm-failed text-center e10-display-panel-h4'>".utils::es('Máte objednaná jídla, která se nebudou vařit. ').'</div>';
				$c .= "<div class='col-12 e10-display-row-content e10-display-cm-failed text-center pb-3'>".utils::es('Vyberte si prosím něco jiného...').'</div>';
			$c .= '</div>';
		}

		$c .= '</div>';

		$c .= '<script>
		setTimeout (function(){mqttPublishData(0, "/shpd/canteen-take-food/'.$this->canteenNdx.'", {"cmd": "reload-remote-element", "elements": ["e10pro-canteen-ServingFoodStatsWidget", "e10pro-canteen-ServingFoodQueueWidget"]})}, 250);
		</script>';

		$logoutTimeoutSec = $this->canteen['timeoutLogoutTakeTerminal'];
		if (!$logoutTimeoutSec)
			$logoutTimeoutSec = 30;

		$logoutTimeout = intval ($logoutTimeoutSec * 1000);
		$c .= '<script>setTimeout (function(){location.href = "'.$this->app()->urlRoot.'/user/logout-check";}, '.$logoutTimeout.');</script>';

		return $c;
	}
}


