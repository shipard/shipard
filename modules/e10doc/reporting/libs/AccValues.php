<?php

namespace e10doc\reporting\libs;


/**
 * class AccValues
 */
class AccValues extends \Shipard\Base\Utility
{
  var $queryParams = [];

  public function setQueryParam($paramId, $paramValue)
  {
    $this->queryParams[$paramId] = $paramValue;
  }

  function loadAccountSum($accountId)
	{
    $resData = ['sumAmount' => 0.0, 'sumDr' => 0.0, 'sumCr' => 0.0];

		$q[] = 'SELECT journal.accountId, SUM(journal.money) as sumAmount, SUM(journal.moneyDr) as sumDr, SUM(journal.moneyCr) as sumCr';
		array_push ($q, ' FROM e10doc_debs_journal AS journal ');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND journal.[accountId] = %s', $accountId);

		$this->applyQueryParams($q);

		$rows = $this->app->db()->query($q);
		forEach ($rows as $acc)
		{
			$resData['sumAmount'] += $acc['sumAmount'];
			$resData['sumCr'] += $acc['sumCr'];
			$resData['sumDr'] += $acc['sumDr'];
		}

    return $resData;
	}

	function applyQueryParams (&$q)
	{
		if (isset($this->queryParams['workOrder']))
			array_push ($q, ' AND journal.[workOrder] = %i', $this->queryParams['workOrder']);
		if (isset($this->queryParams['dateBegin']))
			array_push ($q, ' AND journal.[dateAccounting] >= %d', $this->queryParams['dateBegin']);
		if (isset($this->queryParams['dateEnd']))
			array_push ($q, ' AND journal.[dateAccounting] <= %d', $this->queryParams['dateEnd']);
	}
}
