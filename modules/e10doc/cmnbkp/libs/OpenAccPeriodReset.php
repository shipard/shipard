<?php

namespace e10doc\cmnbkp\libs;


/**
 * Class OpenAccPeriodReset
 * @package e10doc\cmnbkp\libs
 */
class OpenAccPeriodReset extends \E10\Utility
{
	var $fiscalYear;

	protected function removeExistedDocuments()
	{
		$dbCounter = $this->dbCounter();
		if (!$dbCounter)
		{
			error_log("ERROR - OpenAccPeriodReset: dbCounter not found.");
			return FALSE;
		}

		$eraser = new \e10doc\core\libs\DocEraser($this->app());

		$q = [];
		array_push($q, 'SELECT * FROM [e10doc_core_heads]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [dbCounter] = %i', $dbCounter);
		array_push($q, ' AND [fiscalYear] = %i', $this->fiscalYear);
		array_push($q, ' ORDER BY [docNumber] DESC, ndx DESC');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$eraser->eraseDocument($r['ndx']);
		}
	}

	protected function dbCounter()
	{
		$dbCounter = $this->db()->query('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s', 'cmnbkp',
			' AND [docKeyId] = %s', '9', ' AND docState != %i', 9800)->fetch();
		if (!isset ($dbCounter['ndx']))
			return 0;

		return $dbCounter['ndx'];
	}

	public function run()
	{
		$this->removeExistedDocuments();

		// -- balances
		$eng = new \e10doc\cmnbkp\libs\InitStatesBalanceEngine ($this->app);
		$eng->setParams($this->fiscalYear);
		$eng->closeDocs = TRUE;
		$eng->run();

		// -- other
		$eng = new \e10doc\cmnbkp\libs\OpenClosePeriodEngine ($this->app);
		$eng->setParams($this->fiscalYear, TRUE);
		$eng->closeDocs = TRUE;
		$eng->run();
	}
}
