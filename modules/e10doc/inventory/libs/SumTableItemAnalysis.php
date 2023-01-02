<?php


namespace e10doc\inventory\libs;

use \lib\core\ui\SumTable, \Shipard\Utils\Utils;

/**
 * class SumTableItemAnalysis
 */
class SumTableItemAnalysis extends SumTable
{
	var $dataAll = [];
	var $dataSums = [];
	var $dataSumsByAccKinds = [];
	var $accNames;

	var $docTypes;

	var $accountKinds = NULL;

	public function init()
	{
		$this->hideHeader = 0;
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		parent::init();

		$this->objectClassId = 'e10doc.inventory.libs.SumTableItemAnalysis';

		$this->header = [
			'id' => '>Období',
			'unit' => ' J.',
			'sumInQuantity' => ' Příjem Množství',
			'sumInPrice' => ' Příjem Cena',
			'sumOutQuantity' => ' Výdej Množství',
			'sumOutPrice' => ' Výdej Cena',
			'sumStateQuantity' => ' Zůst. Množství',
			'sumStatePrice' => ' Zůst. Cena',
		];

		$this->colClasses['id'] = 'nowrap width16em';

		$this->extraHeader = [
			[
				'id' => isset($this->options['headerTitle']) ? $this->options['headerTitle'] : 'Rozbor pohybu Zásob',
				'_options' => [
					'colSpan' => ['id' => 8],
					'cellCss' => ['id' => 'background-color: #eef !important; font-weight: normal;'],
				]
			],
			[
				'id' => 'Období',
				'unit' => 'Jed',
				'sumInQuantity' => 'Příjem',
				//'sumInPrice' => ' Příjem Cena',
				'sumOutQuantity' => 'Výdej',
				//'sumOutPrice' => ' Výdej Cena',
				'sumStateQuantity' => 'Zůstatek',
				//'sumStatePrice' => ' Zůst. Cena',
				'_options' => [
					'rowSpan' => ['id' => 2, 'unit' => 2],
					'colSpan' => ['sumInQuantity' => 2, 'sumOutQuantity' => 2, 'sumStateQuantity' => 2],
					'cellClasses' => ['sumInQuantity' => 'center', 'sumOutQuantity' => 'center', 'sumStateQuantity' => 'center', 'unit' => 'number'],
				]
			],
			[
				//'id' => '>Období',
				'sumInQuantity' => 'Množství',
				'sumInPrice' => 'Cena',
				'sumOutQuantity' => 'Množství',
				'sumOutPrice' => 'Cena',
				'sumStateQuantity' => 'Množství',
				'sumStatePrice' => 'Cena',
				'_options' => [
					'cellClasses' => [
						'sumInQuantity' => 'number', 'sumOutQuantity' => 'number', 'sumStateQuantity' => 'number',
						'sumInPrice' => 'number', 'sumOutPrice' => 'number', 'sumStatePrice' => 'number',
					],
				]
			]
		];
	}

	function loadData()
	{
		if ($this->level === 0)
			$this->loadData_Years();
		elseif ($this->level === 1)
			$this->loadData_Months();
		elseif ($this->level === 2)
			$this->loadData_Days();
		elseif ($this->level === 3)
			$this->loadData_DayDocs();
	}

	function loadData_Years()
	{
		$itemNdx = intval($this->queryParams['item_ndx']);

		$fyscalYears = $this->app->cfgItem ('e10doc.acc.periods');
		foreach ($fyscalYears as $fpId => $fp)
		{
			$fiscalYear = intval($fpId);

			$q = [];
			array_push($q, 'SELECT SUM(quantity) as quantity, SUM(price) as price, item, unit, moveType ');
			array_push($q, ' FROM [e10doc_inventory_journal] WHERE [item] = %i', $itemNdx);
			array_push($q, ' AND [fiscalYear] = %i', $fiscalYear);
			array_push($q, ' GROUP BY unit, moveType, item');
			$rows = $this->db()->query($q);

			$sumId = 'FY'.$fiscalYear;
			$item = ['id' => $fp['fullName']];
			$item['note'] = 'Test 123';
			$item['_options'] = [
				'expandable' => [
					'column' => 'id',
					'level' => $this->level,
					'exp-this-id' => $sumId,
					'exp-parent-id' => '',
					'query-params' => ['year_id' => $sumId]
				]
			];

			$cnt = 0;
			foreach ($rows as $r)
			{
				if ($r['moveType'] === 0)
				{
					$item['sumInQuantity'] = $r['quantity'];
					$item['sumInPrice'] = $r['price'];
				}
				else
				{
					$item['sumOutQuantity'] = $r['quantity'];
					$item['sumOutPrice'] = $r['price'];
				}

				if (!isset($item['unit']))
					$item['unit'] = $r['unit'];
				elseif ($item['unit'] !== $r['unit'])
					$item['unit'] .= ' ! '.$r['unit'];

				$cnt++;
			}
			if ($cnt)
			{
				$item['sumStateQuantity'] = ($item['sumInQuantity'] ?? 0.0) + ($item['sumOutQuantity'] ?? 0.0);
				$item['sumStatePrice'] = ($item['sumInPrice'] ?? 0.0) + ($item['sumOutPrice'] ?? 0.0);
				$this->data[] = $item;
			}
		}
	}

	function loadData_Months()
	{
		$itemNdx = intval($this->queryParams['item_ndx']);
		$fiscalYear = intval(substr($this->queryParams['year_id'], 2));
		$months = $this->db()->query(
													'SELECT * FROM [e10doc_base_fiscalmonths] WHERE [fiscalType] = 0 AND [fiscalYear] = %i',
													$fiscalYear,
													' ORDER BY [globalOrder]');


		$sumStateQuantity = 0.0;
		$sumStatePrice = 0.0;

		foreach ($months as $m)
		{
			$q = [];
			array_push($q, 'SELECT SUM(quantity) as quantity, SUM(price) as price, item, unit, moveType');
			array_push($q, ' FROM [e10doc_inventory_journal] WHERE [item] = %i', $itemNdx);
			array_push($q, ' AND [fiscalYear] = %i', $fiscalYear);
			array_push($q, ' AND [date] <= %d', $m['end']);
			array_push($q, ' AND [date] >= %d', $m['start']);
			array_push($q, ' GROUP BY unit, moveType, item');
			$rows = $this->db()->query($q);


			$sumId = 'FY'.$fiscalYear.'-'.$m['ndx'];

			$item = ['id' => $m['calendarYear'].'/'.$m['calendarMonth']];
			$item['note'] = 'Test 456';
			$item['_options'] = [
				'expandable' => [
					'column' => 'id', 'level' => $this->level,
					'exp-this-id' => $sumId,
					'exp-parent-id' => isset($this->queryParams['year_id']) ? $this->queryParams['year_id'] : '',
					'query-params' => ['month_id' => $sumId]
				]
			];

			$cnt = 0;
			foreach ($rows as $r)
			{
				if ($r['moveType'] === 0)
				{
					$item['sumInQuantity'] = $r['quantity'];
					$item['sumInPrice'] = $r['price'];
				}
				else
				{
					$item['sumOutQuantity'] = $r['quantity'];
					$item['sumOutPrice'] = $r['price'];
				}

				if (!isset($item['unit']))
					$item['unit'] = $r['unit'];
				elseif ($item['unit'] !== $r['unit'])
					$item['unit'] .= ' ! '.$r['unit'];
				$cnt++;
			}
			if ($cnt)
			{
				$sumStateQuantity += ($item['sumInQuantity'] ?? 0.0) + ($item['sumOutQuantity'] ?? 0.0);
				$sumStatePrice += ($item['sumInPrice'] ?? 0.0) + ($item['sumOutPrice'] ?? 0.0);
				$item['sumStateQuantity'] = $sumStateQuantity;
				$item['sumStatePrice'] = $sumStatePrice;

				$this->data[] = $item;
			}
		}
	}

	function loadData_Days()
	{
		$itemNdx = intval($this->queryParams['item_ndx']);

		$periodParts = explode('-', $this->queryParams['month_id']);
		$fiscalYear = intval(substr($periodParts[0], 2));
		$fiscalMonth = intval($periodParts[1]);

		$m = $this->db()->query('SELECT * FROM [e10doc_base_fiscalmonths] WHERE [ndx] = %i', $fiscalMonth)->fetch();

		$sumStateQuantity = 0.0;
		$sumStatePrice = 0.0;
		$this->getItemEndState($itemNdx, $fiscalYear, $m['start'], $sumStateQuantity, $sumStatePrice);

		$q = [];
		array_push($q, 'SELECT SUM(quantity) as quantity, SUM(price) as price, item, unit, moveType, [date]');
		array_push($q, ' FROM [e10doc_inventory_journal] WHERE [item] = %i', $itemNdx);
		array_push($q, ' AND [fiscalYear] = %i', $fiscalYear);
		array_push($q, ' AND [date] <= %d', $m['end']);
		array_push($q, ' AND [date] >= %d', $m['start']);
		array_push($q, ' GROUP BY date, unit, moveType, item');
		$rows = $this->db()->query($q);

		$sums = [];
		foreach ($rows as $r)
		{
			$dateId = $r['date']->format('Y-m-d');
			if (!isset($sums[$dateId]))
				$sums[$dateId] = ['date' => $r['date']];

			if ($r['moveType'] === 0)
			{
				$sums[$dateId]['sumInQuantity'] = $r['quantity'];
				$sums[$dateId]['sumInPrice'] = $r['price'];
			}
			else
			{
				$sums[$dateId]['sumOutQuantity'] = $r['quantity'];
				$sums[$dateId]['sumOutPrice'] = $r['price'];
			}

			if (!isset($sums[$dateId]['unit']))
				$sums[$dateId]['unit'] = $r['unit'];
			elseif ($sums[$dateId]['unit'] !== $r['unit'])
				$sums[$dateId]['unit'] .= ' ! '.$r['unit'];
		}


		foreach ($sums as $dateId => $dateValues)
		{
			$sumId = 'FY'.$fiscalYear.'-'.$m['ndx'].'_'.$dateId;

			$item = [
				'id' => $dateId,
				'sumInQuantity' => $dateValues['sumInQuantity'] ?? 0.0,
				'sumInPrice' => $dateValues['sumInPrice'] ?? 0.0,
				'sumOutQuantity' => $dateValues['sumOutQuantity'] ?? 0.0,
				'sumOutPrice' => $dateValues['sumOutPrice'] ?? 0.0,
				'unit' => $dateValues['unit'],
			];
			$item['_options'] = [
				'expandable' => [
					'column' => 'id', 'level' => $this->level,
					'exp-this-id' => $sumId,
					'exp-parent-id' => isset($this->queryParams['month_id']) ? $this->queryParams['month_id'] : '',
					'query-params' => ['day_id' => $sumId]
				]
			];

			$sumStateQuantity += ($item['sumInQuantity'] ?? 0.0) + ($item['sumOutQuantity'] ?? 0.0);
			$sumStatePrice += ($item['sumInPrice'] ?? 0.0) + ($item['sumOutPrice'] ?? 0.0);
			$item['sumStateQuantity'] = $sumStateQuantity;
			$item['sumStatePrice'] = $sumStatePrice;

			$this->data[] = $item;
		}
	}

	function loadData_DayDocs()
	{
		$itemNdx = intval($this->queryParams['item_ndx']);

		$ownerParts = explode('_', $this->queryParams['day_id']);
		$dateId = $ownerParts[1];
		$periodParts = explode('-', $ownerParts[0]);
		$fiscalYear = intval(substr($periodParts[0], 2));

		$sumStateQuantity = 0.0;
		$sumStatePrice = 0.0;
		$this->getItemEndState($itemNdx, $fiscalYear, Utils::createDateTime($dateId), $sumStateQuantity, $sumStatePrice);

		$q = [];
		array_push($q, 'SELECT SUM(journal.quantity) as quantity, SUM(journal.price) as price, item, unit, moveType, docHead, heads.docNumber, heads.docType AS headDocType');
		array_push($q, ' FROM [e10doc_inventory_journal] AS journal');
		array_push($q, ' LEFT JOIN e10doc_core_heads AS heads ON journal.docHead = heads.ndx ');
		array_push($q, ' WHERE [item] = %i', $itemNdx);
		array_push($q, ' AND [journal].[fiscalYear] = %i', $fiscalYear);
		array_push($q, ' AND [journal].[date] = %d', $dateId);
		array_push($q, ' GROUP BY docNumber, journal.unit, journal.moveType, journal.item');
		$rows = $this->db()->query($q);

		$sums = [];
		foreach ($rows as $r)
		{
			$docId = $r['docNumber'];
			if (!isset($sums[$docId]))
				$sums[$docId] = ['docHead' => $r['docHead'], 'docNumber' => $r['docNumber'], 'headDocType' => $r['headDocType']];

			if ($r['moveType'] === 0)
			{
				$sums[$docId]['sumInQuantity'] = $r['quantity'];
				$sums[$docId]['sumInPrice'] = $r['price'];
			}
			else
			{
				$sums[$docId]['sumOutQuantity'] = $r['quantity'];
				$sums[$docId]['sumOutPrice'] = $r['price'];
			}

			if (!isset($sums[$docId]['unit']))
				$sums[$docId]['unit'] = $r['unit'];
			elseif ($sums[$docId]['unit'] !== $r['unit'])
				$sums[$docId]['unit'] .= ' ! '.$r['unit'];
		}


		foreach ($sums as $docId => $docValues)
		{
			$sumId = $docId;

			$item = [
				'id' => [
					'text'=> $docValues['docNumber'], 'icon' => $this->docIcon($docValues),
					'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $docValues['docHead']
				],
				'sumInQuantity' => $docValues['sumInQuantity'],
				'sumInPrice' => $docValues['sumInPrice'],
				'sumOutQuantity' => $docValues['sumOutQuantity'],
				'sumOutPrice' => $docValues['sumOutPrice'],
				'unit' => $docValues['unit'] ?? '',
			];

			$item['_options'] = [
				'expandable' => [
					//'column' => 'id',
					'level' => $this->level,
					//'exp-this-id' => $sumId,
					'exp-parent-id' => isset($this->queryParams['day_id']) ? $this->queryParams['day_id'] : '',
					'query-params' => ['doc_id' => $sumId]
				]
			];

			$sumStateQuantity += ($item['sumInQuantity'] ?? 0.0) + ($item['sumOutQuantity'] ?? 0.0);
			$sumStatePrice += ($item['sumInPrice'] ?? 0.0) + ($item['sumOutPrice'] ?? 0.0);
			$item['sumStateQuantity'] = $sumStateQuantity;
			$item['sumStatePrice'] = $sumStatePrice;

			$this->data[] = $item;
		}
	}

	function getItemEndState($itemNdx, $fiscalYear, $toDate, &$quantity, &$price)
	{
		$q = [];
		array_push($q, 'SELECT SUM(quantity) as quantity, SUM(price) as price');
		array_push($q, ' FROM [e10doc_inventory_journal] WHERE [item] = %i', $itemNdx);
		array_push($q, ' AND [fiscalYear] = %i', $fiscalYear);
		array_push($q, ' AND [date] < %d', $toDate);
		$states = $this->db()->query($q)->fetch();
		if ($states)
		{
			$quantity = $states['quantity'];
			$price = $states['price'];
		}
	}

	function docIcon($r)
	{
		return $this->docTypes[$r['headDocType']]['icon'];
	}
}
