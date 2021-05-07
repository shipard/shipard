<?php

namespace lib\dashboards\widgets;


/**
 * Class DailyInfo
 * @package lib\dashboards\widgets
 */
class DailyInfo extends \e10\widgetPane
{
	public function createContent ()
	{
		$infoClass = $this->app->testGetParam ('infoClass');

		$info = [];
		$o = $this->app->createObject ($infoClass);
		if (!$o)
			return;
		$o->dailySummary($info);
		unset ($o);

		foreach ($info as $i)
		{
			//$paneClass = isset ($i['paneClass']) ? $i['paneClass'] : 'e10-fx-3-xl';
			//$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col pa1 e10-doc-list '.$paneClass]);
			if (isset ($i['title']))
				$this->addContent(['type' => 'line', 'line' => $i['title'], 'openCell' => 'e10-doc-list-title', 'closeCell' => 1]);
			$class = '';
			if (isset ($i['class']))
				$class = ' '.$i['class'];
			if (isset ($i['content']))
			{
				foreach ($i['content'] as $cp)
				{
					$this->addContent(['type' => 'line', 'line' => $cp, 'openCell' => 'e10-doc-list-item' . $class, 'closeCell' => 1]);
				}
			}
			elseif (isset($i['table']))
			{
				$this->addContent([
					'type' => 'table', 'table' => $i['table'], 'header' => $i['header'],
					'params' => ['hideHeader' => 1, 'forceTableClass' => 'fullWidth compact'],
					'openCell' => 'e10-doc-list-table' . $class, 'closeCell' => 1
				]);

			}
		}
	}

	public function title()
	{
		return FALSE;
	}
}
