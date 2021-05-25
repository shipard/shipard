<?php

namespace lib\dashboards\widgets;

use e10\utils;


/**
 * Class DailyBar
 * @package lib\dashboards\widgets
 */
class DailyBar extends \Shipard\UI\Core\WidgetPane
{
	public function createContent ()
	{
		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-zebra-odd']);

		$today = utils::today();

		$info = [];
		$info['today'] = ['class' => '', 'content' => [['text' => utils::datef ($today, '%n %d'), 'icon' => 'icon-calendar']]];

		/*
		$info['exchangeRates'] = ['class' => '', 'content' => []];
		$info['exchangeRates']['content'][] = ['text' => 'EUR 27.01', 'icon' => 'icon-eur', 'class' => ''];
		$info['exchangeRates']['content'][] = ['text' => 'USD 25.12', 'icon' => 'icon-usd', 'class' => ''];
		*/

		$dailyBarCfg = $this->app->cfgItem ('e10.dashboardInfo.dailyBar', []);
		foreach ($dailyBarCfg as $dailyBarItem)
		{
			$o = $this->app->createObject ($dailyBarItem['class']);
			if (!$o)
				continue;
			$o->dailyBar($info);
			unset ($o);
		}

		foreach ($info as $i)
		{
			$class = '';
			if (isset ($i['class']))
				$class = ' '.$i['class'];

			$this->addContent(['type' => 'line', 'line' => $i['content'], 'openCell' => 'e10-fx-block nowrap pa1'.$class, 'closeCell' => 1]);
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}

	public function title()
	{
		return FALSE;
	}
}
