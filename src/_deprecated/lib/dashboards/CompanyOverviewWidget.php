<?php

namespace lib\dashboards;


/**
 * Class CompanyOverviewWidget
 * @package lib\dashboards
 */
class CompanyOverviewWidget extends \Shipard\UI\Core\WidgetPane
{
	public function createContent ()
	{
		$o = new \lib\dashboards\CompanyOverview($this->app);
		$o->run();

		$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $o->code]);
	}

	public function title()
	{
		return FALSE;
	}
}
