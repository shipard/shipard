<?php

namespace e10doc\core\libs;

/**
 * Class DocCheckItemsSuccessors
 * @package e10doc\core\libs
 */
class DocCheckItemsSuccessors extends \e10doc\core\libs\DocCheck
{
	public function checkDocument($repair)
	{
		parent::checkDocument($repair);

		$q = [];
		array_push($q,'SELECT [rows].*, [witems].[isSet] AS itm_ItemIsSet, [witems].[type] AS itm_ItemType,');
		array_push($q,' [successors].[id] AS successorId, [successors].[fullName] AS successorName, [witems].[successorItem]');
		array_push($q,' FROM [e10doc_core_rows] AS [rows]');
		array_push($q,' LEFT JOIN [e10_witems_items] AS [witems] ON [rows].[item] = [witems].[ndx]');
		array_push($q,' LEFT JOIN [e10_witems_items] AS [successors] ON [witems].[successorItem] = [successors].[ndx]');
		array_push($q,' WHERE [document] = %i', $this->docNdx);
		array_push($q,' AND (',
				'[witems].[successorItem] != %i', 0,
				' AND [witems].[successorDate] <= %d', $this->docRecData['dateAccounting'],
				' AND [witems].[successorItem] != [rows].item',
			')');
		array_push($q,' ORDER BY rowOrder, ndx');

		$rows = $this->db()->query ($q);
		forEach ($rows as $row)
		{
			$r = $row->toArray();
			$this->checkRow($r);
		}
	}

	function checkRow (&$row)
	{
		$this->addRowMsg($row, "Item is not replaced by `#{$row['successorId']}` ({$row['successorName']})");
			if ($this->repair)
			{
				$newItem = $this->witem($row['successorItem']);

				$this->repairRow($row, $newItem);
			}
	}

	function repairRow(&$row, $item)
	{
		$newItemtype = $item['type'];
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $this->docRecData['docType'], NULL);
		$itemType = $this->app()->cfgItem ('e10.witems.types.' . $newItemtype, NULL);
		if ($itemType === NULL)
		{
			echo ("* ERROR: Unknown item type {$newItemtype}\n");
			return;
		}
		if (!isset($itemType['kind']))
		{
			echo ("* ERROR: Bad item type {$newItemtype}\n");
			return;
		}

//		$item = $this->witem($newItemtype);

		$updateRow = [];
		$updateRow['item'] = $item['ndx'];
		$updateRow['itemType'] = $newItemtype;
		$updateRow['invDirection'] = $docType['invDirection'];
		if ($itemType['kind'] == 1)
		{
			if ($docType && isset($docType['invDirection']))
				$updateRow ['invDirection'] = $docType['invDirection'];
		}
		$updateRow['itemIsSet'] = 0;
		if ($item['isSet'] )
		{
			$updateRow['itemIsSet'] = 1;
		}

		if (count($updateRow))
		{
			$this->db()->query('UPDATE [e10doc_core_rows] SET ', $updateRow, ' WHERE [ndx] = %i', $row['ndx']);
			$this->needRecalc = TRUE;
		}
	}
}

