<?php

namespace services\money\documentCards;
use \e10\world, \e10\utils;


/**
 * Class ExchangeRatesList
 * @package services\money\documentCards
 */
class ExchangeRatesList extends \e10\DocumentCard
{
	function createContentBody ()
	{
		$this->createContentBody_Values();
	}

	function createContentBody_Values ()
	{
		$t = [];
		$h = [
			'#' => '#', 'currency' => 'Měna',
			'cntUnits' => ' Poč.jed.', 'exchangeRate' => ' Kurz', 'exchangeRateOneUnit' => ' Kurz/jedn.',
			'currencyName' => 'Název měny',
		];

		$q[] = 'SELECT * FROM [services_money_exchangeRatesValues]';
		array_push ($q, ' WHERE [list] = %i', $this->recData['ndx']);
		array_push ($q, ' ORDER by ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$currency = world::currency($this->app(), $r['currency']);

			$item = [
				'currency' => strtoupper($currency['i']), 'currencyName' => $currency['t'], 'cntUnits' => $r['cntUnits'],
				'exchangeRate' => utils::nf($r['exchangeRate'], 3), 'exchangeRateOneUnit' => utils::nf($r['exchangeRateOneUnit'], 7),
				];

			$t[] = $item;
		}

		$this->addContent('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $t, 'header' => $h,
			'params' => []
		]);

	}

	public function createContent ()
	{
		$this->newMode = 1;
		$this->createContentBody ();
	}
}

