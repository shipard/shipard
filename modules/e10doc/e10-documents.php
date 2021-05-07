#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once 'e10-modules/e10/server/php/e10-cli.php';
require_once 'e10-modules/e10/web/web.php';
require_once 'e10-modules/e10/base/base.php';

require_once 'e10-modules/e10doc/balance/balance.php';
require_once 'e10-modules/e10doc/debs/debs.php';
require_once 'e10-modules/e10doc/contracts/sale/sale.php';


use \E10\CLI\Application, \E10\DataModel;


/*
 * itemsBuyPrices
 */

class itemsBuyPrices extends \E10\Utility
{
	public $itemBuyPrices = array ();
	public $salePriceCoef = 0.8;

	public function clear ()
	{
		$qh = "UPDATE e10_witems_items SET priceBuy = 0";
		$this->db()->query ($qh);
	}

	public function setItemPricesFromBuy ()
	{
		$q = "SELECT rows.item as item, SUM(rows.quantity) as quantity, SUM(rows.taxBase) as totalPrice, ROUND(SUM(rows.taxBase)/SUM(rows.quantity), 2) as unitPrice
					FROM e10doc_core_rows as rows, e10doc_core_heads as heads
					WHERE rows.document = heads.ndx and heads.docType = 'stockin' AND
								heads.docState = 4000 AND heads.initState = 0 AND rows.invDirection = 1
					GROUP BY rows.item";
		$rows = $this->db()->query ($q);
		$this->db()->begin();
		forEach ($rows as $r)
		{
			if ($r['unitPrice'] != 0)
			{
				$this->db()->query ("UPDATE e10_witems_items SET priceBuy = %f WHERE ndx = %i", $r['unitPrice'], $r['item']);
				$this->itemBuyPrices [$r['item']] = $r['unitPrice'];
			}
		}
		$this->db()->commit();
	}

	public function setItemPricesFromSaleDocs ()
	{
		$q = "SELECT rows.item as item, SUM(rows.quantity) as quantity, SUM(rows.taxBase) as totalPrice, ROUND(SUM(rows.taxBase)/SUM(rows.quantity), 2) as unitPrice
					FROM e10doc_core_rows as rows, e10doc_core_heads as heads
					WHERE rows.document = heads.ndx and heads.docType in ('cashreg', 'invno') AND
								heads.docState = 4000 AND heads.initState = 0 AND rows.invDirection = -1
					GROUP BY rows.item";
		$rows = $this->db()->query ($q);
		$this->db()->begin();
		forEach ($rows as $r)
		{
			if ($r['unitPrice'] != 0)
			{
				if (!isset($this->itemBuyPrices [$r['item']]))
				{
					$unitPrice = round($r['unitPrice'] * $this->salePriceCoef, 2);
					$this->db()->query ("UPDATE e10_witems_items SET priceBuy = %f WHERE ndx = %i",
																$unitPrice, $r['item']);
					$this->itemBuyPrices [$r['item']] = $unitPrice;
				}
			}
		}
		$this->db()->commit();
	}

	public function setItemPricesFromSaleItems ()
	{
		$q = "SELECT ndx as item, priceSell as unitPrice FROM e10_witems_items";
		$rows = $this->db()->query ($q);
		$this->db()->begin();
		forEach ($rows as $r)
		{
			if ($r['unitPrice'] != 0)
			{
				if (!isset($this->itemBuyPrices [$r['item']]))
				{
					$unitPrice = round($r['unitPrice'] * $this->salePriceCoef, 2);
					$this->db()->query ("UPDATE e10_witems_items SET priceBuy = %f WHERE ndx = %i",
									$unitPrice, $r['item']);
					$this->itemBuyPrices [$r['item']] = $unitPrice;
					//echo ("item {$r['item']} -> {$unitPrice} \n");
				}
			}
		}
		$this->db()->commit();
	}

	public function setZeroInitStatesPrices ()
	{
		$q = "SELECT rows.item as itemNdx, rows.ndx as rowNdx, rows.`text`, rows.quantity as q
					FROM e10doc_core_rows as rows, e10doc_core_heads as heads
					WHERE rows.document = heads.ndx and heads.docType = 'stockin' AND
								heads.docState = 4000 AND heads.initState = 1 AND rows.invDirection = 1 AND YEAR(heads.dateAccounting) = 2012
				 ";
		$rows = $this->db()->query ($q);
		$this->db()->begin();
		forEach ($rows as $r)
		{
			if (isset($this->itemBuyPrices [$r['itemNdx']]))
			{
				$this->db()->query ("UPDATE e10doc_core_rows SET 
														priceItem = %f, priceAll = %f, taxBase = %f
														WHERE ndx = %i",
																$this->itemBuyPrices [$r['itemNdx']],
																$this->itemBuyPrices [$r['itemNdx']] * $r['q'],
																$this->itemBuyPrices [$r['itemNdx']] * $r['q'],
																$r['rowNdx']);

				//echo (dibi::$sql . "\n");
			}
		}
		$this->db()->commit();
	}

	public function run ()
	{
		$coefParam = $this->app->arg ('coef');
		if ($coefParam !== FALSE)
			$this->salePriceCoef = floatval ($coefParam);

		echo ("coef is {$this->salePriceCoef} \n");

		$this->clear();
		$this->setItemPricesFromBuy();
		$this->setItemPricesFromSaleDocs();
		$this->setItemPricesFromSaleItems();
		$this->setZeroInitStatesPrices();
	}
} // itemsBuyPrices


/**
 * Class UpgradeApp
 */

class UpgradeApp extends Application
{
	public function docsQuery ()
	{
		$q = "SELECT * FROM [e10doc_core_heads] ORDER BY ndx";
		return $q;
	}

	function recalcOneDoc ($ndx)
	{
		$tableDocs = $this->table ('e10doc.core.heads');
		$f = $tableDocs->getTableForm ('edit', $ndx);

		$tableRows = $this->table ('e10doc.core.rows');

		$q = "SELECT * FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY ndx";
		$rows = $this->db()->query ($q, $f->recData ['ndx']);
		forEach ($rows as $r)
		{
			$tableRows->dbUpdateRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$tableDocs->dbUpdateRec ($f->recData);
	}

	public function recalc ()
	{
		$q = $this->docsQuery();
		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			echo ("* {$r['docNumber']}\n");
			$this->recalcOneDoc ($r ['ndx']);
		}
	}

	public function checkDocsItemBalances ()
	{
		$q = "SELECT heads.docType as docType, rows.ndx as rowNdx, rows.item, rows.itemBalance as docBalance, items.[useBalance] as itemBalance
					FROM e10doc_core_rows as rows
					LEFT JOIN e10_witems_items as items ON rows.item = items.ndx
					LEFT JOIN e10doc_core_heads as heads ON rows.document = heads.ndx
					WHERE rows.itemBalance != items.[useBalance]";

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$itemBalance = $r['itemBalance'];
			$docBalance = $r ['docBalance'];

			echo ("* {$r['docBalance']} => {$r['itemBalance']}\n");

			$this->db()->query ("UPDATE [e10doc_core_rows] SET itemBalance = %i", $itemBalance, "WHERE [ndx] = %i", $r ['rowNdx']);
		}
	}

	public function checkDocsItemTypes ()
	{
		$doIt = $this->arg('run');

		$q = "SELECT heads.docType as docType, heads.docNumber as headDocNumber, heads.docType as headDocType, 
					rows.ndx as rowNdx, rows.item, rows.itemType as rowType, items.[type] as itemType
					FROM e10doc_core_rows as rows
					LEFT JOIN e10_witems_items as items ON rows.item = items.ndx
					LEFT JOIN e10doc_core_heads as heads ON rows.document = heads.ndx
					WHERE (rows.itemType != items.[type] OR rows.itemType IS NULL)";

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$itemType = Application::cfgItem ('e10.witems.types.' . $r['itemType'], NULL);
			if ($itemType === NULL)
			{
				echo ("* ERROR: Unknown item type {$r['itemType']}\n");
				continue;
			}
			if (!isset($itemType['kind']))
			{
				echo ("* ERROR: Bad item type {$r['itemType']}\n");
				continue;
			}

			$docType = Application::cfgItem ('e10.docs.types.' . $r ['docType'], NULL);

			echo ("* {$r['headDocNumber']} / {$r['headDocType']}: {$r['rowType']} => {$r['itemType']}\n");

			$newRow = array ('itemType' => $r['itemType'], 'invDirection' => 0);

			if ($itemType['kind'] == 1)
			{
				if ($docType && isset($docType['invDirection']))
					$newRow ['invDirection'] = $docType['invDirection'];
			}

			if ($doIt)
				$this->db()->query ("UPDATE [e10doc_core_rows] SET ", $newRow, "WHERE [ndx] = %i", $r ['rowNdx']);
		}

		if (!$doIt)
			echo "Use --run param to make changes...\n";
	}

	public function checkDocsItemUnits ()
	{
		$q = "SELECT heads.docType as docType, rows.ndx as rowNdx, rows.item, rows.unit as badItemUnit, items.[defaultUnit] as goodItemUnit
					FROM e10doc_core_rows as rows
					LEFT JOIN e10_witems_items as items ON rows.item = items.ndx
					LEFT JOIN e10doc_core_heads as heads ON rows.document = heads.ndx
					WHERE rows.unit != items.[defaultUnit]";

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			echo ("* {$r['badItemUnit']} => {$r['goodItemUnit']}\n");

			$newRow = array ('unit' => $r['goodItemUnit']);

			$this->db()->query ("UPDATE [e10doc_core_rows] SET ", $newRow, "WHERE [ndx] = %i", $r ['rowNdx']);
		}
	}

	public function checkDocsVATINs ()
	{
		$q = "SELECT recid, valueString FROM [e10_base_properties] where [group] = 'ids' AND property = 'taxid' AND valueString != ''";

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			echo ("* {$r['valueString']}\n");

			$this->db()->query ("UPDATE [e10doc_core_heads] SET personVATIN = %s WHERE personVATIN = '' AND [person] = %i",
													$r ['valueString'], $r ['recid']);
		}
	}

	public function checkItems ()
	{
		$q = "SELECT * FROM [e10_witems_items] ORDER BY [ndx]";

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$itemType = Application::cfgItem ('e10.witems.types.' . $r['type'], NULL);
			if (!$itemType)
				echo ("* WRONG ITEM TYPE: {$r['fullName']}\n");
			//echo ("* {$r['fullName']}\n");
		}
	}

	public function recalcItemsBuyPrices ()
	{
		$h = new itemsBuyPrices ($this);
		$h->run ();
	}

	public function reAccounting ()
	{
		$year = intval ($this->arg('year'));

		$q = "SELECT * FROM e10doc_core_heads as heads WHERE docType in ('invni', 'invno', 'bank', 'cash', 'cashreg', 'cmnbkp', 'purchase') AND heads.docState = 4000";
		if ($year != 0)
			$q .= " AND YEAR(dateAccounting) = $year";
		$q .= ' ORDER BY dateAccounting, activateTimeLast, ndx';

		$rows = $this->db()->query ($q);

		$this->db()->begin();

		if ($year != 0)
			$this->db()->query ("DELETE FROM [e10doc_debs_journal] WHERE YEAR(dateAccounting) = $year");
		else
			$this->db()->query ("DELETE FROM [e10doc_debs_journal]");

		forEach ($rows as $r)
		{
			$docAccEngine = new \E10Doc\Debs\docAccounting ($this);
			$docAccEngine->setDocument ($r);
			$docAccEngine->run();
			$docAccEngine->save();

			if ($docAccEngine->messagess() !== FALSE)
			{
				echo ("* {$r['docNumber']} ({$r['docType']}) ");
				$this->err($docAccEngine->messagess());
				$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = 9 WHERE ndx = %i", $r['ndx']);
			}
			else
				$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = 1 WHERE ndx = %i", $r['ndx']);

			unset ($docAccEngine);
		}
		$this->db()->commit();
	}

	public function setUsersGroups ()
	{
		\lib\docs\PersonsGroupsSetter::runAll ($this);
	}

	public function checkVAT ()
	{
		$year = intval ($this->arg('year'));

		$q = "SELECT * FROM e10doc_core_heads as heads WHERE docType in ('invni', 'invno', 'cash', 'cashreg') AND heads.docState = 4000 AND taxCalc = 2";
		if ($year != 0)
			$q .= " AND YEAR(dateAccounting) = $year";
		$q .= ' ORDER BY dateAccounting, ndx';

		$cnt = 0;
		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$qmr = "SELECT SUM(taxBaseHc) as sumBaseHc, SUM(taxBaseHcCorr) as taxBaseHcCorr FROM [e10doc_core_rows] WHERE [document] = %i";
			$sum = $this->db()->query ($qmr, $r['ndx'])->fetch ();
			if (!$sum)
			{
				echo "!NO LINES!\n";
				continue;
			}

			$sumBaseHcFromRows = round($sum ['sumBaseHc'], 2);
			$sumBaseHcCorr = round($r ['sumBaseHc'] - $sumBaseHcFromRows, 2);

			$subBaseRowsCorrected = round($sum ['sumBaseHc'] + $sum['taxBaseHcCorr'], 2);

			if ($r['sumBaseHc'] != $subBaseRowsCorrected)
			{
				echo " # {$r['docType']} - {$r['docNumber']} [{$r['sumBaseHc']}/{$sum ['sumBaseHc']} = $subBaseRowsCorrected]: $sumBaseHcCorr ({$sum['taxBaseHcCorr']}) \n";
				$this->recalcOneDoc($r['ndx']);
				$cnt++;
			}
		}

		echo "TOTAL: $cnt documents \n";
	}

	public function run ()
	{
		switch ($this->command ())
		{
			case	"recalc":										return $this->recalc ();
			case	"checkItems":								return $this->checkItems ();
			case	"checkDocsItemBalances":		return $this->checkDocsItemBalances ();
			case	"checkDocsItemTypes":				return $this->checkDocsItemTypes ();
			case	"checkDocsItemUnits":				return $this->checkDocsItemUnits ();
			case	"checkDocsVATINs":					return $this->checkDocsVATINs ();
			case	"reAccounting":							return $this->reAccounting ();
			case	"balance":									return \E10Doc\Balance\balanceRecalc ($this);

			case	"recalcBuyPrices":					return $this->recalcItemsBuyPrices ();
			case	"setUsersGroups":						return $this->setUsersGroups ();

			case	"checkVAT":					return $this->checkVAT ();
		}
		echo ("unknown or nothing param...\r\n");
	}
}

$myApp = new UpgradeApp ($argv);
$startTime = time();
$myApp->run ();
$endTime = time();


echo ("DONE in ".($endTime-$startTime)." secs.\n");
