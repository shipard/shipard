<?php

namespace e10doc\stockInitStates\libs;
use \e10doc\core\libs\E10Utils;


class InitStatesEngine extends \Shipard\Base\Utility
{
	var $fiscalYearRecData;

	function createInitStateDocHead ($fiscalYear, $warehouse)
	{
		$this->fiscalYearRecData = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalyears] WHERE ndx = %i", $fiscalYear)->fetch();

		$docDate = $this->fiscalYearRecData['start']->format('Y-m-d');
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);

		// -- kontrola existujícího dokladu
		$q = "SELECT * FROM [e10doc_core_heads] WHERE [docType] = 'stockinst' AND [dateAccounting] = %d AND [warehouse] = %i";
		$test = $this->app->db()->query ($q, $docDate, $warehouse)->fetch();
		if ($test)
		{
			$initStateDoc = $tableDocs->loadItem ($test['ndx']);
			return $initStateDoc;
		}

		$docH = array ();
		$docH ['docType']						= 'stockinst';
		$docH ['warehouse']					= $warehouse;

		$tableDocs->checkNewRec ($docH);
		$docH ['dateAccounting']		= $docDate;
		$docH ['dateIssue']					= $docDate;
		$docH ['person'] = $docH ['owner'];

		$docNdx = $tableDocs->dbInsertRec ($docH);

		$f = $tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$tableDocs->dbUpdateRec ($f->recData);

		return $f->recData;
	}

	function createInitStateRows ($fiscalYear, $warehouse, $head)
	{
		$pastPeriod = $this->app->db()->query("SELECT * FROM [e10doc_base_fiscalyears] WHERE end <= %d AND docState != 9800 ORDER BY [end] DESC", $this->fiscalYearRecData['start'])->fetch ();
		if (!$pastPeriod)
			return;
		$pastFiscalYear = $pastPeriod['ndx'];

		$solveSets = 1;

		$headNdx = $head['ndx'];
		$tableRows = new \E10Doc\Core\TableRows ($this->app);

		// -- delete old lines
		$this->app->db()->query ("DELETE FROM e10doc_core_rows WHERE [document] = %i", $headNdx);

		// -- create new lines
		foreach ([0, 1] as $stage)
		{
			$q = [];
			if ($stage === 0)
			{
				array_push($q, 'SELECT items.fullName as itemFullName, items.defaultUnit as itemUnit, items.[type] as itemType,');
				array_push($q, ' SUM(journal.quantity) as quantity, SUM(journal.price) as price, item ');
				array_push($q, ' FROM [e10doc_inventory_journal] as journal');
				array_push($q, ' RIGHT JOIN e10_witems_items as items on (items.ndx = journal.item)');
				array_push($q, ' WHERE [fiscalYear] = %i', $pastFiscalYear);
				array_push($q, ' AND [journal].warehouse = %i', $warehouse);
				array_push($q, ' AND (items.successorItem = %i', 0, ' OR items.successorItem IS NULL)');
				array_push($q, ' GROUP BY item');
			}
			elseif ($stage === 1)
			{
				array_push($q, 'SELECT successors.fullName as itemFullName, successors.defaultUnit as itemUnit, successors.[type] as itemType,');
				array_push($q, ' SUM(journal.quantity) as quantity, SUM(journal.price) as price, successors.ndx AS item');
				array_push($q, ' FROM [e10doc_inventory_journal] as journal');
				array_push($q, ' RIGHT JOIN e10_witems_items as items on (items.ndx = journal.item)');
				array_push($q, ' LEFT JOIN e10_witems_items as successors on (items.successorItem = successors.ndx)');
				array_push($q, ' WHERE [fiscalYear] = %i', $pastFiscalYear);
				array_push($q, ' AND [journal].warehouse = %i', $warehouse);

				array_push($q, ' AND (items.successorItem != %i', 0, ' AND items.successorItem IS NOT NULL',
					' AND items.successorDate IS NOT NULL AND items.successorDate > %d', $pastPeriod['end'],
					')');

				array_push($q, ' GROUP BY items.successorItem');
			}
			$rows = $this->app->db()->query($q);

			forEach ($rows as $r)
			{
				if ($r ['quantity'] == 0)
					continue;

				$priceItem = round($r['price'] / $r['quantity'], 2);
				$roundedTotalPrice = round($priceItem * $r['quantity'], 2);

				if ($r['price'] !== $roundedTotalPrice && $r['quantity'] > 1)
				{
					$newRow ['document'] = $headNdx;
					$newRow ['item'] = $r['item'];
					$newRow ['itemType'] = $r['itemType'];
					$newRow ['text'] = $r['itemFullName'];
					$newRow ['unit'] = $r['itemUnit'];
					$newRow ['quantity'] = $r['quantity'] - 1;
					$newRow ['priceItem'] = round($r['price'] / ($r['quantity'] - 1), 2);
					$usedTotalPrice = round($newRow ['quantity'] * $newRow ['priceItem'], 2);
					$tableRows->dbInsertRec($newRow, $head);
					unset ($newRow);

					$newRow ['document'] = $headNdx;
					$newRow ['item'] = $r['item'];
					$newRow ['itemType'] = $r['itemType'];
					$newRow ['text'] = $r['itemFullName'];
					$newRow ['unit'] = $r['itemUnit'];
					$newRow ['quantity'] = 1;
					$newRow ['priceItem'] = round($r['price'] - $usedTotalPrice, 2);
					$tableRows->dbInsertRec($newRow, $head);
				}
				else
				{
					$newRow ['document'] = $headNdx;
					$newRow ['item'] = $r['item'];
					$newRow ['itemType'] = $r['itemType'];
					$newRow ['text'] = $r['itemFullName'];
					$newRow ['unit'] = $r['itemUnit'];
					$newRow ['quantity'] = $r['quantity'];
					$newRow ['priceItem'] = round($r['price'] / $r['quantity'], 2);

					$tableRows->dbInsertRec($newRow, $head);
				}
				unset ($newRow);
			}
		}

		if ($solveSets)
		{
			$sirQuery = [];
			array_push($sirQuery, 'SELECT [sets].*,');
			array_push($sirQuery, ' items.fullName as itemFullName, items.defaultUnit as itemUnit, items.[itemType] as itemType');
			array_push($sirQuery, ' FROM e10_witems_itemsets AS [sets]');
			array_push($sirQuery, ' LEFT JOIN [e10_witems_items] AS items ON [sets].item = items.ndx');
			array_push($sirQuery, ' WHERE [setItemType] = %i', 0);
			array_push($sirQuery, ' AND (sets.validFrom IS NULL OR sets.validFrom <= %d', $head['dateAccounting'], ')');
			array_push($sirQuery, ' AND (sets.validTo IS NULL OR sets.validTo >= %d', $head['dateAccounting'], ')');
			//array_push($sirQuery, ' AND [items].[itemType] = %i', 10);
			array_push($sirQuery, ' ORDER BY [sets].[item], [sets].[itemOwner], [sets].ndx');


			$setItemState = ['quantity' => 0.0, 'priceAll' => 0.0];
			$cnt = 0;
			$lastItemNdx = -1;
			$setItemRow = NULL;
			$lastSetItemRow = NULL;

			$setsItemsRows = $this->db()->query($sirQuery);
			foreach ($setsItemsRows as $setItemRow)
			{
				if ($lastItemNdx != $setItemRow['item'] && $lastItemNdx !== -1)
				{
					$this->saveSetItemStates($tableRows, $headNdx, $head, $lastSetItemRow, $setItemState);
					$setItemState['quantity'] = 0.0;
					$setItemState['priceAll'] = 0.0;
					$cnt = 0;
				}
				$lastItemNdx = $setItemRow['item'];
				$lastSetItemRow = $setItemRow->toArray();

				$isrQuery = [];
				array_push($isrQuery, 'SELECT [rows].*,');
				array_push($isrQuery, ' items.fullName as itemFullName, items.defaultUnit as itemUnit, items.[type] as itemType');
				array_push($isrQuery, ' FROM [e10doc_core_rows] AS [rows]');
				array_push($isrQuery, ' LEFT JOIN [e10_witems_items] AS items ON [rows].item = items.ndx');
				array_push($isrQuery, ' WHERE [document] = %i', $headNdx, ' AND [item] = %i', $setItemRow['itemOwner']);
				array_push($isrQuery, ' ORDER BY [rows].[item], [rows].ndx');

				$rowsToDelete = [];
				$initStateRows = $this->db()->query($isrQuery);

				foreach ($initStateRows as $isr)
				{
					if ($isr['unit'] !== $setItemRow['itemUnit'])
					{
						$ucc = E10Utils::unitsConversionCoefficient($this->app(), $isr['unit'], $setItemRow['itemUnit']);
						$setItemState['quantity'] += $isr['quantity'] * $ucc;
						$setItemState['priceAll'] += $isr['priceAll'];
					}
					else
					{
						$setItemState['quantity'] += $isr['quantity'];
						$setItemState['priceAll'] += $isr['priceAll'];
					}
					$rowsToDelete[] = $isr['ndx'];
					$cnt++;
				}

				if (count($rowsToDelete))
				{
					$this->db()->query('DELETE FROM [e10doc_core_rows] WHERE ndx IN %in ', $rowsToDelete);
				}
			}

			if ($cnt)
			{
				$this->saveSetItemStates($tableRows, $headNdx, $head, $setItemRow, $setItemState);
			}
		}
	}

	function saveSetItemStates($tableRows, $headNdx, $head, $setItemRow, $setItemState)
	{
		if (abs($setItemState['quantity']) < 0.0001)
			return;

		$priceItem = round($setItemState['priceAll'] / $setItemState['quantity'], 2);
		$roundedTotalPrice = round($priceItem * $setItemState['quantity'], 2);

		if ($setItemState['priceAll'] !== $roundedTotalPrice && $setItemState['quantity'] > 1)
		{
			$newRow = [];
			$newRow ['document'] = $headNdx;
			$newRow ['item'] = $setItemRow['item'];
			$newRow ['text'] = $setItemRow['itemFullName'];
			$newRow ['unit'] = $setItemRow['itemUnit'];

			$newRow ['quantity'] = $setItemState['quantity'] - 1;
			$newRow ['priceItem'] = round($setItemState['priceAll'] / ($setItemState['quantity'] - 1), 2);
			$usedTotalPrice = round($newRow ['quantity'] * $newRow ['priceItem'], 2);
			$tableRows->dbInsertRec($newRow, $head);

			$newRow = [];
			$newRow ['document'] = $headNdx;
			$newRow ['item'] = $setItemRow['item'];
			$newRow ['text'] = $setItemRow['itemFullName'];
			$newRow ['unit'] = $setItemRow['itemUnit'];

			$newRow ['quantity'] = 1;
			$newRow ['priceItem'] = round($setItemState['priceAll'] - $usedTotalPrice, 2);
			$tableRows->dbInsertRec($newRow, $head);
		}
		else
		{
			$newRow = [];
			$newRow ['document'] = $headNdx;
			$newRow ['item'] = $setItemRow['item'];
			$newRow ['text'] = $setItemRow['itemFullName'];
			$newRow ['unit'] = $setItemRow['itemUnit'];

			$newRow ['quantity'] = $setItemState['quantity'];
			$newRow ['priceItem'] = round($priceItem, 2);

			$tableRows->dbInsertRec($newRow, $head);
		}
	}

	function createInitState ($fiscalYear, $warehouse)
	{
		$this->app->db->begin();
		$docHead = $this->createInitStateDocHead ($fiscalYear, $warehouse);
		$this->createInitStateRows ($fiscalYear, $warehouse, $docHead);
		$this->app->db->commit();
	}
}
