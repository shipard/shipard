<?php


namespace lib\wkf;

require_once __APP_DIR__ . '/e10-modules/e10pro/wkf/wkf.php';



/**
 * Class WidgetForum
 * @package lib\wkf
 */
class WidgetIntranet extends \lib\wkf\WidgetStart
{
	function createTabs ()
	{
		parent::createTabs();

		$logo = $this->app->cfgItem ('appSkeleton.logo', '');
		if ($logo !== '')
			$this->toolbar['logo'] = $logo;
	}
}
