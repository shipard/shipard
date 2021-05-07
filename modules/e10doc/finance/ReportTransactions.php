<?php

namespace E10Doc\Finance;

/**
 * Class ReportTransactions
 * @package E10Doc\Finance
 */
class ReportTransactions extends \e10doc\core\libs\reports\GlobalReport
{
	function createContent ()
	{
		$this->addContent (['type' => 'viewer', 'table' => 'e10doc.finance.transactions', 'viewer' => 'e10doc.finance.ViewTransactions', 'params' => []]);
	}

	public function createToolbar () {return [];}
}
