<?php


namespace wkf\core\widgets;

use \e10\widgetBoard, \wkf\base\TableSections;

/**
 * Class Dashboard
 * @package wkf\core\widgets
 */
class DashboardForum extends \wkf\core\widgets\Dashboard
{
	function createTabs ()
	{
		$this->help = 'prirucka/4884';

		parent::createTabs();

		$logo = $this->app->cfgItem ('appSkeleton.logo', '');
		if ($logo !== '')
			$this->toolbar['logo'] = $logo;
	}
}
