<?php

namespace e10pro\canteen\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class ServingFoodStatsWidget
 * @package e10pro\canteen\dataView
 */
class ServingFoodStatsWidget extends DataView
{
	var $today;
	var $canteenNdx = 1;
	var $canteen = NULL;

	protected function init()
	{
		$this->classId = 'e10pro.canteen.dataView.ServingFoodStatsWidget';
		$this->remoteElementId = 'e10pro-canteen-ServingFoodStatsWidget';

		$this->requestParams['showAs'] = 'webAppWidget';

		$this->today = utils::today();

		parent::init();
	}

	protected function loadData()
	{
		// -- menus & foods
		$this->loadDataFoods();

		// -- foods stats
		$this->data['counts'] = [];
		$this->loadDataCount('all', NULL);
		$this->loadDataCount('takeDone', [' AND [takeDone] = %i', 1]);
		$this->loadDataCount('takeRest', [' AND [takeDone] = %i', 0]);
	}

	protected function loadDataCount($countId, $countQuery)
	{
		$q [] = 'SELECT [food], COUNT(*) AS cnt FROM [e10pro_canteen_foodOrders]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [food] != %i',0);
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [date] = %d', $this->today);
		array_push($q, ' AND [docState] != %i', 9800);

		if ($countQuery)
			$q = array_merge($q, $countQuery);

		array_push($q, ' GROUP BY [food]');


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->data['counts'][$r['food']][$countId] = $r['cnt'];
		}
	}

	protected function loadDataFoods()
	{
		// -- menu
		$q[] = 'SELECT * FROM [e10pro_canteen_menus]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND ([dateFrom] <= %d', $this->today, ' AND [dateTo] >= %d', $this->today, ')');
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' ORDER BY [fullName]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$item['foods'] = [];
			$this->data['menus'][$r['ndx']] = $item;
		}

		if (!count($this->data['menus']))
			return;

		// -- foods
		$q = [];
		$q[] = 'SELECT * FROM [e10pro_canteen_menuFoods]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [canteen] = %i', $this->canteenNdx);
		array_push($q, ' AND [menu] IN %in', array_keys($this->data['menus']));
		array_push($q, ' AND [date] = %d', $this->today);
		array_push($q, ' ORDER BY [foodIndex]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$this->data['menus'][$r['menu']]['foods'][$r['ndx']] = $item;
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

		//$c .= json_encode($this->data['counts']);

		$c .= "<div class='e10-display-panel-middle'>";
		$c .= "<div class='row'><div class='col-12 e10-display-panel-title e10-display-cm-panelTitle'><i class='fa fa-angle-right'></i> ".utils::es('Odebraná / zbývající jídla').'</div></div>';

		foreach ($this->data['menus'] as $menuNdx => $menu)
		{
			foreach ($menu['foods'] as $foodNdx => $food)
			{
				if ($food['notCooking'])
					continue;

				$takeDone = (isset($this->data['counts'][$foodNdx]['takeDone'])) ? $this->data['counts'][$foodNdx]['takeDone'] : 0;
				$takeRest = (isset($this->data['counts'][$foodNdx]['takeRest'])) ? $this->data['counts'][$foodNdx]['takeRest'] : 0;

				$c .= "<div class='row'>";
					$c .= "<div class='col-2 e10-display-panel-handle e10-display-cm-panelHandle'>";
						$c .= "<span class='e10-display-panel-h3'>".utils::es($food['foodIndex']).'</span>';
					$c .= '</div>';
					$c .= "<div class='col-10 e10-display-panel-subTitle e10-display-cm-panelSubTitle'><span class='d-flex align-items-end h-100 pb-3'>".utils::es($food['foodName']).'</span></div>';
				$c .= '</div>';

				$c .= "<div class='row' style='border-bottom: 4px solid transparent;'>";
				$c .= "<div class='col-2 e10-display-panel-handle e10-display-cm-panelHandle'>".'</div>';
					$c .= "<div class='col-5 text-center e10-display-cm-done'>";
						$c .= "<span class='e10-display-panel-h1'>".$takeDone.'</span>';
					$c .= '</div>';
					$c .= "<div class='col-5 text-center e10-display-cm-rest'>";
						$c .= "<span class='e10-display-panel-h1'>".$takeRest.'</span>';
					$c .= '</div>';
				$c .= '</div>';
			}
		}

		$c .= '</div>';

		return $c;
	}
}
