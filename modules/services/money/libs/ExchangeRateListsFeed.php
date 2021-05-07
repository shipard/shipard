<?php

namespace services\money\libs;

use e10\utils, e10\Utility, e10\Response;


/**
 * Class ExchangeRateListsFeed
 * @package services\money\libs
 */
class ExchangeRateListsFeed extends Utility
{
	var $date = NULL;
	var $listTypeId = 0;
	var $object = [];

	public function init ()
	{
	}

	protected function checkParams()
	{
		$dateStr = $this->app->requestPath(2);
		if (utils::dateIsValid($dateStr))
			$this->date = utils::createDateTime($dateStr);

		$this->listTypeId = intval($this->app->requestPath(3));
	}

	protected function doIt()
	{
		if (!$this->listTypeId || !$this->date)
			return;

		$q[] = 'SELECT * FROM [services_money_exchangeRatesLists]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [validFrom] = %d', $this->date);
		array_push ($q, ' AND [listType] = %i', $this->listTypeId);
		array_push ($q, ' AND [docState] = %i', 4000);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$list = [
				'rec' => [
					'country' => $r['country'], 'currency' => $r['currency'], 'listNumber' => $r['listNumber'],
					'validFrom' => $r['validFrom']->format('Y-m-d'),
					'validTo' => ($r['validTo']) ? $r['validTo']->format('Y-m-d') : NULL,
					'listType' => $r['listType'], 'periodType' => $r['periodType'], 'rateType' => $r['rateType'],
				],
				'values' => []
			];

			$qr = [];
			array_push ($qr, 'SELECT * FROM [services_money_exchangeRatesValues]');
			array_push ($qr, ' WHERE [list] = %i', $r['ndx']);
			array_push ($qr, ' ORDER BY [ndx]');
			$listRows = $this->db()->query($qr);
			foreach ($listRows as $lr)
			{
				$list['values'][] = [
					'currency' => $lr['currency'],
					'cntUnits' => $lr['cntUnits'],
					'exchangeRate' => $lr['exchangeRate'],
					'exchangeRateOneUnit' => $lr['exchangeRateOneUnit'],
				];
			}
			$this->object['valid'] = 1;
			$this->object['data'] = $list;

			break;
		}
	}

	public function run ()
	{
		$this->object['valid'] = 0;

		$this->checkParams();
		$this->doIt();

		$response = new Response ($this->app);
		$response->add ('objectType', 'exchangeRatesList');
		$response->add ('object', $this->object);
		return $response;
	}
}
