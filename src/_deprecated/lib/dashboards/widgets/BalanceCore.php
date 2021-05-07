<?php

namespace lib\dashboards\widgets;

use e10\utils;


/**
 * Class OverviewSales
 * @package lib\dashboards\widgets
 */
class BalanceCore extends \e10\widgetPane
{
	function createCodeMeter ($md)
	{
		$c = '';

		if ($md['total'])
		{
			$lastBg = 'transparent';
			$totalSize = 0.0;
			$parts = [];

			foreach ($md['parts'] as $p)
			{
				if (!$p['num'])
					continue;

				$thisSize = round($p['num'] / $md['total'] * 100, 1);
				$totalSize += $thisSize;

				$parts[] = ['size' => $thisSize, 'info' => $p];
				$lastBg = $p['color'];
			}

			if (count ($parts))
			{
				$c .= "<div class='meter' style='width: 100%; display: inline-flex; background-color: {$lastBg}; flex-basis: 100%; height: .8ex;'>";

				if ($totalSize < 100.0)
					$parts[0]['size'] += (100.0 - $totalSize);

				foreach ($parts as $p)
				{
					$title = utils::es($p['info']['title'].' - '.$p['size'].'%');
					$c .= "<span title='{$title}' style='height: .8ex; width: {$p['size']}%; background-color: {$p['info']['color']};'></span>";
				}
			}
		}

		if ($c === '')
			$c .= "<div class='meter' style='width: 100%; display: inline-flex; background-color: gray; flex-basis: 100%; height: .8ex;'>";

		$c .= '</div>';
		return $c;
	}

	public function createBalance ($title, $icon, $blockClass, $data)
	{
		$meterData = ['total' => $data['data']['sum']['rest'], 'parts' => [
			['num' => $data['data']['sum']['rest0'], 'color' => '#DFD', 'title' => 'Ve splatnosti'],
			['num' => $data['data']['sum']['rest1'], 'color' => '#FDD', 'title' => 'do 30 dnů po splatnosti'],
			['num' => $data['data']['sum']['rest2'], 'color' => '#FAA', 'title' => 'víc než 30 po splatnosti'],
		]
		];
		$meter = $this->createCodeMeter($meterData);
		$this->addContent(['type' => 'line', 'line' => ['code' => $meter]]);

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row']);

		$this->addContent(['type' => 'line', 'line' => ['text' => $title, 'icon' => $icon, 'class' => 'e10-widget-big-number'],
			'openCell' => 'e10-fx-col pa1']);
		$this->addContent(['type' => 'line', 'line' => ['text' => utils::nf ($data['data']['sum']['rest']), 'class' => 'e10-widget-big-text block'], 'closeCell' => 1]);

		$info = [
			['text' => utils::nf ($data['data']['sum']['rest0']), 'class' => 'block', 'prefix' => 'Ve splatnosti'],
			['text' => utils::nf ($data['data']['sum']['rest1']), 'class' => 'block', 'prefix' => ' < 30 dnů'],
			['text' => utils::nf ($data['data']['sum']['rest2']), 'class' => 'block', 'prefix' => ' > 30 dnů'],
		];

		$this->addContent(['type' => 'line', 'line' => $info, 'openCell' => 'e10-fx-col e10-fx-grow align-right pa1', 'closeCell' => 1]);

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}

	public function title()
	{
		return FALSE;
	}
}
