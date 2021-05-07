<?php

namespace e10doc\core\libs;

require_once __APP_DIR__ . '/e10-modules/e10doc/debs/debs.php';
use \e10\utils, \e10doc\core\e10utils;


/**
 * Class DocCheckItemTypes
 * @package e10doc\core\libs
 */
class DocCheckItemTypes extends \e10doc\core\libs\DocCheck
{
	public function checkDocument($repair)
	{
		parent::checkDocument($repair);

		$q = [];
		array_push($q,'SELECT [rows].*, [witems].[isSet] AS itm_ItemIsSet, [witems].[type] AS itm_ItemType');
		array_push($q,' FROM [e10doc_core_rows] AS [rows]');
		array_push($q,' LEFT JOIN [e10_witems_items] AS [witems] ON [rows].[item] = [witems].[ndx]');
		array_push($q,' WHERE [document] = %i', $this->docNdx);
		array_push($q,' AND [witems].[type] != [rows].itemType');
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
		if ($row['item'] && $row['itemType'] !== $row['itm_ItemType'])
		{
			$this->addRowMsg($row, "Bad item type; (i=`{$row['itm_ItemType']}` vs r=`{$row['itemType']}`)");
			if ($this->repair)
				$this->repairRow($row, $row['itm_ItemType']);
		}
	}

	function repairRow(&$row, $newItemtype)
	{
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

		$item = $this->witem($row['item']);

		$updateRow = [];
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

