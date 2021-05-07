<?php

namespace e10mnf\core;

use \lib\core\ui\SumTable, \e10\utils;


/**
 * Class SumTableWorks
 * @package e10mnf\core
 */
class SumTableWorks extends SumTable
{
	var $dataAll = [];
	var $dataSums = [];

	public function init()
	{
		parent::init();

		$this->objectClassId = 'e10mnf.core.SumTableWorks';

		$this->header = [
			'id' => '>ID',
			'note' => 'Text',
			'totalTime' => ' Čas',
		];

		$this->colClasses['id'] = 'nowrap width10em';
		$this->colClasses['totalTime'] = 'nowrap width10';
	}

	function loadData()
	{
		if ($this->level === 0)
			$this->loadData_Months();
		elseif ($this->level === 1)
			$this->loadData_Days();
		elseif ($this->level === 2)
			$this->loadData_Rows();
	}

	function loadData_Months()
	{
		$sumTotal = ['id' => 'CELKEM', 'totalTime' => 0];

		$q[] = 'SELECT [rows].*, [head].docNumber ';
		array_push($q, ' FROM e10mnf_core_workRecsRows AS [rows] LEFT JOIN [e10mnf_core_workRecs] as [head] ON [rows].[workRec] = head.[ndx]');
		array_push($q, ' WHERE 1');
		$this->applyQueryParams ($q);

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			if (isset ($r['beginDate']))
				$monthId =  $r['beginDate']->format('Y-m');
			else
				$monthId =  $r['docNumber'];

			if (!isset($this->dataSums[$monthId]))
				$this->dataSums[$monthId] = ['sumId' => $monthId, 'id' => $monthId, 'totalTime' => 0];

			$this->dataSums[$monthId]['totalTime'] += $r['timeLen'];
			$sumTotal['totalTime'] += $r['timeLen'];
		}

		foreach ($this->dataSums as $r)
		{
			$sumId = $r['sumId'];
			$item = ['id' => $r['id'], 'totalTime' => utils::nf(round($r['totalTime']/60/60, 1), 1).' hod'];

			$item['_options'] = ['expandable' => [
					'column' => 'id', 'level' => $this->level,
					'exp-this-id' => $sumId,
					'exp-parent-id' => '',
					'query-params' => ['month_id' => $sumId]
				]
			];
			$this->data[] = $item;
		}

		// -- total sum
		$item = [
			'id' => ['text' => 'CELKEM', 'icontxt' => ' ∑ '],
			'totalTime' => utils::nf(round($sumTotal['totalTime']/60/60, 1), 1).' hod',
			'_options' => ['class' => 'sumtotal', 'colSpan' => ['id' => 2]],
		];
		$this->data[] = $item;
	}

	function loadData_Days()
	{
		$q[] = 'SELECT [rows].* ';
		array_push($q, ' FROM e10mnf_core_workRecsRows AS [rows]');
		array_push($q, ' WHERE 1');
		$this->applyQueryParams ($q);
		array_push($q, ' ORDER BY [beginDate], ndx');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$dayId = $r['beginDate']->format('Y-m-d');

			if (!isset($this->dataSums[$dayId]))
				$this->dataSums[$dayId] = ['sumId' => $dayId, 'id' => $dayId, 'totalTime' => 0];

			$this->dataSums[$dayId]['totalTime'] += $r['timeLen'];
		}

		foreach ($this->dataSums as $r)
		{
			$sumId = $r['sumId'];
			$item = ['id' => $r['id'], 'totalTime' => utils::nf(round($r['totalTime']/60/60, 1), 1).' hod'];

			$item['_options'] = ['expandable' => [
				'column' => 'id', 'level' => $this->level,
				'exp-this-id' => $sumId,
				'exp-parent-id' => isset($this->queryParams['month_id']) ? $this->queryParams['month_id'] : '',
				'query-params' => ['day_id' => $sumId]
				]
			];
			$this->data[] = $item;
		}
	}

	function loadData_Rows()
	{
		$q[] = 'SELECT [rows].*, heads.docNumber ';
		array_push($q, ' FROM e10mnf_core_workRecsRows AS [rows]');
		array_push($q, ' LEFT JOIN e10mnf_core_workRecs AS heads ON [rows].workRec = heads.ndx');
		array_push($q, ' WHERE 1');
		$this->applyQueryParams ($q);

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$rowId = strval($r['ndx']);
			$item = [
				'id' => $r['docNumber'],
				'totalTime' => utils::nf(round($r['timeLen']/60/60, 1), 1).' hod',
				'note' => $r['subject']
			];

			$item['_options'] = ['expandable' => [
					'level' => $this->level,
					'exp-this-id' => $r['ndx'],
					'exp-parent-id' => isset($this->queryParams['day_id']) ? $this->queryParams['day_id'] : '',
				]
			];

			$this->data[$rowId] = $item;
		}
	}

	function applyQueryParams (&$q)
	{
		if (isset($this->queryParams['work_order']))
			array_push ($q, ' AND [rows].[workOrder] = %i', $this->queryParams['work_order']);


		if (isset($this->queryParams['month_id']))
		{
			$dateBegin = utils::createDateTime($this->queryParams['month_id'].'-01');
			$dateEnd = utils::createDateTime($dateBegin->format ('Y-M-t'));
			array_push($q, ' AND ([rows].[beginDate] >= %t', $dateBegin, 'AND [rows].[beginDate] <= %t', $dateEnd, ')');
		}

		if (isset($this->queryParams['day_id']))
		{
			$dateBegin = utils::createDateTime($this->queryParams['day_id'].' 00:00:00');
			$dateEnd = utils::createDateTime($this->queryParams['day_id'].' 23:59:59');
			array_push($q, ' AND ([rows].[beginDate] >= %t', $dateBegin, 'AND [rows].[beginDate] <= %t', $dateEnd, ')');
		}
	}
}
