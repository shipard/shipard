<?php

namespace lib\cacheItems;



use \Shipard\Utils\Utils;


/**
 * Class StoreStates
 * @package lib\cacheItems
 */
class StoreStates extends \Shipard\Base\CacheItem
{
	var $units;
	var $items;

	function loadDocs ($date)
	{
		$q = [];
		array_push ($q, 'SELECT [rows].item, [rows].unit, items.shortName as itemName, items.fullName as fullItemName, SUM([rows].quantity) as quantity, SUM([rows].taxBaseHc) AS taxBaseHc ');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push ($q, ' WHERE heads.[docState] = %i', 4000, ' AND heads.[docType] = %s', 'cashreg');
		array_push ($q, ' AND heads.[activateDateFirst] = %d', $date);
		array_push ($q, ' GROUP BY [rows].item, [rows].unit');
		array_push ($q, ' ORDER BY taxBaseHc DESC');

		$data = [];
		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
					'title' => ($r['itemName'] !== '') ? $r['itemName'] : $r['fullItemName'],
					'quantity' => intval(round($r['quantity'])),
					'taxBaseHc' => intval(round($r['taxBaseHc'])), 'unit' => $this->units[$r['unit']]['shortcut']
			];
			$data[] = $item;
		}

		$shortData = [];
		$cutedSum = [];
		$maxRows = 8;
		Utils::cutRows ($data, $shortData, ['taxBaseHc','quantity'], $cutedSum, $maxRows);
		if (count($cutedSum))
		{
			$cutedSum['title'] = 'Ostatní';
			$cutedSum['quantity'] = intval(round($cutedSum['quantity']));
			$cutedSum['taxBaseHc'] = intval(round($cutedSum['taxBaseHc']));
			$shortData[] = $cutedSum;
		}

		$this->items = $shortData;
	}

	function createData()
	{
		$this->units = $this->app->cfgItem ('e10.witems.units');

		$q [] = 'SELECT * FROM e10doc_core_heads';
		array_push($q, ' WHERE docType = %s', 'cashreg', ' AND docState = %i', 4000);
		array_push($q, ' ORDER BY activateDateFirst DESC');
		array_push($q, ' LIMIT 0, 1');
		$lastDoc = $this->app->db()->query ($q)->fetch();
		if (!$lastDoc)
			return;

		unset ($q);
		$q [] = 'SELECT SUM(sumBaseHc) as sumBaseHc, COUNT(*) AS cnt FROM e10doc_core_heads';
		array_push($q, ' WHERE docType = %s', 'cashreg', ' AND docState = %i', 4000);
		array_push($q, ' AND activateDateFirst = %d', $lastDoc['activateDateFirst']);
		array_push($q, ' LIMIT 0, 1');

		$sumDoc = $this->app->db()->query ($q)->fetch();
		if (!$sumDoc)
			return;

		$this->loadDocs($lastDoc['activateDateFirst']);

		$this->data = [
				'lastChange' => $lastDoc['sumBaseHc'],
				'curr' => $this->app->cfgItem ('e10.base.currencies.'.$lastDoc['homeCurrency'].'.shortcut'),
				'sumBaseHc' => $sumDoc['sumBaseHc'],
				'date' => $lastDoc['activateTimeLast']->format('Y-m-d'),
				'cntDocs' => $sumDoc['cnt'],
				'items' => $this->items
		];

		parent::createData();
	}
}
