<?php


namespace e10doc\core\libs;

use \lib\core\ui\SumTable, \Shipard\Utils\Utils;

/**
 * class SumTablePersonAnalysis
 */
class SumTablePersonAnalysis extends SumTable
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

		$this->objectClassId = 'e10doc.core.libs.SumTablePersonAnalysis';

		$this->header = [
			'id' => '>Období',
			'sumInPrice' => ' Nákup',
			'sumOutPrice' => ' Prodej',
			'sumOtherPrice' => ' Ost.',
		];

		$this->colClasses['id'] = 'nowrap width16em';
		/*
		$this->extraHeader = [
			[
				'id' => isset($this->options['headerTitle']) ? $this->options['headerTitle'] : 'Obraty',
				'_options' => [
					'colSpan' => ['id' => 8],
					'cellCss' => ['id' => 'background-color: #eef !important; font-weight: normal;'],
				]
			],
			[
				'id' => 'Období',
				'unit' => 'Jed',
				'sumInQuantity' => 'Nákup',
				'sumOutQuantity' => 'Prodej',
				'sumOtherQuantity' => 'Ostatní',
				'_options' => [
					'rowSpan' => ['id' => 2, 'unit' => 2],
					'colSpan' => ['sumInQuantity' => 2, 'sumOutQuantity' => 2, 'sumOtherQuantity' => 2],
					'cellClasses' => ['sumInQuantity' => 'center', 'sumOutQuantity' => 'center', 'sumOtherQuantity' => 'center', 'unit' => 'number'],
				]
			],
			[
				'sumInQuantity' => 'Množství',
				'sumInPrice' => 'Cena',
				'sumOutQuantity' => 'Množství',
				'sumOutPrice' => 'Cena',
				'sumOtherQuantity' => 'Množství',
				'sumOtherPrice' => 'Cena',
				'_options' => [
					'cellClasses' => [
						'sumInQuantity' => 'number', 'sumOutQuantity' => 'number', 'sumOtherQuantity' => 'number',
						'sumInPrice' => 'number', 'sumOutPrice' => 'number', 'sumOtherPrice' => 'number',
					],
				]
			]
		];
		*/
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
		$personNdx = intval($this->queryParams['person_ndx']);

		$fyscalYears = array_reverse($this->app->cfgItem ('e10doc.acc.periods'), TRUE);
		foreach ($fyscalYears as $fpId => $fp)
		{
			$fiscalYear = intval($fpId);

			$q = [];
			array_push($q, 'SELECT SUM([rows].taxBaseHC) AS price, [unit],');
			array_push($q, ' heads.docType AS docType, heads.cashBoxDir AS cashBoxDir');
			array_push($q, ' FROM e10doc_core_rows as [rows]');
			array_push($q, ' LEFT JOIN e10doc_core_heads as heads ON (heads.ndx = [rows].document)');
			array_push($q, ' WHERE [heads].person = %i', $personNdx);
			array_push($q, ' AND heads.docState = 4000');
			array_push($q, ' AND heads.[fiscalYear] = %i', $fiscalYear);
			array_push($q, ' GROUP BY docType, cashBoxDir');

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
				$td = $this->docTradeDir($r['docType'], $r['cashBoxDir']);
				if ($td === 2)
				{
					$item['sumInPrice'] = $r['price'];
				}
				elseif ($td === 1)
				{
					$item['sumOutPrice'] = $r['price'];
				}
				else
				{
					$item['sumOtherPrice'] = $r['price'];
				}

				$cnt++;
			}
			if ($cnt)
			{
				$this->data[] = $item;
			}
		}
	}

	function loadData_Months()
	{
		$personNdx = intval($this->queryParams['person_ndx']);
		$fiscalYear = intval(substr($this->queryParams['year_id'], 2));
		$months = $this->db()->query('SELECT * FROM [e10doc_base_fiscalmonths] ',
																	' WHERE [fiscalType] = 0 AND [fiscalYear] = %i', $fiscalYear,
																	' ORDER BY [globalOrder]');

		foreach ($months as $m)
		{
			$q = [];
			array_push($q, 'SELECT SUM([rows].taxBaseHC) AS price,');
			array_push($q, ' heads.docType as docType, heads.cashBoxDir AS cashBoxDir');
			array_push($q, ' FROM e10doc_core_rows as [rows]');
			array_push($q, ' LEFT JOIN e10doc_core_heads as heads ON (heads.ndx = [rows].document)');
			array_push($q, ' WHERE [heads].person = %i', $personNdx);
			array_push($q, ' AND heads.docState = 4000');
			array_push($q, ' AND heads.[fiscalYear] = %i', $fiscalYear);
			array_push($q, ' AND heads.[dateAccounting] <= %d', $m['end']);
			array_push($q, ' AND heads.[dateAccounting] >= %d', $m['start']);
			array_push($q, ' GROUP BY docType, cashBoxDir');

			$rows = $this->db()->query($q);


			$sumId = 'FY'.$fiscalYear.'-'.$m['ndx'];

			$item = ['id' => $m['calendarYear'].'/'.$m['calendarMonth']];
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
				$td = $this->docTradeDir($r['docType'], $r['cashBoxDir']);
				if ($td === 2)
				{
					$item['sumInPrice'] = $r['price'];
				}
				elseif ($td === 1)
				{
					$item['sumOutPrice'] = $r['price'];
				}
				else
				{
					$item['sumOtherPrice'] = $r['price'];
				}
				$cnt++;
			}
			if ($cnt)
			{
				$this->data[] = $item;
			}
		}
	}

	function loadData_Days()
	{
		$personNdx = intval($this->queryParams['person_ndx']);

		$periodParts = explode('-', $this->queryParams['month_id']);
		$fiscalYear = intval(substr($periodParts[0], 2));
		$fiscalMonth = intval($periodParts[1]);

		$m = $this->db()->query('SELECT * FROM [e10doc_base_fiscalmonths] WHERE [ndx] = %i', $fiscalMonth)->fetch();

		$q = [];
		array_push($q, 'SELECT SUM([rows].taxBaseHC) AS price,');
		array_push($q, ' heads.docType as docType, heads.cashBoxDir AS cashBoxDir, [heads].dateAccounting AS [date]');
		array_push($q, ' FROM e10doc_core_rows as [rows]');
		array_push($q, ' LEFT JOIN e10doc_core_heads as heads ON (heads.ndx = [rows].document)');
		array_push($q, ' WHERE [heads].person = %i', $personNdx);
		array_push($q, ' AND heads.docState = 4000');
		array_push($q, ' AND heads.[fiscalYear] = %i', $fiscalYear);
		array_push($q, ' AND heads.[dateAccounting] <= %d', $m['end']);
		array_push($q, ' AND heads.[dateAccounting] >= %d', $m['start']);
		array_push($q, ' GROUP BY [heads].dateAccounting, docType, cashBoxDir');

		$rows = $this->db()->query($q);

		$sums = [];
		foreach ($rows as $r)
		{
			$dateId = $r['date']->format('Y-m-d');
			if (!isset($sums[$dateId]))
				$sums[$dateId] = ['date' => $r['date']];

			$td = $this->docTradeDir($r['docType'], $r['cashBoxDir']);
			if ($td === 2)
			{
				$sums[$dateId]['sumInPrice'] = $r['price'];
			}
			elseif ($td === 1)
			{
				$sums[$dateId]['sumOutPrice'] = $r['price'];
			}
			else
			{
				$sums[$dateId]['sumOtherPrice'] = $r['price'];
			}
		}


		foreach ($sums as $dateId => $dateValues)
		{
			$sumId = 'FY'.$fiscalYear.'-'.$m['ndx'].'_'.$dateId;

			$item = [
				'id' => $dateId,
				'sumInPrice' => $dateValues['sumInPrice'] ?? 0.0,
				'sumOutPrice' => $dateValues['sumOutPrice'] ?? 0.0,
				'sumOtherPrice' => $dateValues['sumOtherPrice'] ?? 0.0,
			];
			$item['_options'] = [
				'expandable' => [
					'column' => 'id', 'level' => $this->level,
					'exp-this-id' => $sumId,
					'exp-parent-id' => isset($this->queryParams['month_id']) ? $this->queryParams['month_id'] : '',
					'query-params' => ['day_id' => $sumId]
				]
			];

			$this->data[] = $item;
		}
	}

	function loadData_DayDocs()
	{
		$personNdx = intval($this->queryParams['person_ndx']);

		$ownerParts = explode('_', $this->queryParams['day_id']);
		$dateId = $ownerParts[1];
		$periodParts = explode('-', $ownerParts[0]);
		$fiscalYear = intval(substr($periodParts[0], 2));

		$q = [];
		array_push($q, 'SELECT SUM([rows].quantity) AS quantity, SUM([rows].taxBase) AS price, [unit],');
		array_push($q, ' heads.docType AS docType, [heads].dateAccounting AS [date], heads.docNumber AS docNumber, ');
		array_push($q, ' heads.ndx AS docHead, heads.cashBoxDir AS cashBoxDir');
		array_push($q, ' FROM e10doc_core_rows AS [rows]');
		array_push($q, ' LEFT JOIN e10doc_core_heads as heads ON (heads.ndx = [rows].document)');
		array_push($q, ' WHERE [heads].person = %i', $personNdx);
		array_push($q, ' AND heads.docState = 4000');
		array_push($q, ' AND heads.[fiscalYear] = %i', $fiscalYear);
		array_push($q, ' AND heads.[dateAccounting] = %d', $dateId);
		array_push($q, ' GROUP BY [heads].docNumber, heads.docType');

		$rows = $this->db()->query($q);

		$sums = [];
		foreach ($rows as $r)
		{
			$docId = $r['docNumber'];
			if (!isset($sums[$docId]))
				$sums[$docId] = ['docHead' => $r['docHead'], 'docNumber' => $r['docNumber'], 'headDocType' => $r['docType']];

			$td = $this->docTradeDir($r['docType'], $r['cashBoxDir']);
			if ($td === 2)
			{
				$sums[$docId]['sumInPrice'] = $r['price'];
			}
			elseif ($td === 1)
			{
				$sums[$docId]['sumOutPrice'] = $r['price'];
			}
			else
			{
				$sums[$docId]['sumOtherPrice'] = $r['price'];
			}
		}


		foreach ($sums as $docId => $docValues)
		{
			$sumId = $docId;

			$item = [
				'id' => [
					'text'=> $docValues['docNumber'], 'icon' => $this->docIcon($docValues),
					'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $docValues['docHead']
				],
				'sumInQuantity' => $docValues['sumInQuantity'] ?? 0.0,
				'sumInPrice' => $docValues['sumInPrice'] ?? 0.0,
				'sumOutQuantity' => $docValues['sumOutQuantity'] ?? 0.0,
				'sumOutPrice' => $docValues['sumOutPrice'] ?? 0.0,
				'sumOtherQuantity' => $docValues['sumOtherQuantity'] ?? 0.0,
				'sumOtherPrice' => $docValues['sumOtherPrice'] ?? 0.0,
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

			$this->data[] = $item;
		}
	}

	function docTradeDir($docTypeId, $cashBoxDir)
	{
		if ($docTypeId === 'cash')
		{
			if ($cashBoxDir == 1)
				return 1; // in --> out
			elseif ($cashBoxDir == 2)
				return 2; // out --> in
		}
		$docType = $this->docTypes[$docTypeId] ?? NULL;
		if (!$docType)
			return 0;
		$td = intval($docType['tradeDir']) ?? 0;
		return $td;
	}

	function docIcon($r)
	{
		return $this->docTypes[$r['headDocType']]['icon'];
	}
}
