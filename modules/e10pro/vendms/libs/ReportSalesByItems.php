<?php
namespace e10pro\vendms\libs;


use \e10doc\core\libs\E10Utils, \E10\uiutils, \E10\utils;


/**
 * class ReportSalesByItems
 */
class ReportSalesByItems extends \e10doc\core\libs\reports\GlobalReport
{
	var $units;
	var $currencies;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['enableAll', 'quarters', 'halfs', 'years'], 'defaultValue' => E10Utils::todayFiscalMonth($this->app)]);

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->setInfo('icon', 'e10doc-sale/customers');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		//$this->setInfo('note', '1', 'Všechny částky jsou včetně DPH');
	}

	function createContent ()
	{
		$this->createContent_Amount ();
	}

	function createCoreData ()
	{
    $q = [];

		array_push($q, 'SELECT items.id as itemId, items.fullName AS itemName,');
		array_push($q, ' heads.homeCurrency AS homeCurrency,');
		array_push($q, ' [rows].item, [rows].unit as rowUnit, SUM([rows].quantity) AS quantity, ');
		array_push($q, ' SUM([rows].taxBaseHc) AS price');
		array_push($q, ' FROM e10doc_core_rows AS [rows]');
		array_push($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push($q, ' WHERE heads.docState = 4000 ');

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);

		array_push($q, ' AND heads.docType IN %in', ['invno', 'cashreg']);
    array_push($q, ' GROUP BY [rows].item, heads.homeCurrency');

		if ($this->subReportId === 'quantity')
    	array_push($q, ' ORDER BY quantity DESC, itemName');
		elseif ($this->subReportId === 'amount')
    	array_push($q, ' ORDER BY price DESC, itemName');
		else
		array_push($q, ' ORDER BY itemName');

		$rows = $this->app->db()->query($q);

		$data = [];

		forEach ($rows as $r)
		{
			$newItem = $r->toArray();
			//if ($r ['person'] != 0)
				$newItem ['itemId'] = ['text'=> $r ['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $r ['item']];

			$data[] = $newItem;
		}

		return $data;
	}

	function createContent_Amount ()
	{
		$data = $this->createCoreData(TRUE);

		$h = [
      '#' => '#',
      'itemId' => ' id',
      'itemName' => 'Název položky',
      'quantity' => '+Počet',
      'price' => '+Cena celkem',
      'homeCurrency' => 'Měna',
    ];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Prodeje podle položek');
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'abc', 'icon' => 'detailReportAlphabetically', 'title' => 'Abecedně'];
		$d[] = ['id' => 'amount', 'icon' => 'detailReportFinancially', 'title' => 'Finančně'];
		$d[] = ['id' => 'quantity', 'icon' => 'detailReportTop', 'title' => 'Dle množství'];

		return $d;
	}
}

