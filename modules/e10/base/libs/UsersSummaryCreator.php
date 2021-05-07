<?php


namespace e10\base\libs;

use \e10\Utility;


/**
 * Class UsersSummaryCreator
 * @package e10\base\libs
 */
class UsersSummaryCreator extends Utility
{
	public function run()
	{
		$e = new \wkf\core\libs\UsersSummaryCreator($this->app());
		$e->run();
	}
}

