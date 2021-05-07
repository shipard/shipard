<?php

namespace e10doc\core\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/debs/debs.php';
use \e10\utils, \e10doc\core\e10utils;


/**
 * Class DocCheckSetsItems
 * @package e10doc\core\libs
 */
class DocCheckSetsItems extends \e10doc\core\libs\DocCheck
{
	var $itemSetsRows = NULL;
	var $newDocRowSetRows = NULL;
	var $needReset = 0;

	public function checkDocument($repair)
	{
		parent::checkDocument($repair);

		$q = [];
		array_push($q,'SELECT [rows].*, [witems].[isSet] AS itm_ItemIsSet, [witems].[type] AS itm_ItemType, [witems].[id] AS itm_ItemId');
		array_push($q,' FROM [e10doc_core_rows] AS [rows]');
		array_push($q,' LEFT JOIN [e10_witems_items] AS [witems] ON [rows].[item] = [witems].[ndx]');
		array_push($q,' WHERE [document] = %i', $this->docNdx);
		array_push($q,' AND [rowType] = %i', 0);
		array_push($q,' ORDER BY rowOrder, ndx');

		$rows = $this->db()->query ($q);
		forEach ($rows as $row)
		{
			$r = $row->toArray();
			$this->checkRow($r);
		}

		$this->checkRowSet_UnusedRows();
	}

	function checkRow (&$row)
	{
		$this->itemSetsRows = NULL;
		$this->newDocRowSetRows = NULL;
		$this->needReset = 0;

		if ($row['item'] && $row['itemType'] !== $row['itm_ItemType'])
		{
			$this->addRowMsg($row, "No item");
//			return;
		}
		if ($row['item'] && $row['itemType'] !== $row['itm_ItemType'])
		{
			$this->addRowMsg($row, "Bad item type; (i=`{$row['itm_ItemType']}` vs r=`{$row['itemType']}`)");
			return;
		}

		if ($row['itm_ItemIsSet'] === 0 && $row['itemIsSet'] !== 0)
		{
			$this->addRowMsg($row,"Nesouhlasi itemIsSet (i=`{$row['itm_ItemIsSet']}` vs r=`{$row['itemIsSet']}`)");

			$this->db()->query('UPDATE [e10doc_core_rows] SET itemIsSet = %i', $row['itm_ItemIsSet'], ' WHERE [ndx] = %i', $row['ndx']);

			$row['itemIsSet'] = $row['itm_ItemIsSet'];
			$this->needReset = 0;
		}

		$this->checkRowSet($row);

		if ($this->needReset && $this->repair)
		{
			$this->repairRowSet($row);
		}
	}

	function checkRowSet($row)
	{
		$currentRowSetRows = $this->loadRowSetsRows($row);

		if (!$row['item'] && count($currentRowSetRows) !== 0)
		{
			$this->addRowMsg($row,"Řádek obsahuje řádky sady, přestože položka není vyplněna");
			$this->needReset = 1;
			return;
		}

		if (!$row['item'])
			return;

		if ($row['itm_ItemIsSet'] == 0 && count($currentRowSetRows) !== 0)
		{
			$this->addRowMsg($row,"Řádek obsahuje řádky sady, přestože položka #".$row['itm_ItemId']." není sada");
			$this->needReset = 1;
			return;
		}


		if ($row['itm_ItemIsSet'] === 1 && count($currentRowSetRows) === 0)
		{
			$this->addRowMsg($row,"Řádek dokladu neobsahuje řádky sady, přestože položka #".$row['item']." je sada");
			$this->needReset = 1;
			return;
		}

		$this->itemSetsRows = $this->loadItemSetsRows($row);
		$this->newDocRowSetRows = $this->createDocRowSetRows($row, $this->itemSetsRows);

		if (count($currentRowSetRows) !== count($this->newDocRowSetRows))
		{
			$this->addRowMsg($row,"Řádek obsahuje nesprávný počet řádků sady: je ".count($currentRowSetRows).', má být '.count($this->newDocRowSetRows));
			$this->needReset = 1;
			return;
		}

		foreach ($currentRowSetRows as $rowId => $er)
		{
			$mr = $this->newDocRowSetRows[$rowId];

			if ($er['item'] !== $mr['item'])
			{
				$this->addRowMsg($row,"Vadná cílová položka sady");
				$this->needReset = 1;
			}

			if ($er['unit'] !== $mr['unit'])
			{
				$this->addRowMsg($row,"Vadná cílová jednotka položky sady");
				$this->needReset = 1;
			}

			if ($er['quantity'] !== $mr['quantity'])
			{
				$this->addRowMsg($row,"Nesouhlasí množství");
				$this->needReset = 1;
			}

			if ($er['invDirection'] !== $mr['invDirection'])
			{
				$this->addRowMsg($row,"Nesouhlasí invDirection");
				$this->needReset = 1;
			}

			if ($er['invPrice'] !== $mr['invPrice'] && $mr['invDirection'] != -1)
			{
				$this->addRowMsg($row,"Nesouhlasí cena; je ".$er['invPrice'].", má být ".$mr['invPrice']);
				$this->needReset = 1;
			}

			if ($er['rowOrder'] !== $mr['rowOrder'])
			{
				$this->addRowMsg($row,"Nesouhlasí rowOrder");
				$this->needReset = 1;
			}

			if ($er['operation'] !== $mr['operation'])
			{
				$this->addRowMsg($row,"Nesouhlasí Pohyb");
				$this->needReset = 1;
			}
		}
	}

	function checkRowSet_UnusedRows()
	{
		$q = [];
		array_push($q,'SELECT [rows].ndx, [rows].[text]');
		array_push($q,' FROM [e10doc_core_rows] AS [rows]');
		array_push($q,' WHERE [rows].[document] = %i', $this->docNdx);
		array_push($q,' AND [rows].[rowType] != %i', 0);
		array_push($q,' AND [rows].[ownerRow] NOT IN (SELECT ndx FROM e10doc_core_rows WHERE e10doc_core_rows.document = %i', $this->docNdx, ' AND rowType = 0)');
		array_push($q,' ORDER BY [rows].rowOrder, [rows].ndx');

		$rows = $this->db()->query ($q);
		forEach ($rows as $r)
		{
			$this->addRowMsg($r,'Řádek #'.$r['ndx'].' má vadného vlastníka');
		}
	}

	function loadRowSetsRows($row)
	{
		$rowSetRows = [];

		$q = [];
		array_push($q,'SELECT [rows].*');
		array_push($q,' FROM [e10doc_core_rows] AS [rows]');
		array_push($q,' WHERE [document] = %i', $this->docNdx);
		array_push($q,' AND [ownerRow] = %i', $row['ndx']);
		array_push($q,' ORDER BY rowOrder, ndx');

		$rows = $this->db()->query ($q);
		forEach ($rows as $row)
		{
			$r = $row->toArray();
			$rowSetRows[] = $r;
		}

		return $rowSetRows;
	}

	function loadItemSetsRows($row)
	{
		$itemSetsRows = [];

		if ($row['itm_ItemIsSet'] === 0)
			return $itemSetsRows; // item is not set

		$itemNdx = $row['item'];

		$q [] = 'SELECT itemset.*, items.fullName as itemFullName, items.[type] AS itemType, items.useBalance AS itemBalance, items.defaultUnit as itemUnit';
		array_push($q,' FROM [e10_witems_itemsets] AS itemset');
		array_push($q,' LEFT JOIN [e10_witems_items] AS items ON itemset.item = items.ndx');
		array_push($q,' WHERE itemOwner = %i', $itemNdx);
		array_push($q,' AND (itemset.validFrom IS NULL OR itemset.validFrom <= %d', $this->docRecData['dateAccounting'], ')');
		array_push($q,' AND (itemset.validTo IS NULL OR itemset.validTo >= %d', $this->docRecData['dateAccounting'], ')');
		array_push($q,' ORDER BY ndx');

		$rows = $this->db()->query($q);

		foreach ($rows as $row)
		{
			$r = $row->toArray();
			$itemSetsRows[] = $r;
		}

		return $itemSetsRows;
	}

	function createDocRowSetRows($row, $itemSetsRows)
	{
		$docRowSetRows = [];

		$docType = $this->app()->cfgItem ('e10.docs.types.' . $this->docRecData['docType'], NULL);

		foreach ($itemSetsRows as $isr)
		{
			$operation = $row['operation'];

			$newRow = [
				'document' => $this->docNdx, 'ownerRow' => $row['ndx'], 'rowType' => 1,
				'item' => $isr['item'], 'unit' => $isr['itemUnit'], 'itemType' => $isr['itemType'], 'itemBalance' => $isr['itemBalance'],
				'operation' => $operation,
				'text' => $isr['itemFullName'], 'rowOrder' => $row['rowOrder'],
			];

			$newRow['quantity'] = ($isr['quantity']) ? $isr['quantity'] * $row['quantity'] : $row['quantity'];

			$newRow['invDirection'] = 0;
			$itemType = $this->app()->cfgItem ('e10.witems.types.' . $newRow['itemType'], NULL);
			if ($itemType)
			{
				if ($itemType['kind'] == 1 && $this->docRecData['warehouse'] != 0)
				{
					if ($docType && isset($docType['invDirection']))
						$newRow['invDirection'] = $docType['invDirection'];
				}
			}

			$newRow['invPrice'] = 0;
			if ($isr['setItemType'] == 0)
			{
				$newRow['invPrice'] = $row['taxBaseHc'];
				if ($this->docRecData['docType'] === 'purchase' && $newRow['invDirection'] === 1 && $newRow ['invPrice'] < 0.0)
					$newRow['invPrice'] = 0.0;
			}

			if ($isr['itemUnit'] !== $row['unit'])
			{
				$ucc = e10utils::unitsConversionCoefficient($this->app(), $row['unit'], $isr['itemUnit']);
				$newRow['quantity'] *= $ucc;
			}

			$docRowSetRows[] = $newRow;
		}

		return $docRowSetRows;
	}

	function repairRowSet($row)
	{
		if (!$this->itemSetsRows)
			$this->itemSetsRows = $this->loadItemSetsRows($row);

		if (!$this->newDocRowSetRows)
			$this->newDocRowSetRows = $this->createDocRowSetRows($row, $this->itemSetsRows);

		// -- delete old rows
		$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $this->docNdx, ' AND [ownerRow] = %i', $row['ndx']);

		// -- insert new rows
		foreach ($this->newDocRowSetRows as $newRow)
		{
			$this->db()->query ('INSERT INTO [e10doc_core_rows] ', $newRow);
		}
	}
}

