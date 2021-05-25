<?php

namespace lib\cacheItems;


use e10doc\core\libs\E10Utils;


/**
 * Class CashStates
 * @package lib\cacheItems
 */
class CashStates extends \Shipard\Base\CacheItem
{
	function createData()
	{
		$cashBoxes = $this->app->cfgItem ('e10doc.cashBoxes', FALSE);
		if ($cashBoxes === FALSE)
			return;

		$this->data['totalHc'] = 0.0;

		foreach ($cashBoxes as $cbNdx => $cbCfg)
		{
			if ($cbCfg['efd'])
				continue;

			$q [] = 'SELECT * FROM e10doc_core_heads';
			array_push($q, ' WHERE docType IN %in', ['invni', 'invno', 'cash', 'cashreg', 'purchase'],
					' AND cashBox = %i', $cbNdx, ' AND totalCash != 0',
					' AND docState = %i', 4000);
			array_push($q, ' ORDER BY dateAccounting DESC, ndx DESC');
			array_push($q, ' LIMIT 0, 1');

			$lastDoc = $this->app->db()->query ($q)->fetch();
			unset ($q);

			if (!$lastDoc)
				continue;

			$balance = E10Utils::getCashBoxInitState ($this->app, $cbNdx, new \DateTime(date('Ymd', strtotime('+1 day'))), $lastDoc['fiscalYear']);

			$currId = $cbCfg['curr'];
			$this->data['oneCurrId'] = $currId;
			$currShortcut = $this->app->cfgItem ('e10.base.currencies.'.$currId.'.shortcut');

			$cashBoxBalance = [
					'title' => $cbCfg['shortName'], 'curr' => $currShortcut, 'balance' => $balance,
					'date' => $lastDoc['dateAccounting']->format('Y-m-d')
			];
			$this->data['cashBoxes'][] = $cashBoxBalance;

			if (!isset ($this->data['sums'][$currId]))
				$this->data['sums'][$currId] = ['balance' => 0.0, 'curr' => $currShortcut];
			$this->data['sums'][$currId]['balance'] += $balance;

			$this->data['totalHc'] += $balance;

			$this->data['sums'][$currId]['date'] = $lastDoc['dateAccounting']->format('Y-m-d');
		}

		parent::createData();
	}
}
