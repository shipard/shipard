<?php

namespace e10doc\inventory\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/inventory/inventory.php';

use \Shipard\Base\Utility, \e10doc\core\libs\E10Utils, e10doc\inventory\Inventory;


/**
 * class InventoryStatesEngine
 */
class InventoryStatesEngine extends Utility
{
	var $documentHead;
	var $mnfSupport = FALSE;


	function createInventoryJournal ($fiscalYear)
	{
		$this->db()->begin ();

		$this->deleteYear($fiscalYear);
		$this->addJournalRows($fiscalYear);
		$this->recalcPrices($fiscalYear);

		$this->db()->commit ();
	}

	function clearDocumentRows ()
	{
		$q = "DELETE FROM [e10doc_inventory_journal] WHERE [docHead] = %i";
		$this->db()->query ($q, $this->documentHead['ndx']);
	}

	function deleteYear ($year)
	{
		$q = "DELETE FROM [e10doc_inventory_journal] WHERE [fiscalYear] = $year OR [fiscalYear] = 0";
		$this->db()->query ($q);
	}

	function addJournalRows ($year)
	{
		if ($this->app->model()->module('e10doc.mnf') !== FALSE)
			$this->mnfSupport = TRUE;

		$app = $this->app;
		$docQry = '';
		if (isset ($this->documentHead))
			$docQry = 'AND [rows].document = '.$this->documentHead['ndx'];

		// --- initial states
		$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow)
					SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity, [rows].taxBase, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
					where [rows].document = heads.ndx $docQry and heads.docType = 'stockinst' AND heads.docState = 4000 AND [rows].invDirection = 1 AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
		$app->db()->query ($q, Inventory::mtIn, Inventory::mtoInitState, $year);

		$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow)
			SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity, [rows].taxBase, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
				where [rows].document = heads.ndx $docQry and heads.docType = 'stockin' AND heads.docState = 4000 AND heads.initState = 1 AND [rows].invDirection = 1 AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
		$app->db()->query ($q, Inventory::mtIn, Inventory::mtoInitState, $year);

		// --- in
		$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow)
			SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity, [rows].invPrice, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
				where [rows].document = heads.ndx $docQry AND heads.docState = 4000 AND heads.initState = 0
				AND (heads.docType IN ('stockin', 'purchase', 'invni', 'cash') AND [rows].invDirection = 1 AND [rows].quantity > 0)
				AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
		$app->db()->query ($q, Inventory::mtIn, Inventory::mtoIn, $year);

		$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow)
			SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity*-1, [rows].taxBase*-1, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
				where [rows].document = heads.ndx $docQry AND heads.docState = 4000 AND heads.initState = 0
				AND (heads.docType in ('stockout', 'cashreg', 'invno') AND [rows].invDirection = -1 AND [rows].quantity < 0)
				AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
		$app->db()->query ($q, Inventory::mtIn, Inventory::mtoIn, $year);

		if ($this->mnfSupport)
		{
			// výroba - montáž - příjem
			$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow)
				SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity, [rows].taxBase, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
					where [rows].document = heads.ndx $docQry AND heads.docState = 4000 AND heads.initState = 0
					AND (heads.docType = 'mnf' AND heads.mnfType = 0 AND [rows].invDirection = 1 AND [rows].quantity > 0)
					AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
			$app->db()->query ($q, Inventory::mtIn, Inventory::mtoMnfInAssembly, $year);

			// výroba - řádky montáž - výdej
			$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow, docRowOwner)
				SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity*-1, 0, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx, [rows].ownerRowMain FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
					where [rows].document = heads.ndx $docQry
					AND heads.docType = 'mnf' AND heads.mnfType = 0 AND [rows].invDirection = -1 AND [rows].quantity > 0
					AND heads.docState = 4000 AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
			$app->db()->query ($q, Inventory::mtOut, Inventory::mtoMnfOutAssembly, $year);
		}

		// --- out
		$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow)
			SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity*-1, 0, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
				where [rows].document = heads.ndx $docQry
				AND (heads.docType in ('stockout', 'cashreg', 'invno') AND [rows].invDirection = -1 AND [rows].quantity > 0)
				AND heads.docState = 4000 AND heads.initState = 0 AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
		$app->db()->query ($q, Inventory::mtOut, Inventory::mtoOut, $year);

		$q = "INSERT INTO [e10doc_inventory_journal] (moveType, moveTypeOrder, warehouse, item, unit, quantity, price, [date], [fiscalYear], docHead, docRow)
			SELECT %i, %i, heads.warehouse, [rows].item, [rows].unit, [rows].quantity, 0, heads.dateAccounting, $year, heads.ndx as ndxHead, [rows].ndx FROM e10doc_core_rows AS [rows], e10doc_core_heads as heads
				where [rows].document = heads.ndx $docQry
				AND (heads.docType IN ('stockin', 'purchase', 'invni') AND [rows].invDirection = 1 AND [rows].quantity < 0)
				AND heads.docState = 4000 AND heads.initState = 0 AND heads.fiscalYear = %i ORDER BY heads.dateAccounting, [rows].ndx";
		$app->db()->query ($q, Inventory::mtOut, Inventory::mtoOut, $year);
	}

	function recalcPricesAvg ($year, $warehouse)
	{
		$q = "SELECT * FROM e10_witems_items order by ndx";
		$rows = $this->db()->query ($q);

		forEach ($rows as $r)
		{
			$m = $r['ndx'];
			$aktCena [$m] = 0;
			$prumCena [$m] = 0;
			$aktMnozstvi [$m] = 0;
			$zapor [$m] = 0;
		}

		$q = "SELECT * FROM [e10doc_inventory_journal] WHERE [fiscalYear] = %i AND warehouse = %i ORDER BY [date], moveTypeOrder, ndx";
		$rows = $this->db()->query ($q, $year, $warehouse);
		forEach ($rows as $r)
		{
			$m = $r['item'];
			if ($m == 0)
				continue;
			switch ($r ['moveTypeOrder'])
			{
				case Inventory::mtoInitState:
				case Inventory::mtoIn:
					if ($zapor [$m])
					{

					}
					else
					{
						$ac = $aktCena [$m];
						if (($aktMnozstvi [$m] + $r['quantity']) != 0.0)
							$prumCena [$m] = abs (round (($ac + $r['price']) / ($aktMnozstvi [$m] + $r['quantity']), 2));
					}
					$aktCena [$m] += $r['price'];
					$aktMnozstvi [$m] += round ($r['quantity'], 4);
					break;
				case Inventory::mtoMnfOutAssembly:
				case Inventory::mtoMnfOutDisassembly:
				case Inventory::mtoOut:
					if (round($aktMnozstvi [$m] + $r['quantity'], 4) < 0.0)
					{
						//error_log ("======= MINUS: $m " . \E10\df ($r['date']));
						if ($zapor [$m] == 0)
						{
							$q = "SELECT SUM(quantity) as quantity, SUM(price) as price FROM [e10doc_inventory_journal] WHERE moveTypeOrder < %i AND [item] = $m AND [fiscalYear] = $year AND warehouse = $warehouse";
							$rocniObraty = $this->db()->query ($q, Inventory::mtoMnfOutAssembly)->fetch ();
							if ($rocniObraty['quantity'] != 0)
								$prumCena [$m] = abs (round ($rocniObraty['price'] / $rocniObraty['quantity'], 2));
							else
								$prumCena [$m] = 0.0;
						}
						$zapor [$m] = 1;
					}
					$tatoCena = abs($prumCena[$m] * $r['quantity']) * -1;
					if (round($aktMnozstvi [$m] + $r['quantity'], 4) == 0.0)
					{
						$tatoCena = $aktCena [$m] * -1;
						//$prumCena [$m] = 0.0;
					}
					$tatoCenaZ = round ($tatoCena, 2);
					$q = "UPDATE [e10doc_inventory_journal] SET price = $tatoCenaZ WHERE ndx = {$r['ndx']}";
					$this->db()->query ($q);

					// -- update document row - invPriceAcc
					$this->db()->query ('UPDATE [e10doc_core_rows] SET [invPriceAcc] = ?', -$tatoCenaZ, ' WHERE [ndx] = %i', $r['docRow']);

					$aktCena [$m] += $tatoCenaZ;
					$aktMnozstvi [$m] += round ($r['quantity'], 4);

					// TODO: Inventory::mtoMnfOutDisassembly - vypočítat příjmové ceny jednotlivých řádků poměrem
					break;
				case Inventory::mtoMnfInAssembly:
					// nacist soucet cen z vydeju na vyrobe
					$q = "SELECT SUM(quantity) as quantity, SUM(price) as price FROM [e10doc_inventory_journal] WHERE moveTypeOrder = %i AND [docHead] = {$r['docHead']} AND docRowOwner = {$r['docRow']}";
					$vyroba = $this->db()->query ($q, Inventory::mtoMnfOutAssembly)->fetch ();
					if ($vyroba)
					{
						$tatoCena = abs($vyroba ['price']);
						$tatoCenaZ = round ($tatoCena, 2);

						$q = "UPDATE [e10doc_inventory_journal] SET price = $tatoCenaZ WHERE ndx = {$r['ndx']}";
						$this->db()->query ($q);

						$aktCena [$m] += $tatoCenaZ;
						$ac = $aktMnozstvi [$m] * $prumCena [$m];
						if (($aktMnozstvi [$m] + $r['quantity']) > 0)
							$prumCena [$m] = abs (round (($ac + $tatoCenaZ) / ($aktMnozstvi [$m] + $r['quantity']), 2));
						$aktMnozstvi [$m] += round ($r['quantity'], 4);
					}
					break;
			} // switch ($r ['moveTypeOrder'])
		}
	} // recalcPricesAvg

	function recalcPricesAvgYear ($fiscalYear, $warehouse)
	{
		$q[] = 'SELECT';
		array_push($q, ' item, unit, SUM(quantity) as quantity, SUM(price) as price');
		array_push($q, ' FROM [e10doc_inventory_journal] ');
		array_push($q, ' WHERE warehouse = %i', $warehouse, ' AND fiscalYear = %i', $fiscalYear);
		array_push($q, ' AND moveTypeOrder IN %in', [Inventory::mtoIn, Inventory::mtoInitState]);
		array_push($q, ' GROUP BY item, unit');

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$price = 0.0;
			if ($r ['quantity'] != 0.0)
			{
				$price = abs(round ($r['price'] / $r ['quantity'], 2));
			}
			$this->db()->query (
				'UPDATE e10doc_inventory_journal SET price = ', "(quantity * $price)",
				' WHERE item = %i', $r['item'], ' AND [unit] = %s', $r['unit'],
				' AND moveTypeOrder = %i', Inventory::mtoOut,
				' AND fiscalYear = %i', $fiscalYear, ' AND warehouse = %i', $warehouse
			);
		}

		// -- check zero
		unset ($q);
		$q[] = 'SELECT SUM(quantity) as quantity, SUM(price) as price, item, unit FROM [e10doc_inventory_journal]';
		array_push ($q, ' WHERE [fiscalYear] = %i', $fiscalYear);
		array_push ($q, ' GROUP BY item, unit');
		array_push ($q, ' HAVING SUM(quantity) = 0 AND SUM(price) != 0');
		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$last = $this->db()->query (
				'SELECT * FROM [e10doc_inventory_journal] WHERE [item] = %i', $r['item'], ' AND [unit] = %s', $r['unit'],
				' AND moveTypeOrder = %i', Inventory::mtoOut, ' AND [fiscalYear] = %i', $fiscalYear,
				' ORDER BY [date] DESC LIMIT 0, 1'
			)->fetch();
			if ($last)
			{
				$this->db()->query('UPDATE [e10doc_inventory_journal] SET price = price - ' . $r['price'], ' WHERE ndx = %i', $last['ndx']);
			}
		}
	}

	function recalcPrices ($fiscalYear)
	{
		$warehouses = $this->app->cfgItem ('e10doc.warehouses', []);
		foreach ($warehouses as $w)
		{
			$options = E10Utils::warehouseOptions ($this->app, $w['ndx'], $fiscalYear);
			if ($options['calcPrices'] == 0)
				$this->recalcPricesAvg($fiscalYear, $w['ndx']);
			else
				if ($options['calcPrices'] == 1)
					$this->recalcPricesAvgYear($fiscalYear, $w['ndx']);
		}
	}

	public function setDocument ($docRecData)
	{
		$this->documentHead = $docRecData;
	}

	public function createDocumentJournal ()
	{
		$year = $this->documentHead['fiscalYear'];
		$this->addJournalRows($year);

		$rows = $this->app()->db ()->query ('SELECT docRow, price FROM [e10doc_inventory_journal] WHERE docHead = %i', $this->documentHead['ndx']);
		foreach ($rows as $r)
		{
		//	$this->app()->db ()->query ('UPDATE [e10doc_core_rows] SET [invPriceAcc] = ?', $r['price'], ' WHERE ndx = %i', $r['docRow']);
		}
	}

	public function resetAllStates()
	{
		$q[] = 'SELECT * FROM [e10doc_base_fiscalyears]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [start] IS NOT NULL AND [end] IS NOT NULL');
		array_push ($q, ' AND docState = %i', 4000);
		array_push ($q, ' ORDER BY [start]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->createInventoryJournal($r['ndx']);
		}
	}
}
