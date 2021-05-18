<?php

namespace e10doc\core\libs;
use \e10doc\core\libs\E10Utils;
use \Shipard\Utils\Utils;


class AggregateDocRows extends Aggregate
{
	CONST groupItems = 1, groupAccGroups = 2, groupTypes = 3, groupBrands = 4, groupItemKinds = 5;

	var $enabledDocTypes = ['cashreg', 'invno'];
	var $enabledOperations = [1010001, 1010002];

	var $groupBy = self::groupItems;

	var $itemBrand = FALSE;
	var $itemType = FALSE;

	function create ()
	{
		switch ($this->groupBy)
		{
			case self::groupItems:
				$selColumns = 'items.fullName as groupName, item as groupNdx';
				break;
			case self::groupAccGroups:
				$selColumns = 'debsgroups.fullName as groupName, debsgroups.ndx as groupNdx';
				break;
			case self::groupTypes:
				$selColumns = 'itemtypes.fullName as groupName, itemtypes.ndx as groupNdx';
				break;
			case self::groupBrands:
				$selColumns = 'itembrands.fullName as groupName, itembrands.ndx as groupNdx';
				break;
			case self::groupItemKinds:
				$selColumns = "CASE items.itemKind WHEN 0 THEN 'Služby' WHEN 1 THEN 'Zásoby' WHEN 2 THEN 'Účetní položky' WHEN 3 THEN 'Ostatní' END as groupName, items.itemKind as groupNdx";
				break;
		}

		$q[] = 'SELECT ';

		array_push($q, $selColumns);
		array_push($q, ' , SUM([rows].taxBaseHc) as taxBase,');

		switch ($this->period)
		{
			case self::periodDaily:
				array_push($q, ' heads.dateAccounting as dateAccounting '); break;
			case self::periodMonthly:
				array_push($q, ' YEAR(heads.dateAccounting) as dateAccountingYear, MONTH(heads.dateAccounting) as dateAccountingMonth '); break;
		}

		array_push($q, ' FROM e10doc_core_rows as [rows] LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');

		array_push($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push($q, ' LEFT JOIN e10doc_debs_groups AS debsgroups ON items.debsGroup = debsgroups.ndx');
		array_push($q, ' LEFT JOIN e10_witems_itemtypes AS itemtypes ON items.itemType = itemtypes.ndx');
		array_push($q, ' LEFT JOIN e10_witems_brands AS itembrands ON items.brand = itembrands.ndx');

		array_push($q, ' WHERE heads.docState = 4000');
		array_push($q, ' AND docType IN %in', $this->enabledDocTypes);
		array_push($q, ' AND operation IN %in', $this->enabledOperations);

		if ($this->itemBrand !== FALSE)
			array_push($q, ' AND items.brand = %i', $this->itemBrand);
		if ($this->itemType !== FALSE)
			array_push($q, ' AND items.itemType = %i', $this->itemType);

		E10Utils::fiscalPeriodQuery ($q, $this->fiscalPeriod);

		switch ($this->period)
		{
			case self::periodDaily:
				array_push($q, ' GROUP BY heads.dateAccounting, [rows].item'); break;
			case self::periodMonthly:
				array_push($q, ' GROUP BY dateAccountingYear, dateAccountingMonth, [rows].item'); break;
		}

		$rows = $this->app->db()->query($q);

		$data = [];
		$total = ['date' => 'CELKEM', 'totalBase' => 0.0];
		$groupNames = [];

		forEach ($rows as $r)
		{
			$groupNdx = 'G'.$r['groupNdx'];

			switch ($this->period)
			{
				case self::periodDaily:
					$dateKey = $r['dateAccounting']->format ('Y-m-d');
					$date = utils::datef ($r['dateAccounting'], '%n %d');
					break;
				case self::periodMonthly:
					$dateKey = $r['dateAccountingYear'].'-'.$r['dateAccountingMonth'];
					$date = $r['dateAccountingMonth'].'.'.$r['dateAccountingYear'];
					break;
			}

			if (!isset ($data [$dateKey]))
				$data [$dateKey] = ['date' => $date, 'totalBase' => 0.0];
			if (!isset ($data [$dateKey][$groupNdx]))
				$data [$dateKey][$groupNdx] = 0.0;

			$data [$dateKey][$groupNdx] += $r['taxBase'];
			$data [$dateKey]['totalBase'] += round($r['taxBase'], 2);

			if (!isset($total[$groupNdx]))
			{
				$total[$groupNdx] = 0.0;
				if ($r['groupName'])
					$groupNames[$groupNdx] = $r['groupName'];
				else
					$groupNames[$groupNdx] = 'NEUVEDENO';
			}

			$total[$groupNdx] += $r['taxBase'];
			$total['totalBase'] += round ($r['taxBase'], 2);
		}

		$h = ['date' => ' '.$this->periodColumnName, 'totalBase' => '+CELKEM'];
		$groupOrder = array_merge([], $total);
		unset ($groupOrder['date']);
		unset ($groupOrder['totalBase']);
		arsort($groupOrder, SORT_NUMERIC);

		foreach ($groupOrder as $opk => $opv)
		{
			$h[$opk] = '+'.$groupNames[$opk];
		}

		$maxCols = $this->maxResultParts;
		$totalsCuted = [];
		Utils::cutColumns ($data, $this->data, $h, $this->header, $this->graphLegend, $totalsCuted, 2, $maxCols);

		$this->graphBar = ['type' => 'graph', 'graphType' => 'bar', 'XKey' => 'date', 'stacked' => 1, 'header' => $this->header,
											 'disabledCols' => ['totalBase'], 'graphData' => $this->data];
		$this->graphLine = ['type' => 'graph', 'graphType' => 'spline', 'XKey' => 'date', 'header' => $this->header,
												'graphData' => $this->data];

		foreach ($this->graphLegend as $legendId => $legendTitle)
			$this->pieData[] = [Utils::tableHeaderColName($legendTitle), $totalsCuted[$legendId]];
		$this->graphDonut = ['type' => 'graph', 'graphType' => 'pie', 'graphData' => $this->pieData];
	}
}

