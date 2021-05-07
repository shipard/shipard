<?php

namespace demo\documents\libs;
use \e10doc\core\libs\E10Utils;
use \Shipard\Utils\Utils;


/**
 * Class YearOpen
 * @package lib\demo
 */
class YearOpen extends \demo\core\libs\Task
{
	public function save()
	{
		$todayDate = Utils::today();
		$fiscalYear = E10Utils::todayFiscalYear($this->app, $todayDate);

		// -- close previous
		/*
		$eng = new OpenClosePeriodEngine ($app);
		$eng->closeDocs = TRUE;
		$eng->setParams($params['fiscalYear'], FALSE);
		$eng->run();
		 */

		// -- open balances
		$eng = new \e10doc\cmnbkp\libs\InitStatesBalanceEngine ($this->app());
		$eng->setParams($fiscalYear);
		$eng->closeDocs = TRUE;
		$eng->run();
		unset ($eng);

		// -- open others
		/*
		$eng = new OpenClosePeriodEngine ($app);
		$eng->closeDocs = TRUE;
		$eng->setParams($params['fiscalYear'], TRUE);
		$eng->run();
		 */
	}

	public function run()
	{
		$this->save();
		return TRUE;
	}
}
