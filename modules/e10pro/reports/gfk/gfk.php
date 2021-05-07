<?php

namespace E10Pro\Reports\Gfk;

use \E10\utils, E10Doc\Core\e10utils;


function createWeeksPeriodCombo ($app, $weekYear, $weekNumber)
{
	$periods = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] ORDER BY [globalOrder] DESC")->fetchAll ();

	$c = '';
	$c .= \E10\es ('Rok') . ': ';

	$c .= "<select name='weekYear'>";
	for ($y = 2012; $y < 2018; $y++)
	{
    $c .= "<option value='$y'" . ($y == $weekYear ? " selected='selected'" : '') . '>' . $y . '</option>';
	}
	$c .= '</select>';

	$c .= \E10\es (' Týden') . ': ';

	$c .= "<select name='weekNumber'>";
	for ($y = 1; $y < 54; $y++)
	{
		$wn = $y . ' (' . utils::weekDate ($y, $weekYear, 1, 'd.m.') . ' - ' . utils::weekDate ($y, $weekYear, 7, 'd.m.') . ')';
    $c .= "<option value='$y'" . ($y == $weekNumber ? " selected='selected'" : '') . '>' . $wn . '</option>';
	}
	$c .= '</select>';

	return $c;
}


/**
 * reportGfk
 *
 */

class reportGfk extends \E10\GlobalReport
{
	public $weekNumber = 0;
	public $weekYear = 0;

	function init ()
	{
		parent::init();
		$this->weekNumber = intval ($this->app->testGetParam ('weekNumber'));
		if (!$this->weekNumber)
			$this->weekNumber = strftime ('%V');
		$this->weekYear = intval ($this->app->testGetParam ('weekYear'));
		if (!$this->weekYear)
			$this->weekYear = strftime ('%G');
	}

	function createReportContent ()
	{
		$c = '';
		$c .= $this->createReportContent_1 ();
		return $c;
	}

	function createReportContent_1 ()
	{
		$dateFrom = utils::weekDate ($this->weekNumber, $this->weekYear, 1);
		$dateTo = utils::weekDate ($this->weekNumber, $this->weekYear, 7);

		$q = "SELECT rows.item as item, rows.invDirection as invDirection, items.`type` as itemType, items.brand as itemBrand, brands.shortName as brandName, types.fullName as typeName,
									items.fullName as fullName, SUM(rows.priceTotal) as priceTotal, SUM(rows.quantity) as quantity
					FROM e10doc_core_rows as rows
					LEFT JOIN e10doc_core_heads AS heads ON rows.document = heads.ndx
					LEFT JOIN e10_witems_items AS items ON rows.item = items.ndx
					LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx
					LEFT JOIN e10_witems_itemtypes AS types ON items.`type` = types.id
					WHERE heads.dateAccounting >= %d AND heads.dateAccounting <= %d AND heads.docState = 4000 AND docType IN ('cashreg', 'invno', 'stockin') AND rows.invDirection <> 0 AND heads.initState = 0
					GROUP BY rows.item, rows.invDirection";
		$rows = $this->app->db()->query($q, $dateFrom, $dateTo);

		$data = array ();

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			if (!isset ($data[$itemNdx]))
			{
				$data [$itemNdx] = array ('ndx' => array ('text'=> $itemNdx, 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
						'ean' => '',
						'brand' => $r['brandName'], 'name' => $r['fullName'],
						'type' => $r['typeName'], 'stock' => 0,
						'buy' => 0, 'sale' => 0, 'price' => 0);
				if ($r['itemType'] == 'DRS')
					$data [$itemNdx]['type'] = '';
				// -- search ean
				$eans = $this->app->db()->query ("SELECT * FROM [e10_base_properties] WHERE [property] = 'ean' AND tableid = 'e10.witems.items' AND recid = $itemNdx")->fetch();
				if ($eans)
					$data [$itemNdx]['ean'] = $eans ['valueString'];

				// stock
				$fiscalYear = e10utils::todayFiscalYear($this->app, $dateFrom);
				$qq = "SELECT SUM(quantity) as quantity FROM [e10doc_inventory_journal] WHERE [item] = $itemNdx AND [fiscalYear] = $fiscalYear AND [date] <= '$dateFrom' GROUP BY item";
				$stock = $this->app->db()->query ($qq)->fetch();
				if ($stock)
					$data [$itemNdx]['stock'] = intval ($stock ['quantity']);
			}

			if ($r['invDirection'] == -1)
			{ // sale
				$data [$itemNdx]['sale'] = intval ($r['quantity']);
				if ($r['quantity'] != 0)
					$data [$itemNdx]['price'] = round($r['priceTotal']/$r['quantity'], 2);
			}
			else
			{ // buy
				$data [$itemNdx]['buy'] = intval ($r['quantity']);
			}
		}

		//$data [] = $total;

		$c = '';
		$c .= "<div class='e10-reportContent'>";

		//$fiscalPeriod = fiscalPeriod ($this->app, $this->fiscalMonth);
		//$c .= "<h1>Obraty zboží/služby za období ".$fiscalPeriod['calendarYear'].' / '.$fiscalPeriod['calendarMonth'].'</h1>';

		$h = array ('ndx' => ' Kód', 'ean' => 'EAN', 'brand' => 'Výrobce', 'name' => 'Název výrobku', 'type' => 'Produktová skupina',
								'stock' => ' Stav v ks', 'buy' => ' Nákup v ks', 'sale' => ' Prodej v ks', 'price' => ' Cena za ks');

		$params = array ('tableClass' => 'e10-vd-mainTable');
		$c .= \E10\renderTableFromArray ($data, $h, $params);


		$csv = '';
		$csv .= "EAN\tVýrobce\tNázev\tProduktová skupina\tSkladem ks\tNákup ks\tProdej ks\tCena za ks\n";
		forEach ($data as $r)
		{
			$csv .= "{$r['ean']}\t{$r['brand']}\t{$r['name']}\t{$r['type']}\t{$r['stock']}\t{$r['buy']}\t{$r['sale']}\t{$r['price']}\n";
		}
		file_put_contents(__APP_DIR__."/tmp/gfk_fotocz_{$dateFrom}_{$dateTo}.csv", $csv);

		$c .= "</div>";


		return $c;
	}

	public function createToolbarCode ()
	{
		$c = createWeeksPeriodCombo ($this->app, $this->weekYear, $this->weekNumber);
		$c .= parent::createToolbarCode ();
		return $c;
	}
} // class reportGfk


