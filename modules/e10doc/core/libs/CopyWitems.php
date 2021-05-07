<?php

namespace e10doc\core\libs;

use \e10\TableForm, \e10\utils, \e10\Utility;


/**
 * Class CopyWitems
 * @package e10doc\core\libs
 */
class CopyWitems extends Utility
{
	/** @var \e10\witems\TableItems */
	var $tableItems;
	var $requestParams = NULL;

	var $newItemNdx = 0;

	var $itemTypes = [];

	var $doFinalize = 0;

	function init()
	{
		$this->tableItems = $this->app()->table('e10.witems.items');
	}

	function setRequestParams($requestParams)
	{
		$this->requestParams = $requestParams;
	}

	function doAll ()
	{
		$q = [];
		array_push($q, 'SELECT witems.*');

		array_push($q, ' FROM [e10_witems_items] AS [witems]');
		array_push($q, ' WHERE 1');
		$this->createQueryFromParams($q);
		array_push($q, ' ORDER BY [witems].[fullName]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			echo "* ".$r['id'].' - '.$r['fullName'];

			$oldItem = $r->toArray();

			$this->newItemNdx = 0;
			$existedNewItem = NULL;
			if (isset($this->requestParams['successorDate']) && $oldItem['successorItem'])
			{
				$existedNewItem = $this->tableItems->loadItem($oldItem['successorItem']);
				if ($existedNewItem)
					$this->newItemNdx = $existedNewItem['ndx'];
			}

			$newItem = [];
			$this->createNewItemData($oldItem, $newItem);

			if ($this->newItemNdx)
			{
				echo " UPDATE ";
				$newItem['ndx'] = $this->newItemNdx;
				$this->tableItems->dbUpdateRec($newItem);
			}
			else
			{
				echo " INSERT ";
				$this->newItemNdx = $this->tableItems->dbInsertRec($newItem);

				$newItemRecData = $this->tableItems->loadItem($this->newItemNdx);
				$this->tableItems->checkAfterSave2($newItemRecData);
				$this->tableItems->docsLog($this->newItemNdx);
			}

			$this->copyDocLinks('e10-witems-items-categories', $oldItem['ndx']);
			$this->copyProperties($oldItem['ndx']);
			$this->copyClassification($oldItem['ndx']);
			$this->setSets($newItem);
			$this->updateOldItem($oldItem);

			if ($this->doFinalize)
			{
				$this->doFinalize($oldItem, $newItem);
				echo " -- FINAL!!!";
			}

			echo "\n";
		}
	}

	function createNewItemData($oldItem, &$newItem)
	{
		$newItem['fullName'] = $oldItem['fullName'];
		$newItem['shortName'] = $oldItem['shortName'];
		$newItem['id'] = $oldItem['id'];
		$newItem['itemType'] = $oldItem['itemType'];
		$newItem['itemKind'] = $oldItem['itemKind'];
		$newItem['type'] = $oldItem['type'];
		$newItem['defaultUnit'] = $oldItem['defaultUnit'];
		$newItem['priceBuy'] = $oldItem['priceBuy'];
		$newItem['priceSell'] = $oldItem['priceSell'];
		$newItem['priceSellBase'] = $oldItem['priceSellBase'];
		$newItem['priceSellTotal'] = $oldItem['priceSellTotal'];
		$newItem['vatRate'] = $oldItem['vatRate'];
		$newItem['brand'] = $oldItem['brand'];
		$newItem['warranty'] = $oldItem['warranty'];
		$newItem['niceUrl'] = $oldItem['niceUrl'];
		$newItem['useFor'] = $oldItem['useFor'];
		$newItem['useBalance'] = $oldItem['useBalance'];
		$newItem['askQCashRegister'] = $oldItem['askQCashRegister'];
		$newItem['askPCashRegister'] = $oldItem['askPCashRegister'];
		$newItem['orderCashRegister'] = $oldItem['orderCashRegister'];
		$newItem['groupCashRegister'] = $oldItem['groupCashRegister'];

		$newItem['isSet'] = $oldItem['isSet'];
		if (isset($this->requestParams['setSets']))
			$newItem['isSet'] = 1;

		if (isset($this->requestParams['setColumnsNew']))
		{
			foreach ($this->requestParams['setColumnsNew'] as $colId => $colValue)
			{
				if (is_array($colValue))
				{
					$currentValue = $newItem[$colId];

					if (isset($colValue[$currentValue]))
						$newItem[$colId] = $colValue[$currentValue];
				}
				else
				{
					$newItem[$colId] = $colValue;
				}
			}
		}

		if (!isset($newItem['docState']))
			$newItem['docState'] = 4000;
		if (!isset($newItem['docStateMain']))
			$newItem['docStateMain'] = 2;

		// -- set itemKind & type
		$itemType = $this->itemType($newItem['itemType']);
		if (!$itemType)
		{
			echo " --- !!! ERROR !!! ---";
			return;
		}

		$newItem['type'] = $itemType['id'];
		$newItem['itemKind'] = $itemType['type'];
	}

	function updateOldItem($oldItem)
	{
		$update = [];
		if (isset($this->requestParams['successorDate']))
		{
			$update['successorDate'] = $this->requestParams['successorDate'];
			$update['successorItem'] = $this->newItemNdx;
		}

		if (isset($this->requestParams['setColumnsOld']))
		{
			foreach ($this->requestParams['setColumnsOld'] as $colId => $colValue)
				$update[$colId] = $colValue;
		}

		if (count($update))
		{
			$update['ndx'] = $oldItem['ndx'];
			$this->tableItems->dbUpdateRec($update);
		}
	}

	function itemType($itemTypeNdx)
	{
		if (isset($this->itemTypes[$itemTypeNdx]))
			return $this->itemTypes[$itemTypeNdx];

		$itemTypeRecData = $this->db()->query('SELECT * FROM [e10_witems_itemtypes] WHERE [ndx] = %i', $itemTypeNdx)->fetch();
		if ($itemTypeRecData)
		{
			$this->itemTypes[$itemTypeNdx] = $itemTypeRecData->toArray();
			return $this->itemTypes[$itemTypeNdx];
		}

		return NULL;
	}

	function copyDocLinks($linkId, $oldItemNdx)
	{
		// -- delete existed
		$this->db()->query('DELETE FROM [e10_base_doclinks] WHERE [linkId] = %s', $linkId, ' AND [srcRecId] = %i', $this->newItemNdx);

		// -- copy new
		$q[] = 'SELECT * FROM e10_base_doclinks WHERE 1';
		array_push($q, ' AND srcTableId = %s', 'e10.witems.items', ' AND srcRecId = %i', $oldItemNdx);
		array_push($q, ' AND [linkId] = %s', $linkId);
		array_push($q, ' ORDER BY ndx');
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			unset($newItem['ndx']);
			$newItem['srcRecId'] = $this->newItemNdx;
			$this->db()->query('INSERT INTO [e10_base_doclinks] ', $newItem);
		}
	}

	function copyProperties($oldItemNdx)
	{
		// -- delete existed
		$this->db()->query('DELETE FROM [e10_base_properties] WHERE [tableid] = %s', 'e10.witems.items', ' AND [recid] = %i', $this->newItemNdx);

		// -- copy new
		$q[] = 'SELECT * FROM e10_base_properties WHERE 1';
		array_push($q, ' AND tableid = %s', 'e10.witems.items', ' AND recid = %i', $oldItemNdx);
		array_push($q, ' ORDER BY ndx');
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			unset($newItem['ndx']);
			$newItem['recid'] = $this->newItemNdx;
			$this->db()->query('INSERT INTO [e10_base_properties] ', $newItem);
		}
	}

	function copyClassification($oldItemNdx)
	{
		// -- delete existed
		$this->db()->query('DELETE FROM [e10_base_clsf] WHERE [tableid] = %s', 'e10.witems.items', ' AND [recid] = %i', $this->newItemNdx);

		// -- copy new
		$q[] = 'SELECT * FROM e10_base_clsf WHERE 1';
		array_push($q, ' AND tableid = %s', 'e10.witems.items', ' AND recid = %i', $oldItemNdx);
		array_push($q, ' ORDER BY ndx');
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			unset($newItem['ndx']);
			$newItem['recid'] = $this->newItemNdx;
			$this->db()->query('INSERT INTO [e10_base_clsf] ', $newItem);
		}
	}

	function setSets ($newItem)
	{
		if (!isset($this->requestParams['setSets']))
			return;

		// -- delete existed
		$this->db()->query('DELETE FROM [e10_witems_itemsets] WHERE [itemOwner] = %i', $this->newItemNdx);

		// -- add new
		foreach ($this->requestParams['setSets'] as $setId => $setItem)
		{
			$idParts = explode (':', $setId);
			if (count($idParts) !== 2)
				continue;

			if (!isset($newItem[$idParts[0]]) || $newItem[$idParts[0]] != $idParts[1])
				continue;

			$newItemSetRow = ['itemOwner' => $this->newItemNdx];
			foreach ($setItem as $colId => $colValue)
			{
				if ($colId[0] === '_')
					continue;
				$newItemSetRow[$colId] = $colValue;
			}

			$this->db()->query('INSERT INTO [e10_witems_itemsets] ', $newItemSetRow);
		}
	}

	function createQueryFromParams(&$q)
	{
		if (!isset($this->requestParams['query']))
			return;

		foreach ($this->requestParams['query'] as $qCol => $qValue)
		{
			if (is_array($qValue))
				array_push($q, 'AND ['.$qCol.'] IN %in ', $qValue);
			else
				array_push($q, 'AND ['.$qCol.'] = %s', $qValue);
		}
	}

	function doFinalize($oldItem, $newItem)
	{
		if (!isset($this->requestParams['doFinalize']))
			return;

		foreach ($this->requestParams['doFinalize'] as $dfId => $dfValue)
		{
			if ($dfId === 'removeOldLabels')
			{
				if ($dfValue === 1)
					$this->db()->query('DELETE FROM [e10_base_clsf] WHERE [tableid] = %s', 'e10.witems.items', ' AND [recid] = %i', $oldItem['ndx']);
			}
			elseif ($dfId === 'removeOldCategories')
			{
				if ($dfValue === 1)
					$this->db()->query('DELETE FROM [e10_base_doclinks] WHERE [linkId] = %s', 'e10-witems-items-categories', ' AND [srcRecId] = %i', $oldItem['ndx']);
			}
		}
	}

	public function run()
	{
		echo "COPY ITEMS....\n";

		$this->init();
		$this->doAll();
	}
}


/*
 * example params file:
 * usage: `sudo e10-app cli-action --action=e10doc.core/copy-witems --params=nove-polozky.json --do-finalize`
 *
{
	"query": {
		"itemType": 1,
		"docState": 4000
	},

	"successorDate": "2019-01-01",

	"setColumnsNew": {
		"itemType": 9,
		"id": "",
		"niceUrl": ""
	},

	"setColumnsOld": {
		"docState": 9000,
		"docStateMain": 5
	},

	"setSets": {
		"brand:1": {"item": 510, "quantity": 1, "setItemType": 0, "_": "AL --> AL"},
		"brand:11": {"item": 510, "quantity": 1, "setItemType": 0, "_": "AL kabel --> AL"},
		"brand:5": {"item": 509, "quantity": 1, "setItemType": 0, "_": "BRonz --> CU"},
		"brand:2": {"item": 510, "quantity": 1, "setItemType": 0, "_": "CU --> CU"},
		"brand:12": {"item": 510, "quantity": 1, "setItemType": 0, "_": "CU kabel --> CU"},
		"brand:26": {"item": 512, "quantity": 1000, "setItemType": 0, "_": "DK Drahé kovy --> Směsné kovy"},
		"brand:4": {"item": 508, "quantity": 1, "setItemType": 0, "_": "FE lehké --> FE"},
		"brand:18": {"item": 508, "quantity": 1, "setItemType": 0, "_": "FE těžké --> FE"},
		"brand:10": {"item": 512, "quantity": 1, "setItemType": 0, "_": "KATalyzátory --> Směsné kovy"},
		"brand:23": {"item": 517, "quantity": 1, "setItemType": 0, "_": "MO --> Ostatní"},
		"brand:6": {"item": 509, "quantity": 1, "setItemType": 0, "_": "MS (mosaz) --> CU"},
		"brand:7": {"item": 508, "quantity": 1, "setItemType": 0, "_": "NErez --> FE"},
		"brand:22": {"item": 517, "quantity": 1, "setItemType": 0, "_": "NIkl --> Ostatní --- ???????"},
		"brand:3": {"item": 511, "quantity": 1, "setItemType": 0, "_": "PAPír --> Papír"},
		"brand:21": {"item": 515, "quantity": 1, "setItemType": 0, "_": "PB --> PB"},
		"brand:20": {"item": 514, "quantity": 1, "setItemType": 0, "_": "SN --> SN"},
		"brand:25": {"item": 517, "quantity": 1, "setItemType": 0, "_": "TItan --> Ostatní"},
		"brand:24": {"item": 517, "quantity": 1, "setItemType": 0, "_": "Wolfram --> Ostatní"},
		"brand:19": {"item": 513, "quantity": 1, "setItemType": 0, "_": "ZN --> ZN"}
	},

	"doFinalize": {
		"removeOldLabels": 1,
		"removeOldCategories": 1
	}
}
 *
 */
