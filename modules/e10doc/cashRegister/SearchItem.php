<?php

namespace e10doc\cashRegister;
require_once __APP_DIR__ . '/e10-modules/e10doc/core/core.php';


use e10\Utility, e10doc\core\e10utils;


/**
 * Class SearchItem
 * @package e10doc\cashRegister
 */
class SearchItem extends Utility
{
	var $units;

	public $result = ['success' => 0];


	protected function search ()
	{
		$symbol = $this->app->requestPath(4);
		$today = new \DateTime();

		$sql = 'SELECT * FROM [e10_base_properties] props LEFT JOIN e10_witems_items items ON props.recid = items.ndx where [tableid] = %s AND property = %s AND valueString = %s AND items.docStateMain != 4';
		$witemEan = $this->db()->query ($sql, 'e10.witems.items', 'ean', $symbol)->fetch ();
		if (!$witemEan)
			return;

		$r = $this->app->loadItem ($witemEan['recid'], 'e10.witems.items');
		if (!$r)
			return;

		$askQuantity = 0;
		if ($r['defaultUnit'] !== 'pcs')
			$askQuantity = 1;
		$taxCalc = intval($this->app->cfgItem ('options.e10doc-sale.cashRegSalePricesType', e10utils::taxCalcIncludingVATCode($this->app(), $today)));
		$this->result ['item'] = [
				'title' => $r['shortName'], 'name' => $r['fullName'], 'pk' => $r['ndx'],
				'price' => e10utils::itemPriceSell($this->app, $taxCalc, $r),
				'unit' => $r['defaultUnit'], 'unitName' => $this->units[$r['defaultUnit']]['shortcut'],
				'askq' => $askQuantity
		];

		$this->result ['success'] = 1;
	}

	public function run ()
	{
		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->search();
	}
}
