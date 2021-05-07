<?php

namespace E10Pro\Reports\Canon;

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
 * reportCanon
 *
 */

class reportCanon extends \E10\GlobalReport
{
	public $weekNumber = 0;
	public $weekYear = 0;

	protected $tableSales;
	protected $salesTitle;

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
		$c .= "<div class='e10-reportContent'>";
		$c .= $this->createReportContent_Sales ();
		$c .= $this->createReportContent_Inventory ();
		$c .= $this->createReportContent_Warehouses ();
		$c .= "</div>";
		return $c;
	}

	function createReportContent_Sales ()
	{
		$dateFrom = utils::weekDate ($this->weekNumber, $this->weekYear, 1);
		$dateTo = utils::weekDate ($this->weekNumber, $this->weekYear, 7);
		$dateFrom2 = utils::weekDate ($this->weekNumber, $this->weekYear, 1, 'Ymd');
		$dateTo2 = utils::weekDate ($this->weekNumber, $this->weekYear, 7, 'Ymd');

		$q = "SELECT rows.item as item, heads.docType as docType, rows.invDirection as invDirection, items.brand as itemBrand,
								 items.fullName as fullName, SUM(rows.quantity) as quantity, heads.dateAccounting as dateAccounting, heads.paymentMethod as paymentMethod
					FROM e10doc_core_rows as rows
					LEFT JOIN e10doc_core_heads AS heads ON rows.document = heads.ndx
					LEFT JOIN e10_witems_items AS items ON rows.item = items.ndx
					WHERE items.brand = 15 AND heads.dateAccounting >= %d AND heads.dateAccounting <= %d AND heads.docState = 4000 AND docType IN ('cashreg', 'invno') AND rows.invDirection = -1 AND heads.initState = 0
					GROUP BY rows.item, rows.dateAccounting, heads.docType, heads.paymentMethod";
		$rows = $this->app->db()->query($q, $dateFrom, $dateTo);

		$data = array ();

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itm = array ('rt' => 'DR', 'wh' => '1', 'ean' => '', 'pn' => '', 
										'wn' => array ('text'=> $itemNdx, 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
										'name' => $r['fullName'],
										'date' => $r['dateAccounting']->format('Ymd'),
										'sale' => intval ($r['quantity']),
										'ct' => '', 'country' => 'CZ');

			if ($r['docType'] == 'cashreg')
				$itm['ct'] = 'retail';
			else
			if ($r['docType'] == 'invno')
			{
				if ($r['paymentMethod'] == 3)
					$itm['ct'] = 'e-tail';
				else
					$itm['ct'] = 'retail';
			}

			// -- search ean
			$eans = $this->app->db()->query ("SELECT * FROM [e10_base_properties] WHERE [property] = 'ean' AND tableid = 'e10.witems.items' AND recid = $itemNdx")->fetch();
			if ($eans)
				$itm['ean'] = $eans ['valueString'];

			$data [] = $itm;
		}

		$c = '';

		$c .= "<h1>Prodej výrobků Canon za období ".utils::weekDate ($this->weekNumber, $this->weekYear, 1, 'd.m.Y').' až '.utils::weekDate ($this->weekNumber, $this->weekYear, 7, 'd.m.Y').'</h1>';

		$h = array ('rt' => 'RT', 'wh' => 'Sklad', 'ean' => 'EAN', 
								'pn' => 'Canon Code', 'wn' => 'Kód položky',
								'name' => 'Název výrobku',
								'date' => 'Datum',
								'sale' => ' Prodej v ks', 'ct' => 'Typ zákazníka', 'country' => 'Stát');

		$params = array ('tableClass' => 'e10-vd-mainTable');
		$c .= \E10\renderTableFromArray ($data, $h, $params);

		$params ['colSeparator'] = ';';
		$csv = utils::renderTableFromArrayCsv ($data, $h, $params);
		$csv .= 'FT;' . count ($data) . "\n";

		$ts = date ('YmdHis');
		$csvfn = "RET_SALE_FOTOCZCZ0000000_{$dateFrom2}_{$dateTo2}_{$ts}.TXT";
		file_put_contents(__APP_DIR__."/tmp/$csvfn", $csv);

		return $c;
	}

	function createReportContent_Inventory ()
	{
		$dateFrom = utils::weekDate ($this->weekNumber, $this->weekYear, 1);
		$dateTo = utils::weekDate ($this->weekNumber, $this->weekYear, 7);
		$dateFrom2 = utils::weekDate ($this->weekNumber, $this->weekYear, 1, 'Ymd');
		$dateTo2 = utils::weekDate ($this->weekNumber, $this->weekYear, 7, 'Ymd');

		$fiscalYear = e10utils::todayFiscalYear($this->app, $dateFrom);
		$q = "SELECT item, SUM(quantity) as quantity, items.fullName as fullName FROM [e10doc_inventory_journal] as inv
					LEFT JOIN e10_witems_items AS items ON inv.item = items.ndx
					WHERE items.brand = 15 AND [fiscalYear] = $fiscalYear AND [date] <= %d GROUP BY item";


		$rows = $this->app->db()->query($q, $dateTo);
		$data = array ();
		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itm = array ('rt' => 'DR', 'wh' => '1', 'ean' => '', 'pn' => '', 
										'wn' => array ('text'=> $itemNdx, 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
										'name' => $r['fullName'],
										'date' => $dateTo2,
										'stock' => intval ($r['quantity']),
										);

			// -- search ean
			$eans = $this->app->db()->query ("SELECT * FROM [e10_base_properties] WHERE [property] = 'ean' AND tableid = 'e10.witems.items' AND recid = $itemNdx")->fetch();
			if ($eans)
				$itm['ean'] = $eans ['valueString'];

			if ($itm['stock'] <= 0)
				continue;

			$data [] = $itm;
		}

		$c = '';

		$c .= "<h1>Stav skladu výrobků Canon za období ".utils::weekDate ($this->weekNumber, $this->weekYear, 1, 'd.m.Y').' až '.utils::weekDate ($this->weekNumber, $this->weekYear, 7, 'd.m.Y').'</h1>';

		$h = array ('rt' => 'RT', 'wh' => 'Sklad', 'ean' => 'EAN',
								'pn' => 'Canon Code', 'wn' => 'Kód položky',
								'name' => 'Název výrobku',
								'date' => 'Datum',
								'stock' => ' Stav skladu');

		$params = array ('tableClass' => 'e10-vd-mainTable');
		$c .= \E10\renderTableFromArray ($data, $h, $params);


		$params ['colSeparator'] = ';';
		$csv = utils::renderTableFromArrayCsv ($data, $h, $params);
		$csv .= 'FT;' . count ($data) . "\n";

		$ts = date ('YmdHis');
		$csvfn = "RET_INVE_FOTOCZCZ0000000_{$dateFrom2}_{$dateTo2}_{$ts}.TXT";
		file_put_contents(__APP_DIR__."/tmp/$csvfn", $csv);

		return $c;
	}

	function createReportContent_Warehouses ()
	{
		$dateFrom2 = utils::weekDate ($this->weekNumber, $this->weekYear, 1, 'Ymd');
		$dateTo2 = utils::weekDate ($this->weekNumber, $this->weekYear, 7, 'Ymd');

		$rows = $this->app->db->query ("SELECT * from [e10doc_base_warehouses] ORDER BY [id]");
		$data = array ();
		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itm = array ('rt' => 'DR', 'id' => $r['ndx'], 'name' => $r ['fullName'],
										'street' => $r['street'], 'zip' => $r['zipcode'],
										'city' => $r['city'],
										'country' => 'CZ'
										);
			$data [] = $itm;
		}

		$c = '';

		$c .= "<h1>Sklady výrobků Canon</h1>";

		$h = array ('rt' => 'RT', 'id' => 'Sklad č.', 'name' => 'Název',
								'street' => 'Ulice', 'zip' => 'PSČ',
								'city' => 'Město',
								'country' => 'Stát');

		$params = array ('tableClass' => 'e10-vd-mainTable');
		$c .= \E10\renderTableFromArray ($data, $h, $params);

		$params ['colSeparator'] = ';';
		$csv = utils::renderTableFromArrayCsv ($data, $h, $params);
		$csv .= 'FT;' . count ($data) . "\n";

		$ts = date ('YmdHis');
		$csvfn = "RET_OUTL_FOTOCZCZ0000000_{$dateFrom2}_{$dateTo2}_{$ts}.TXT";
		file_put_contents(__APP_DIR__."/tmp/$csvfn", $csv);

		return $c;
	}

	function createReportSalesXls ()
	{
		$dateFrom = utils::weekDate ($this->weekNumber, $this->weekYear, 1);
		$dateTo = utils::weekDate ($this->weekNumber, $this->weekYear, 7);

		$this->salesTitle = "Prodej výrobků Canon za období ".utils::weekDate ($this->weekNumber, $this->weekYear, 1, 'd.m.Y').' až '.utils::weekDate ($this->weekNumber, $this->weekYear, 7, 'd.m.Y');

		$q = "SELECT rows.item as item, rows.invDirection as invDirection, items.`type` as itemType, items.brand as itemBrand, brands.shortName as brandName, types.fullName as typeName,
									items.fullName as fullName, SUM(rows.priceTotal) as priceTotal, SUM(rows.quantity) as quantity
					FROM e10doc_core_rows as rows
					LEFT JOIN e10doc_core_heads AS heads ON rows.document = heads.ndx
					LEFT JOIN e10_witems_items AS items ON rows.item = items.ndx
					LEFT JOIN e10_witems_brands AS brands ON items.brand = brands.ndx
					LEFT JOIN e10_witems_itemtypes AS types ON items.`type` = types.id
					WHERE items.brand = 15 AND heads.dateAccounting >= %d AND heads.dateAccounting <= %d AND heads.docState = 4000 AND docType IN ('cashreg', 'invno', 'stockin') AND rows.invDirection <> 0 AND heads.initState = 0
					GROUP BY rows.item, rows.invDirection";
		$rows = $this->app->db()->query($q, $dateFrom, $dateTo);


		$data = array ();

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			if (!isset ($data[$itemNdx]))
			{
				$item = [
						'week' => $this->weekYear.'-'.$this->weekNumber,
						'account' => 'FOTOCZCZ0000000',
						'name' => $r['fullName'],
						'stock' => 0,
						'sale' => 0
				];

				// stock
				$fiscalYear = e10utils::todayFiscalYear($this->app, $dateFrom);
				$qq = "SELECT SUM(quantity) as quantity FROM [e10doc_inventory_journal] WHERE [item] = $itemNdx AND [fiscalYear] = $fiscalYear AND [date] <= '$dateTo' GROUP BY item";
				$stock = $this->app->db()->query ($qq)->fetch();
				if ($stock)
					$item['stock'] = intval ($stock ['quantity']);
			}

			if ($r['invDirection'] == -1)
			{ // sale
				$item['sale'] = intval ($r['quantity']);
			}
			else
			{ // buy
				//$data [$itemNdx]['buy'] = intval ($r['quantity']);
			}
			if ($item['sale'] || $item['stock'])
				$data [$itemNdx] = $item;
		}

		$h = array ('ndx' => ' Kód', 'ean' => 'EAN', 'brand' => 'Výrobce', 'name' => 'Název výrobku', 'type' => 'Produktová skupina',
				'stock' => ' Stav v ks', 'buy' => ' Nákup v ks', 'sale' => ' Prodej v ks', 'price' => ' Cena za ks');

		$params = array ('tableClass' => 'e10-vd-mainTable');
		$this->tableSales = $data;
	}


	public function createToolbarCode ()
	{
		$c = createWeeksPeriodCombo ($this->app, $this->weekYear, $this->weekNumber);
		$c .= parent::createToolbarCode ();
		return $c;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Microsoft Excel 2003 (.xlsx)', 'icon' => 'icon-file-excel-o',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xlsx',
				'data-filename' => $this->saveAsFileName('sales-xls')
		];
	}

	public function saveReportAs ()
	{
		$this->createReportSalesXls ();

		$excelEngine = new \lib\E10Excel ($this->app);
		$spreadsheet = $excelEngine->create();

		$excelEngine->putTable ($spreadsheet, 0, 'A', 1, [['week', 'account', 'product', 'stock', 'sales']]);
		$excelEngine->putTable ($spreadsheet, 0, 'A', 2, $this->tableSales);
		$excelEngine->setAutoSize($spreadsheet);

		$sheet = $spreadsheet->getSheet(0);
		//$sheet->setTitle ($this->salesTitle);

		$baseFileName = $excelEngine->save ($spreadsheet);
		$this->fullFileName = __APP_DIR__.'/tmp/'.$baseFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
	}

	public function saveAsFileName ($type)
	{
		$dateFrom2 = utils::weekDate ($this->weekNumber, $this->weekYear, 1, 'Ymd');
		$dateTo2 = utils::weekDate ($this->weekNumber, $this->weekYear, 7, 'Ymd');

		$fn = "RET_SALE_FOTOCZCZ0000000_{$dateFrom2}_{$dateTo2}.xlsx";
		return $fn;
	}
} // class reportCanon

