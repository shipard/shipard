<?php

namespace e10pro\zus;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use e10\utils, e10\str;


/**
 * Class WidgetPlanTeacherSmall
 * @package e10pro\zus
 */
class WidgetPlanTeacherSmall extends \e10pro\zus\WidgetPlanTeacher
{
	public function createContent()
	{
		$this->planMode = 'today';
		parent::createContent();
	}
}
