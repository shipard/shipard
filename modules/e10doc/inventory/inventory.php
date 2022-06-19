<?php

namespace e10doc\inventory;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/balance/balance.php';
require_once __SHPD_MODULES_DIR__ . 'e10/witems/tables/items.php';

use \E10\utils, \E10Doc\Core\e10utils, \E10\Wizard, \E10\TableForm, \E10\TableView;


function inventoryRecalc ($app, $options = NULL)
{
	$e = new \e10doc\inventory\libs\InventoryStatesEngine($app);
	$e->resetAllStates();

	$objectData ['message'] = 'Zásoby jsou přepočítány.';
	$objectData ['finalAction'] = 'reloadPanel';

	$r = new \E10\Response ($app);
	$r->add ("objectType", "panelAction");
	$r->add ("object", $objectData);

	return $r;
}


class Inventory
{
	const mtIn = 0, mtOut = 1;
	const mtoInitState = 1, mtoIn = 2,
			mtoMnfOutAssembly = 3, mtoMnfInAssembly = 4, mtoMnfOutDisassembly = 5, mtoMnfInDisassembly = 6,
			mtoOut = 7;

}

/**
 * reportInventoryStates
 *
 */

class reportInventoryStates extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalYear = 0;
	var $endDate = NULL;
	public $warehouse = 0;
	protected $reportType = 'normal';
	protected $units;
	var $tableItems;

	function init ()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['years'], 'defaultValue' => e10utils::todayFiscalMonth($this->app)]);
		$this->addParam ('warehouse');

		if ($this->reportType == 'normal')
		{
			$this->addParamItemsBrands ();
			$this->addParam ('switch', 'includeItems', ['title' => 'Zahrnout položky', 'place' => 'panel', 'switch' => array ('all' => 'Všechny', 'nonZero' => 'S nenulovým stavem')]);
		}

		parent::init();

		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->tableItems = $this->app->table ('e10.witems.items');

		$this->warehouse = $this->reportParams ['warehouse']['value'];
		$fpValue = $this->reportParams ['fiscalPeriod']['values'][$this->reportParams ['fiscalPeriod']['value']];
		if (!$this->fiscalYear)
		{
			$this->fiscalYear = $fpValue['fiscalYear'];
			$this->endDate = utils::createDateTime($fpValue['dateEnd']);
		}

		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('param', 'Sklad', $this->reportParams ['warehouse']['activeTitle']);
		if ($this->reportType == 'normal')
		{
			if ($this->reportParams ['itemBrand']['value'] != '-1')
				$this->setInfo('param', 'Značka', $this->reportParams ['itemBrand']['activeTitle']);
			if ($this->reportParams ['includeItems']['value'] != 'all')
				$this->setInfo('param', 'Zahrnuté položky', $this->reportParams ['includeItems']['activeTitle']);
		}
	}

	protected function addParamItemsBrands ()
	{
		$q = 'SELECT * from [e10_witems_brands] WHERE docState != 9800 ORDER BY fullName';

		$this->itemsBrands ['-1'] = 'Vše';
		$this->itemsBrands ['0'] = '-- neuvedeno --';

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['fullName'])
				$this->itemsBrands [$r['ndx']] = $r['fullName'];
			else
				$this->itemsBrands [$r['ndx']] = $r['shortName'];
		}

		$this->addParam('switch', 'itemBrand', ['title' => 'Značka', 'switch' => $this->itemsBrands]);
	}

	function createContent ()
	{
		$this->createReportContent_States ();
	}

	function createReportContent_States ()
	{
		$q [] = "SELECT item, unit, SUM(quantity) as quantity, SUM(inv.price) as priceAll, items.fullName as fullName, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain FROM [e10doc_inventory_journal] as inv
							LEFT JOIN e10_witems_items AS items ON inv.item = items.ndx";

		array_push($q, " WHERE [fiscalYear] = %i", $this->fiscalYear);
		if ($this->endDate !== NULL)
			array_push($q, ' AND [date] <= %d', $this->endDate);
		if ($this->warehouse)
			array_push($q, " AND [warehouse] = %i", $this->warehouse);
		if ($this->reportType == 'normal')
			if ($this->reportParams ['itemBrand']['value'] != '-1')
				array_push ($q, ' AND items.brand = %i', $this->reportParams ['itemBrand']['value']);

		array_push($q, " GROUP BY item, unit");

		if ($this->reportType == 'normal')
			if ($this->reportParams ['includeItems']['value'] != 'all')
				array_push($q, " HAVING [quantity] != 0 OR priceAll != 0");
		if ($this->reportType == 'minus')
			array_push($q, " HAVING ([quantity] < 0)");
		if ($this->reportType == 'troubles')
			array_push($q, " HAVING ([quantity] = 0 AND [priceAll] != 0) OR ([quantity] != 0 AND [priceAll] = 0)");


		$rows = $this->app->db()->query($q);

		$data = array();


		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itemRecData = array('ndx' => $r['itemId'], 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']);
			$docStates = $this->tableItems->documentStates($itemRecData);
			$docStateClass = $this->tableItems->getDocumentStateInfo($docStates, $itemRecData, 'styleClass');

			$itm = array(
				'wn' => array('text' => $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk' => $itemNdx),
				'n' => $r['fullName'],
				'q' => $r['quantity'], 'u' => isset($this->units[$r['unit']]) ? $this->units[$r['unit']]['shortcut'] : '',
				'pa' => $r['priceAll'],
				'pi' => ($r['quantity'] === 0.0) ? 0.0 : round($r['priceAll'] / $r['quantity'], 2),
				'_options' => array('cellClasses' => array('wn' => $docStateClass))
			);
			$data[] = $itm;
		}

		$title = NULL;
		if ($this->reportType == 'minus')
		{
			$this->setInfo('icon', 'e10doc-inventory/minus');
			$this->setInfo('title', 'Záporné stavy položek');
			$title = 'Záporné stavy ke konci období';
		} else
		if ($this->reportType == 'troubles')
		{
			$this->setInfo('icon', 'e10doc-inventory/troubles');
			$this->setInfo('title', 'Problematické stavy položek');
		}
		else
		{
			$this->setInfo('icon', 'e10doc-inventory/inventoryStates');
			$this->setInfo('title', 'Stavy položek');
		}

		if (count($data))
		{
			$h = [
				'#' => '#', 'wn' => ' id', 'n' => 'Název', 'q' => ' Množství', 'u' => 'Jed.',
				'pi' => ' Cena/Jed', 'pa' => '+Cena Celkem'
			];
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $data, 'title' => $title, 'main' => TRUE, 'params' => ['minPrecision' => 2]]);
		}
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar();

		if ($this->reportType == 'minus')
			$toolbar [] = array ('type' => 'action', 'action' => 'addwizard', 'table' => 'e10.persons.persons',
													 'text' => 'Opravit mínusy', 'data-class' => 'e10doc.inventory.RepairMinusWizard', 'icon' => 'icon-magic');

		return $toolbar;
	}

} // class reportInventoryStates



/**
 * reportInventoryMinus
 *
 */

class reportInventoryMinus extends reportInventoryStates
{
	var $mnfSupport = FALSE;

	function init ()
	{
		if ($this->app->model()->module('e10doc.mnf') !== FALSE)
			$this->mnfSupport = TRUE;

		$this->reportType = 'minus';
		parent::init();
	}

	function createContent ()
	{
		$this->createReportContent_States ();
		$this->createReportContent_Minus ();
	}

	function createReportContent_Minus ()
	{
		$q [] =  'SELECT item, unit, [date], SUM(quantity) as quantity, SUM(inv.price) as priceAll, items.fullName as fullName, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain';

		if ($this->mnfSupport )
			array_push ($q, ', items.mnfEnableAssembling as mnfEnableAssembling');

		array_push ($q, ' FROM [e10doc_inventory_journal] as inv LEFT JOIN e10_witems_items AS items ON inv.item = items.ndx');
		array_push ($q, ' WHERE [fiscalYear] = %i', $this->fiscalYear);
		if ($this->warehouse)
			array_push ($q, ' AND [warehouse] = %i', $this->warehouse);
		array_push ($q, ' GROUP BY item, unit, [date]');

		$rows = $this->app->db()->query($q);

		$data = array ();

		$state = 0;
		$lastItem = -1;

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			if ($lastItem != $itemNdx)
			{
				$lastItem = $itemNdx;
				$state = 0;
			}

			$state += $r['quantity'];
			$state = round($state, 4);
			if ($state >= 0.0)
				continue;
			//if (isset ($data[$itemNdx]))
			//	continue;

			$itemRecData = array ('ndx' => $itemNdx, 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']);
			$docStates = $this->tableItems->documentStates ($itemRecData);
			$docStateClass = $this->tableItems->getDocumentStateInfo ($docStates, $itemRecData, 'styleClass');

			$itm = [
				'wn' => ['text' => $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx],
				'n' => $r['fullName'],
				'd' => utils::datef($r['date']),
				'q' => $state/*round($state, 7)*/, 'u' => $r['unit'],
				'_options' => ['cellClasses' => ['wn' => $docStateClass]]
			];

			if ($this->mnfSupport)
			{
				if ($r['mnfEnableAssembling'])
				{
					$dm = new \DateTime ($r['date']->format('Y-m-d'));
					$dm->sub(new \DateInterval('P1D'));
					$xms = $dm->format ('Y-m-d');
					$quantity = $state * -1;
					$itm ['action'] = array ('text'=> 'Vyrobit', 'docAction' => 'new', 'table' => 'e10doc.core.heads', 'type' => 'button', 'actionClass' => 'btn btn-primary',
																	 'addParams' => "__docType=mnf&__firstRowItem={$r['item']}&__warehouse={$this->warehouse}&__firstRowUnit={$r['unit']}&__dateIssue={$xms}&__firstRowQuantity={$quantity}&__firstRowOperation=1060701");
				}
			}
			$data[] = $itm;
			unset ($itm);
		}

		$title = 'Průběžné mínusy položek';
		$h = ['#' => '#', 'wn' => ' id', 'n' => 'Název', 'q' => ' Stav', 'u' => 'Jed.', 'd' => ' Datum', 'action' => 'Akce'];

		if (!$this->mnfSupport || $this->testEngine)
			unset ($h['action']);

		$this->addContent (['type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data, 'params' => ['minPrecision' => 2]]);

		if ($this->testEngine && count($data))
		{
			$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Záporné stavy položek', 'class' => 'h2 block pt1']]);
			$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data, 'params' => ['minPrecision' => 2]]);
		}
	}

	public function setTestCycle ($cycle, $testEngine)
	{
		parent::setTestCycle($cycle, $testEngine);

		$this->subReportId = 'minus';

		switch ($cycle)
		{
			case 'thisFiscalYear': $this->fiscalYear = e10utils::todayFiscalYear($this->app()); break;
			case 'prevFiscalYear': $this->fiscalYear = e10utils::prevFiscalYear($this->app(), e10utils::todayFiscalYear($this->app())); break;
		}
	}

	public function testTitle ()
	{
		$fiscalYearCfg = $this->app()->cfgItem ('e10doc.acc.periods.'.$this->fiscalYear, NULL);
		$title = $fiscalYearCfg ? $fiscalYearCfg['fullName'] : '!!!';
		$t = [];
		$t[] = [
			'text' => 'Byly nalezeny záporné stavy položek '.$title,
			'class' => 'subtitle e10-me h1 block mt1 bb1 lh16'
		];
		return $t;
	}
} // class reportInventoryMinus


/**
 * reportInventoryTroubles
 *
 */

class reportInventoryTroubles extends reportInventoryStates
{
	function init ()
	{
		$this->reportType = 'troubles';
		parent::init();
	}
} // class reportInventoryTroubles


/**
 * reportInventoryErrors
 *
 */

class reportInventoryErrors extends \e10doc\core\libs\reports\GlobalReport
{
	protected $reportType = 'normal';
	protected $warehouse = 0;
	protected $fiscalYear = 0;

	var $tableItems;

	function init ()
	{
		$this->setParams ('fiscalYear warehouse');
		parent::init();

		$this->tableItems = $this->app->table ('e10.witems.items');

		$this->setInfo('title', 'Chyby ve skladové evidenci');
		$this->setInfo('icon', 'e10doc-inventory/errors');
		$this->setInfo('param', 'Rok', $this->reportParams ['fiscalYear']['activeTitle']);
		$this->setInfo('param', 'Sklad', $this->reportParams ['warehouse']['activeTitle']);
	}

	function createContent ()
	{
		$this->warehouse = $this->reportParams ['warehouse']['value'];
		$this->fiscalYear = $this->reportParams ['fiscalYear']['value'];

		$this->createReportContent_BadStates ();
		$this->createReportContent_BadItemTypes ();
		$this->createReportContent_NoneUnits ();
		$this->createReportContent_BadUnits ();
	}

	function createReportContent_BadStates ()
	{
		$q [] =  "SELECT item, SUM(quantity) as quantity, SUM(inv.price) as priceAll, items.fullName as fullName, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain
							FROM [e10doc_inventory_journal] as inv
							LEFT JOIN e10_witems_items AS items ON inv.item = items.ndx";

		array_push ($q, " WHERE [fiscalYear] = %i", $this->fiscalYear);
		if ($this->warehouse)
			array_push ($q, " AND [warehouse] = %i", $this->warehouse);

		array_push ($q, " GROUP BY item");

		if ($this->reportType == 'minus')
			array_push ($q, " HAVING ([quantity] < 0)");
		else
		if ($this->reportType == 'troubles')
			array_push ($q, " HAVING (quantity = 0 AND priceAll != 0) OR (quantity != 0 AND priceAll = 0)");


		$rows = $this->app->db()->query($q);

		$data = array ();


		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itemRecData = array ('ndx' => $itemNdx, 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']);
			$docStates = $this->tableItems->documentStates ($itemRecData);
			$docStateClass = $this->tableItems->getDocumentStateInfo ($docStates, $itemRecData, 'styleClass');


			$itm = array (
										'wn' => array ('text' => $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
										'n' => $r['fullName'],
										'q' => $r['quantity'],
										'_options' => array ('cellClasses' => array('wn' => $docStateClass))
										);
			$data[$itemNdx] = $itm;
		}


		$q2 = "SELECT [rows].item as item, SUM([rows].quantity*[rows].invDirection) as quantity FROM e10doc_core_rows as [rows], e10doc_core_heads as heads
				   where [rows].document = heads.ndx and [rows].invDirection != 0 AND heads.docState = 4000
					 AND heads.fiscalYear = %i GROUP BY item";
		$rows = $this->app->db()->query($q2, $this->fiscalYear);

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];
			if (isset ($data[$itemNdx]))
			{
				if ($data[$itemNdx]['q'] == $r['quantity'])
				{
					unset ($data[$itemNdx]);
					continue;
				}
				$data[$itemNdx]['q2'] = $r['quantity'];
			}
		}

		if (count($data) > 0)
		{
			$title = "Chybné stavy položek";
			$h = array ('#' => '#', 'wn' => ' id', 'n' => 'Název', 'q' => ' Chybné množství', 'q2' => ' Správné množství');
			$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
		}
	}

	function createReportContent_BadItemTypes ()
	{
		$q [] =  "SELECT heads.docNumber as docNumber, heads.ndx as docNdx, [rows].item as item, items.fullName as fullName,
							[rows].itemType as badItemType, items.[type] as goodItemType, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain,
							[rows].quantity as quantity
							FROM e10doc_core_rows as [rows], e10doc_core_heads as heads, e10_witems_items as items";
		array_push ($q, " where [rows].document = heads.ndx and [rows].item = items.ndx
											and heads.docState = 4000 AND ([rows].itemType != items.[type] OR [rows].itemType IS NULL)");
		array_push ($q, " AND heads.fiscalYear = %i", $this->fiscalYear);
		if ($this->warehouse)
			array_push ($q, " AND [heads.warehouse] = %i", $this->warehouse);

		$data = array ();
		$rows = $this->app->db()->query($q);

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itemRecData = array ('ndx' => $itemNdx, 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']);
			$docStates = $this->tableItems->documentStates ($itemRecData);
			$docStateClass = $this->tableItems->getDocumentStateInfo ($docStates, $itemRecData, 'styleClass');

			$itm = array (
				'dn' => array ('text' => $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docNdx']),
				'wn' => array ('text' => $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx, ),
				'n' => $r['fullName'],
				'bit' => $r['badItemType'], 'git' => $r['goodItemType'],
				'q' => $r['quantity'],
				'_options' => array ('cellClasses' => array('wn' => $docStateClass))
			);
			$data[] = $itm;
		}

		if (count($data) > 0)
		{
			$title = "Řádky dokladů se špatným typem položky";
			$h = array ('#' => '#', 'dn'=>'Doklad', 'wn' => ' id', 'n' => 'Název', 'bit' => 'Chybný typ', 'git' => 'Správný typ');
			$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
		}
	}

	function createReportContent_NoneUnits ()
	{
		$q [] =  "SELECT heads.docNumber as docNumber, heads.ndx as docNdx, [rows].item as item, items.fullName as fullName, [rows].itemType as badItemType, items.[type] as goodItemType,
							[rows].quantity as quantity, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain
							FROM e10doc_core_rows as [rows], e10doc_core_heads as heads, e10_witems_items as items";
		array_push ($q, " where [rows].document = heads.ndx and [rows].item = items.ndx
											and heads.docState = 4000 AND invDirection != 0 AND [rows].unit = %s", 'none');
		array_push ($q, " AND heads.fiscalYear = %i", $this->fiscalYear);
		array_push ($q, " AND [heads.warehouse] = %i", $this->warehouse);

		$data = array ();
		$rows = $this->app->db()->query($q);

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itemRecData = array ('ndx' => $itemNdx, 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']);
			$docStates = $this->tableItems->documentStates ($itemRecData);
			$docStateClass = $this->tableItems->getDocumentStateInfo ($docStates, $itemRecData, 'styleClass');

			$itm = array (
										'wn' => array ('text'=> $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
										'dn' => array ('text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docNdx']),
										'n' => $r['fullName'],
										'q' => $r['quantity'],
										'_options' => array ('cellClasses' => array('wn' => $docStateClass))
										);
			$data[] = $itm;
		}

		if (count($data) > 0)
		{
			$title = "Řádky dokladů bez jednotky";
			$h = array ('#' => '#', 'dn'=>'Doklad', 'wn' => ' Položka', 'n' => 'Název');
			$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
		}
	}

	function createReportContent_BadUnits ()
	{
		$q [] =  "SELECT heads.docNumber as docNumber, heads.ndx as docNdx, [rows].item as item, items.fullName as fullName, [rows].unit as badItemUnit, items.[defaultUnit] as goodItemUnit,
							[rows].quantity as quantity, items.id as itemId, items.docState as itemDocState, items.docStateMain as itemDocStateMain
							FROM e10doc_core_rows as [rows], e10doc_core_heads as heads, e10_witems_items as items";
		array_push ($q, " where [rows].document = heads.ndx and [rows].item = items.ndx
											and heads.docState = 4000 AND [rows].unit != items.[defaultUnit] AND invDirection != 0");
		array_push ($q, " AND heads.fiscalYear = %i", $this->fiscalYear);
		if ($this->warehouse)
			array_push ($q, " AND [heads.warehouse] = %i", $this->warehouse);

		$data = array ();
		$rows = $this->app->db()->query($q);

		forEach ($rows as $r)
		{
			$itemNdx = $r['item'];

			$itemRecData = array ('ndx' => $itemNdx, 'docState' => $r['itemDocState'], 'docStateMain' => $r['itemDocStateMain']);
			$docStates = $this->tableItems->documentStates ($itemRecData);
			$docStateClass = $this->tableItems->getDocumentStateInfo ($docStates, $itemRecData, 'styleClass');

			$itm = array (
										'dn' => array ('text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docNdx']),
										'wn' => array ('text'=> $r['itemId'], 'docAction' => 'edit', 'table' => 'e10.witems.items', 'pk'=> $itemNdx),
										'n' => $r['fullName'],
										'bit' => $r['badItemUnit'], 'git' => $r['goodItemUnit'],
										'q' => $r['quantity'],
										'_options' => array ('cellClasses' => array('wn' => $docStateClass))
										);
			$data[] = $itm;
		}

		if (count($data) > 0)
		{
			$title = "Řádky dokladů se špatnou jednotkou";
			$h = array ('#' => '#', 'dn'=>'Doklad', 'wn' => ' id', 'n' => 'Název', 'bit' => 'Chybná jednotka', 'git' => 'Správná jednotka');
			$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
		}
	}
} // class reportInventoryErrors


/**
 * reportInventoryWarehouse
 *
 */

class reportInventoryWarehouse extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalYear = 0;
	public $warehouse = 0;
//	protected $units;

	function init ()
	{
		$this->setParams ('fiscalYear warehouse');
		parent::init();

		$this->warehouse = $this->reportParams ['warehouse']['value'];
		$this->fiscalYear = $this->reportParams ['fiscalYear']['value'];

		$this->setInfo('title', 'Rekapitulace skladu');
		$this->setInfo('icon', 'e10doc-inventory/warehouse');
		$this->setInfo('param', 'Rok', $this->reportParams ['fiscalYear']['activeTitle']);
		$this->setInfo('param', 'Sklad', $this->reportParams ['warehouse']['activeTitle']);
	}

	function createContent ()
	{
		$this->createReportContent_Total ();
		$this->createReportContent_Monthly ();
	}

	function createReportContent_Total ()
	{
		$q [] =  "SELECT warehouse, moveTypeOrder, SUM(inv.price) as priceAll FROM e10doc_inventory_journal as inv";
		array_push ($q, " WHERE [fiscalYear] = %i", $this->fiscalYear);
		if ($this->warehouse)
			array_push ($q, " AND [warehouse] = %i", $this->warehouse);
		array_push ($q, " GROUP BY warehouse, moveTypeOrder");
		$rows = $this->app->db()->query($q);

		$data = array ();


		forEach ($rows as $r)
		{
			$wh = $r['warehouse'];
			$mt = 'mt'.$r['moveTypeOrder'];
			if (isset ($data[$wh]))
			{
				$data[$wh][$mt] = $r['priceAll'];
			}
			else
			{
				$data[$wh] = array ('wh' => $wh, $mt => $r['priceAll']);
			}
		}

		forEach ($data as &$r)
		{
			$t = 0;
			forEach ($r as $numId => $numVal)
			{
				if (substr($numId, 0, 2) !== 'mt')
					continue;
				$t += $numVal;
			}
			$r['total'] = $t;
		}

		$title = "Roční bilance";
		$h = array ('mt1' => ' Počáteční stav', 'mt'.Inventory::mtoIn => ' Příjem', 'mt'.Inventory::mtoOut => ' Výdej', 'total' => ' Zůstatek');
		$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
	}

	function createReportContent_Monthly ()
	{
		$q [] =  "SELECT MONTH([date]) as mnth, moveTypeOrder, SUM(inv.price) as priceAll FROM e10doc_inventory_journal as inv";
		array_push ($q, " WHERE [fiscalYear] = %i", $this->fiscalYear, " AND [warehouse] = %i", $this->warehouse);
		array_push ($q, " GROUP BY mnth, moveTypeOrder");
		$rows = $this->app->db()->query($q);

		$data = array ();


		forEach ($rows as $r)
		{
			$wh = $r['warehouse'];
			$mt = 'mt'.$r['moveTypeOrder'];
			$month = $r['mnth'];
			if (isset ($data[$month]))
			{
				$data[$month][$mt] = $r['priceAll'];
			}
			else
			{
				$data[$month] = array ('month' => $month, $mt => $r['priceAll']);
			}
		}

		$total = 0;
		forEach ($data as &$r)
		{
			$t = 0;
			forEach ($r as $numId => $numVal)
			{
				if (substr($numId, 0, 2) !== 'mt')
					continue;
				$t += $numVal;
			}
			$r['bilance'] = $t;
			$total += $t;
			$r['total'] = $total;
		}

		$title = "Měsíční bilance";
		$h = array ('month' => 'Měsíc',
								'mt1' => '+Počáteční stav', 'mt'.Inventory::mtoIn => '+Příjem', 'mt'.Inventory::mtoOut => '+Výdej',
								'bilance' => '+Bilance', 'total' => ' Průběžný zůstatek');
		$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $data));
	}
} // class reportInventoryWarehouse


/**
 * reportBalanceStockInInvoice
 *
 */

class reportBalanceStockInInvoice extends \E10Doc\Balance\reportBalance
{
	function init ()
	{
		$this->balance = 5000;
		parent::init();
	}
}


/**
 * ViewItems
 */

class ViewItems extends \E10\Witems\ViewItems
{
	public function init ()
	{
		$this->itemKind = 1;
		parent::init();
	}
} // class ViewItems


/**
 * MergeItems
 *
 */

class MergeItems extends \E10Doc\Core\MergeItems
{
	protected function doit ()
	{
		$this->db()->query ('UPDATE [e10doc_inventory_checkRows] SET [item] = %i WHERE [item] IN %in', $this->mergeTargetNdx, $this->mergedNdxs);
	}
}


/**
 * RepairMinusEngine
 *
 */

class RepairMinusEngine extends \E10\Utility
{
	var $warehouse;
	var $fiscalYear;
	var $tableDocs;
	var $tableRows;
	var $prices = array();
	var $docLinkId;
	var $openDocsOnly;

	function createDocHead ($calendarYear, $calendarMonth)
	{
		$docDate = "$calendarYear-$calendarMonth-01";

		$q = 'SELECT * FROM [e10doc_core_heads] WHERE [dateAccounting] = %d AND [linkId] = %s';
		$existedDocs = $this->db()->query ($q, $docDate, $this->docLinkId)->fetch();

		if ($existedDocs['docState'] === 4000)
		{
			return FALSE;
		}

		if (isset($existedDocs['ndx']))
		{
			$docH = $existedDocs->toArray ();
		}
		else
		{
			$docH = array ();
			$docH ['docType']						= 'stockin';
			$docH ['warehouse']					= $this->warehouse;
			$this->tableDocs->checkNewRec ($docH);
		}

		$docH ['dateAccounting']		= $docDate;
		$docH ['dateIssue']					= $docDate;
		$docH ['person'] 						= $docH ['owner'];
		$docH ['title'] 						= 'Aktivace nalezených zásob';
		$docH ['taxCalc']						= 0;
		$docH ['linkId']						= $this->docLinkId;

		return $docH;
	}

	function createDocRows ($head, $endDate)
	{
		$newRows = array();

		$q = "SELECT items.fullName as itemFullName, items.defaultUnit as itemUnit, items.[type] as itemType,
							 SUM(journal.quantity) as quantity, item FROM [e10doc_inventory_journal] as journal
				RIGHT JOIN e10_witems_items as items on (items.ndx = journal.item)
				WHERE [fiscalYear] = %i AND [date] <= %d GROUP BY item HAVING quantity < 0";
		$rows = $this->app->db()->query ($q, $this->fiscalYear, $endDate);
		forEach ($rows as $r)
		{
			$newRow = array ();
			$newRow ['item'] = $r['item'];
			$newRow ['itemType'] = $r['itemType'];
			$newRow ['text'] = $r['itemFullName'];
			$newRow ['unit'] = $r['itemUnit'];
			$newRow ['quantity'] = - $r['quantity'];
			$newRow ['priceItem'] = $this->itemPrice($r['item']);

			$newRows[] = $newRow;
		}

		return $newRows;
	}

	function openExistedDocs ()
	{
		$q = "SELECT * FROM [e10doc_core_heads] WHERE [linkId] = %s AND [docState] = 4000";
		$rows = $this->db()->query ($q, $this->docLinkId);
		foreach ($rows as $r)
		{
			$this->tableDocs->documentOpen ($r['ndx']);
		}
	}

	function setParams ($warehouse, $fiscalYear, $openDocsOnly)
	{
		$this->warehouse = $warehouse;
		$this->fiscalYear = $fiscalYear;
		$this->openDocsOnly = $openDocsOnly;
	}

	function run ()
	{
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);
		$this->docLinkId = "INVREPMIN;{$this->fiscalYear};$this->warehouse;";

		$this->app->db->begin();

		$this->openExistedDocs();

		if (!$this->openDocsOnly)
		{
			$months = $this->app->db()->query('SELECT * FROM [e10doc_base_fiscalmonths] WHERE [fiscalType] = 0 AND fiscalYear = %i ORDER BY [globalOrder]', $this->fiscalYear);

			foreach ($months as $m)
			{
				$docHead = $this->createDocHead ($m['calendarYear'], $m['calendarMonth']);
				$docRows = $this->createDocRows($docHead, $m['end']);
				if (count($docRows) !== 0)
					$this->save ($docHead, $docRows);
			}
		}

		$this->app->db->commit();
	}

	protected function save ($head, $rows)
	{
		if (!isset ($head['ndx']))
		{
			$docNdx = $this->tableDocs->dbInsertRec ($head);
		}
		else
		{
			$docNdx = $head['ndx'];
			$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
			$this->tableDocs->dbUpdateRec ($head);
		}

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec ($f->recData);

		forEach ($rows as $row)
		{
			$row['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($row, $f->recData);
		}

		$f->recData ['docState'] = 4000;
		$f->recData ['docStateMain'] = 2;
		$this->tableDocs->checkDocumentState ($f->recData);
		$f->checkAfterSave();
		$this->tableDocs->dbUpdateRec ($f->recData);
		$this->tableDocs->checkAfterSave2 ($f->recData);
	}

	public function itemPrice ($itemNdx)
	{
		if (isset ($this->prices[$itemNdx]))
			return $this->prices[$itemNdx];

		$this->prices[$itemNdx] = 0.0;

		for ($yd = 0; $yd < 2; $yd++)
		{ // TODO: select years from db!!!
			$fy = $this->fiscalYear - $yd;
			$q = "SELECT SUM(journal.quantity) as quantity, SUM(journal.price) as price FROM [e10doc_inventory_journal] as journal
						WHERE [fiscalYear] = %i AND [item] = %i AND moveType = %i";
			$priceRec = $this->app->db()->query ($q, $fy, $itemNdx, Inventory::mtIn)->fetch();
			if ($priceRec['quantity'] != 0 && $priceRec['price'] != 0)
			{
				$this->prices[$itemNdx] = round (floatval($priceRec['price']) / floatval($priceRec['quantity']), 2);
				break;
			}
		}

		return $this->prices[$itemNdx];
	}
}


/**
 * RepairMinusWizard
 *
 */

class RepairMinusWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ($this->recData ['fiscalYear']);
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->recData ['fiscalYear'] = intval($this->postData['fiscalYear']);

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addInputEnum2 ('fiscalYear', 'Účetní období', e10utils::fiscalYearEnum ($this->app()), self::INPUT_STYLE_OPTION);
			$this->addCheckBox('openDocsOnly', 'Pouze otevřít existující příjemky');
		$this->closeForm ();
	}

	public function doIt ($fiscalYear)
	{
		$eng = new RepairMinusEngine ($this->app);
		$eng->setParams(1, $fiscalYear, $this->recData ['openDocsOnly']);
		$eng->run();
		$this->stepResult ['close'] = 1;
	}
}

/**
 * CheckDocsEngine
 *
 */


class CheckDocsEngine extends \E10\Utility
{
	var $warehouse;
	var $fiscalYear;
	var $date;
	var $docType;
	var $checkNdx;
	var $checkRec;
	var $tableDocs;
	var $tableRows;
	var $prices = array();
	var $docLinkId;

	function createDocHead ()
	{
		$docDate = $this->checkRec['dateCheck']->format('Y-m-d');
		$q = 'SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s AND [linkId] = %s';
		$existedDocs = $this->db()->query ($q, $this->docType, $this->docLinkId)->fetch();

		if ($existedDocs['docState'] === 4000)
		{
			return FALSE;
		}

		if (isset($existedDocs['ndx']))
		{
			$docH = $existedDocs->toArray ();
		}
		else
		{
			$docH = array ();
			$docH ['docType']						= $this->docType;
			$docH ['warehouse']					= $this->warehouse;
			$this->tableDocs->checkNewRec ($docH);
		}

		$docH ['dateAccounting']		= $docDate;
		$docH ['dateIssue']					= $docDate;
		$docH ['person'] 						= $docH ['owner'];
		$docH ['title'] 						= 'Narovnání inventurních rozdílů '.$this->checkRec['docNumber'];
		$docH ['taxCalc']						= 0;

		return $docH;
	}

	function createDocRows ($head)
	{
		$newRows = array();
		$date = $this->checkRec['dateCheck']->format('Y-m-d');
		$fiscalYear = e10utils::todayFiscalYear($this->app, $this->checkRec['dateCheck']);
		$inventoryCheck = $this->checkNdx;

		$q [] = 'SELECT items.ndx as item, items.fullname as itemFullName, items.id as itemid, items.defaultUnit as itemUnit,';
		array_push ($q, ' (SELECT sum(quantity) as q1 from e10doc_inventory_journal as journal WHERE [fiscalYear] = %i AND [date] <= %d AND journal.item = items.ndx) as invQuantity,', $fiscalYear, $date);
		array_push ($q, ' (SELECT sum(quantity) as q2 from e10doc_inventory_checkRows as checks WHERE inventoryCheck = %i AND checks.item = items.ndx) as checkQuantity', $inventoryCheck);
		array_push ($q, ' FROM e10_witems_items as items');

		if ($this->docType === 'stockin')
			array_push ($q, ' HAVING (checkQuantity > invQuantity AND invQuantity >= 0 AND checkQuantity IS NOT NULL) OR (checkQuantity > 0 AND invQuantity IS NULL)');
		else
			array_push ($q, ' HAVING (checkQuantity < invQuantity AND invQuantity >= 0) OR (invQuantity > 0 AND checkQuantity IS NULL)');

		array_push ($q, ' ORDER BY itemFullName, item');

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
			$newRow = array ();

			if ($r['checkQuantity'] === null)
				$r['checkQuantity'] = 0;
			if ($r['invQuantity'] === null)
				$r['invQuantity'] = 0;

			$newRow ['item'] = $r['item'];
			$newRow ['itemType'] = $r['itemType'];
			$newRow ['text'] = $r['itemFullName'];
			$newRow ['unit'] = $r['itemUnit'];
			if ($this->docType === 'stockin')
			{
				$newRow ['quantity'] = ($r['checkQuantity'] - $r['invQuantity']);
				$newRow ['priceItem'] = $this->itemPrice($r['item']);
			}
			else
			{
				$newRow ['quantity'] = ($r['invQuantity'] - $r['checkQuantity']);
				$newRow ['priceItem'] = 0;
			}

			$newRows[] = $newRow;
		}

		return $newRows;
	}

	function setParams ($warehouse, $docType, $checkNdx)
	{
		$this->warehouse = $warehouse;
		$this->docType = $docType;
		$this->checkNdx = $checkNdx;
	}

	function run ()
	{
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);
		$this->checkRec = $this->tableRows->loadItem($this->checkNdx, 'e10doc_inventory_checkHeads');
		$this->fiscalYear = e10utils::todayFiscalYear($this->app, $this->checkRec['dateCheck']);
		$this->docLinkId = "INVCHECK;{$this->checkNdx};";


		$this->app->db->begin();

		$docHead = $this->createDocHead ();
		if ($docHead !== FALSE)
		{
			$docRows = $this->createDocRows($docHead);
			if (count($docRows) !== 0)
				$this->save ($docHead, $docRows);
		}

		$this->app->db->commit();
	}

	protected function save ($head, $rows)
	{
		if (!isset ($head['ndx']))
		{
			$docNdx = $this->tableDocs->dbInsertRec ($head);
		}
		else
		{
			$docNdx = $head['ndx'];
			$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
			$this->tableDocs->dbUpdateRec ($head);
		}

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec ($f->recData);

		forEach ($rows as $row)
		{
			$row['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($row, $f->recData);
		}

		$f->recData ['docState'] = 4000;
		$f->recData ['docStateMain'] = 2;
		$this->tableDocs->checkDocumentState ($f->recData);
		$f->checkAfterSave();
		$this->tableDocs->dbUpdateRec ($f->recData);
		$this->tableDocs->checkAfterSave2 ($f->recData);
	}

	public function itemPrice ($itemNdx)
	{
		if (isset ($this->prices[$itemNdx]))
			return $this->prices[$itemNdx];

		$this->prices[$itemNdx] = 0.0;

		for ($yd = 0; $yd < 2; $yd++)
		{
			$fy = $this->fiscalYear - $yd;
			$q = "SELECT SUM(journal.quantity) as quantity, SUM(journal.price) as price FROM [e10doc_inventory_journal] as journal
					WHERE [fiscalYear] = %i AND [item] = %i AND moveType = %i";
			$priceRec = $this->app->db()->query ($q, $fy, $itemNdx, Inventory::mtIn)->fetch();
			if ($priceRec['quantity'] != 0 && $priceRec['price'] != 0)
			{
				$this->prices[$itemNdx] = round (floatval($priceRec['price']) / floatval($priceRec['quantity']), 2);
				break;
			}
		}

		return $this->prices[$itemNdx];
	}
}

/**
 * CheckDocsWizard
 *
 */

class CheckDocsWizard extends Wizard
{
	protected $docType;

	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->recData['checkNdx'] = $this->focusedPK;

		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
		$this->addInput('checkNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

		$this->closeForm ();
	}

	public function doIt ()
	{
		$eng = new CheckDocsEngine ($this->app);
		$eng->setParams(1, $this->docType, $this->recData['checkNdx']);
		$eng->run();

		$this->stepResult ['close'] = 1;
	}
}

/**
 * CheckDocsWizardIn
 *
 */

class CheckDocsWizardIn extends CheckDocsWizard
{
	public function __construct($app, $options = NULL)
	{
		parent::__construct($app, $options);
		$this->docType = 'stockin';
	}
}

/**
 * CheckDocsWizardOut
 *
 */

class CheckDocsWizardOut extends CheckDocsWizard
{
	public function __construct($app, $options = NULL)
	{
		parent::__construct($app, $options);
		$this->docType = 'stockout';
	}
}


/**
 * Seznam příjemek bez faktury
 */

class InvoiceStockinDisposal extends \E10Doc\Balance\BalanceDisposalViewer
{
	var $itemForLine=0;

	public function init ()
	{
		$this->balance = 5000;
		$this->docCheckBoxes = 1;
		parent::init();
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$buttons = array ();
		$buttons [] = array ('text' => 'Vystavit fakturu', 'docAction' => 'new', 'table' => 'e10doc.core.heads', 'addParams' => '__docType=invni');
		$item['buttons'] = $buttons;

		$item['docActionData']['operation'] = '1099998';
		$item['docActionData']['title'] = 'Nákup zásob - příjemka';

		if (!isset ($item['docActionData']['symbol1']))
		{
			reset($this->documents [$item['pk']]);
			$firstDocKey = key($this->documents [$item['pk']]);//[$r['pairId']]
			$item['docActionData']['symbol1'] = $this->documents [$item['pk']][$firstDocKey]['symbol1'];
		}

		// -- dbCounter: TODO: settings in buy options
		$dbCounters = $this->table->app()->cfgItem ('e10.docs.dbCounters.invni', FALSE);
		$item['docActionData']['dbCounter'] = key($dbCounters);
	}

	public function checkDocumentTile ($document, &$tile)
	{
		$tile['docActionData']['text'] = 'Nákup zásob - příjemka '.$document['docNumber'];
		$tile['docActionData']['item'] = $this->itemForLine();
		$tile['docActionData']['itemBalance'] = 5000;
		$tile['docActionData']['taxCode'] = '110'; // TODO: settings?
		//$tile['docActionData']['weightNet'] = $document['weightNet'];
	}

	protected function itemForLine ()
	{
		if (!$this->itemForLine)
		{
			$rows = $this->db()->query ('SELECT * FROM e10_witems_items WHERE useBalance = 5000 AND docStateMain = 2')->fetch();
			if (isset($rows['ndx']))
				$this->itemForLine = $rows['ndx'];
		}

		return $this->itemForLine;
	}


} // InvoiceStockinDisposal


/**
 * Class ViewItemsInventory
 * @package E10Doc\Inventory
 */
class ViewItemsInventory extends \E10\TableViewGrid
{
	var $docTypes;
	var $restQuantity = 0.0;
	var $restPrice = 0.0;

	public function init ()
	{
		//$this->rowsPageSize = 250;
		//$this->rowsFirst = $this->rowsPageNumber * $this->rowsPageSize;

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->disableFullTextSearchInput = TRUE;

		if ($this->queryParam ('item'))
			$this->addAddParam ('item', $this->queryParam ('item'));

		$this->docTypes = $this->app()->cfgItem ('e10.docs.types');

		$this->topParams = new \e10doc\core\libs\GlobalParams ($this->table->app());
		$this->topParams->addParam ('fiscalYear', 'queryFiscalYear', ['colWidth' => 3]);
		$this->topParams->addParam ('warehouse', 'queryWarehouse', ['colWidth' => 2]);

		parent::init();

		// -- grid
		$g = array ('#' => '#',
			'dateAccounting' => ' Datum',
			'docNumberLink' => '_Doklad',
			'qin' => ' Příjem',
			'qout' => ' Výdej',
			'qrest' => ' Zůst.množ.',
			'prest' => ' Zůst.cena',
			'uprice' => ' ⌀ cena/j.',
		);

		$this->setGrid ($g);
		$this->setInfo('title', 'Pohyby zásoby');
		$this->setInfo('icon', 'icon-cubes');

		$this->restQuantity = 0.0;
		$this->restPrice = 0.0;
		$fp = $this->app()->testGetParam('viewerFlowParams');
		if ($fp !== '' && $this->rowsPageNumber)
		{
			$fpData = json_decode(base64_decode($fp), TRUE);
			if (isset($fpData['restQuantity']))
				$this->restQuantity = $fpData['restQuantity'];
			if (isset($fpData['restPrice']))
				$this->restPrice = $fpData['restPrice'];
		}
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndxHead'];
		$listItem ['dateAccounting'] = $item ['dateAccounting'];

		if ($item['moveTypeOrder'] == Inventory::mtoInitState)
		{
			$listItem ['qin'] = $item ['journalQuantity'];
			$this->restQuantity += $item ['journalQuantity'];
			$this->restPrice += $item ['invPriceAll'];
			$listItem ['class'] = 'e10-row-this';
		}
		else
		if ($item['moveType'] == Inventory::mtIn)
		{
			$listItem ['qin'] = $item ['journalQuantity'];
			$this->restQuantity += $item ['journalQuantity'];
			$this->restPrice += $item ['invPriceAll'];
			if ($item['quantity'] < 0.0)
			{
				$listItem['_options']['cellClasses']['qout'] = 'e10-error';
				$listItem ['qout'] = - $item ['journalQuantity'];
				unset ($listItem ['qin']);
			}
		}
		else
		if ($item['moveType'] == Inventory::mtOut)
		{
			$listItem ['qout'] = - $item ['journalQuantity'];
			$this->restQuantity += $item ['journalQuantity'];
			$this->restPrice += $item ['invPriceAll'];
			if ($item['quantity'] < 0.0)
			{
				$listItem['_options']['cellClasses']['qin'] = 'e10-error';
				$listItem ['qin'] = $item ['journalQuantity'];
				unset($listItem ['qout']);
			}
		}

		$listItem ['qrest'] = round($this->restQuantity, 4);
		$listItem ['prest'] = round($this->restPrice, 4);
		if ($this->restQuantity != 0.0)
			$listItem ['uprice'] = round($this->restPrice / $this->restQuantity, 2);

		if ($this->restQuantity < 0.0)
			$listItem ['class'] = 'e10-row-minus';

		$docType = $this->docTypes [$item['docType']];
		$listItem ['docNumberLink'] = [
			'text'=> $item ['docNumber'], 'docAction' => 'edit', 'icon' => $docType ['icon'],
			'title' => $docType['shortName'].': '.$item ['personName'],
			'table' => 'e10doc.core.heads', 'pk'=> $item ['ndxHead']
		];

		return $listItem;
	}

	public function selectRows ()
	{
		$q[] = 'SELECT [rows].ndx as ndx, [rows].item as item, [rows].quantity as quantity, [rows].taxBase as taxBase,';
		array_push($q, ' [rows].priceItem as priceItem, persons.fullName as personName, [rows].invDirection as rowsInvDirection,');
		array_push($q, ' heads.docNumber as docNumber, heads.dateAccounting as dateAccounting, heads.docType as docType,');
		array_push($q, ' heads.ndx as ndxHead, journal.price as invPriceAll, ');
		array_push($q, ' journal.quantity as journalQuantity, journal.moveTypeOrder, journal.moveType');
		array_push($q, ' FROM e10doc_core_rows as [rows]');
		array_push($q, ' LEFT JOIN e10doc_core_heads as heads ON (heads.ndx = [rows].document)');
		array_push($q, ' LEFT JOIN e10doc_inventory_journal as journal ON (journal.docHead = [rows].document AND journal.docRow = [rows].ndx)');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON (heads.person = persons.ndx)');
		array_push($q, ' WHERE [rows].item = %i', $this->queryParam ('item'),' AND heads.docState = 4000');


		if (isset ($this->topParamsValues['queryFiscalYear']['value']))
		{
			$this->setInfo('param', 'Období', $this->topParamsValues['queryFiscalYear']['activeTitle']);
			array_push($q, ' AND heads.fiscalYear = %i', $this->topParamsValues['queryFiscalYear']['value']);
		}

		if (isset ($this->topParamsValues['queryWarehouse']['value']) && $this->topParamsValues['queryWarehouse']['value'] != 0)
		{
			$this->setInfo('param', 'Sklad', $this->topParamsValues['queryWarehouse']['activeTitle']);
			array_push($q, ' AND heads.warehouse = %i', $this->topParamsValues['queryWarehouse']['value']);
		}

		array_push($q, ' ORDER BY heads.dateAccounting, journal.moveTypeOrder, [rows].ndx');
		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return array();
	}

	public function flowParams()
	{
		$fp = ['restQuantity' => $this->restQuantity, 'restPrice' => $this->restPrice];
		return $fp;
	}
}


/**
 * Detail skladových pohybů položky
 *
 * Class ViewDetailItemsInventory
 * @package E10Doc\Inventory
 */
class ViewDetailItemsInventory extends \E10\TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10doc.core.heads', 'e10doc.inventory.ViewItemsInventory',
			array ('item' => $this->item ['ndx']));
	}
}


