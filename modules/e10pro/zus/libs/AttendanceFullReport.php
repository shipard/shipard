<?php

namespace e10mnf\core\libs;

use \Shipard\Utils\Utils, e10doc\core\libs\E10Utils, \e10doc\core\libs\Aggregate;


/**
 * Class WorkedHoursReport
 * @package e10mnf\core\libs
 */
class WorkedHoursReport extends \e10doc\core\libs\reports\GlobalReport
{
	var $tableWorkOrders;
	var $fiscalPeriod = 0;
	var $fiscalYear = 0;
	var $period = Aggregate::periodDaily;

	var $data = [];
	var $header = [];

	function init ()
	{
		set_time_limit (0);

		$this->tableWorkOrders = $this->app()->table('e10mnf.core.workOrders');

		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years']]);

		parent::init();


		if ($this->fiscalPeriod === 0)
			$this->fiscalPeriod = $this->reportParams ['fiscalPeriod']['value'];
		if ($this->fiscalYear === 0)
			$this->fiscalYear = $this->reportParams ['fiscalPeriod']['values'][$this->fiscalPeriod]['fiscalYear'];

		$this->period = Aggregate::periodDaily;
		if ($this->reportParams ['fiscalPeriod']['value'] == 0 || $this->reportParams ['fiscalPeriod']['value'][0] === 'Y' || strstr ($this->reportParams ['fiscalPeriod']['value'], ',') !== FALSE)
			$this->period = Aggregate::periodMonthly;


		$this->setInfo('title', 'Odpracované hodiny');
		$this->setInfo('icon', 'reportHoursWorked');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);

		$this->paperOrientation = 'landscape';
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'persons': $this->createContent_Persons(); break;
			case 'workOrders': $this->createContent_WorkOrders(); break;
		}
	}

	function createContent_Persons()
	{
		$this->header = [];

		$q [] = 'SELECT workRecsRows.*, ';
		array_push ($q, ' workRecs.person AS personNdx, ');
		array_push ($q, ' persons.fullName as personFullName ');

		array_push ($q, ' FROM [e10mnf_core_workRecsRows] AS workRecsRows');
		array_push ($q, ' LEFT JOIN [e10mnf_core_workRecs] AS workRecs ON [workRecsRows].workRec = [workRecs].ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON workRecs.person = persons.ndx');
		array_push ($q, ' WHERE 1');


		E10Utils::fiscalPeriodDateQuery ($this->app, $q, '[workRecsRows].[beginDate]', $this->reportParams ['fiscalPeriod']['value']);

		array_push ($q, ' ORDER BY persons.lastName, persons.firstName, [workRecsRows].[beginDate]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($this->period === Aggregate::periodDaily)
			{
				$colId = 'D'.$r['beginDate']->format('Ymd');
				$colName = $r['beginDate']->format('d');
			}
			else
			{
				$colId = 'M' . $r['beginDate']->format('Ym');
				//$colName = $r['beginDate']->format('m');
				$colName = Utils::$monthSc3[intval($r['beginDate']->format('m')) - 1];
			}

			$personNdx = $r['personNdx'];

			if (!isset($this->header[$colId]))
				$this->header[$colId] = '+'.$colName;

			if (!isset($this->data[$personNdx]))
			{
				$this->data[$personNdx] = ['person' => $r['personFullName'], 'A00' => 0,0];
			}

			if (!isset($this->data[$personNdx][$colId]))
			{
				$this->data[$personNdx][$colId] = 0.0;
			}

			$this->data[$personNdx][$colId] += $r['timeLen'] / 3600;
			$this->data[$personNdx]['A00'] += $r['timeLen'] / 3600;
		}

		ksort($this->header);
		$this->header = array_merge(['#' => '#', 'person' => 'Osoba', 'A00' => '+Celkem'], $this->header);


		$this->addContent (['type' => 'table', 'header' => $this->header, 'table' => $this->data, 'main' => TRUE, 'params' => ['precision' => 1, 'tableClass' => 'rowsSmall']]);
	}

	function createContent_WorkOrders()
	{
		$this->header = [];

		$q [] = 'SELECT workRecsRows.*, ';
		array_push ($q, ' workRecs.person AS personNdx, ');
		array_push ($q, ' workOrders.docNumber AS woDocNumber ');

		array_push ($q, ' FROM [e10mnf_core_workRecsRows] AS workRecsRows');
		array_push ($q, ' LEFT JOIN [e10mnf_core_workRecs] AS workRecs ON [workRecsRows].workRec = [workRecs].ndx');
		array_push ($q, ' LEFT JOIN [e10mnf_core_workOrders] AS workOrders ON [workRecsRows].workOrder = [workOrders].ndx');
		//array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON workRecs.person = persons.ndx');
		array_push ($q, ' WHERE 1');


		E10Utils::fiscalPeriodDateQuery ($this->app, $q, '[workRecsRows].[beginDate]', $this->reportParams ['fiscalPeriod']['value']);

		array_push ($q, ' ORDER BY workOrders.docNumber, [workRecsRows].[beginDate]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($this->period === Aggregate::periodDaily)
			{
				$colId = 'D'.$r['beginDate']->format('Ymd');
				$colName = $r['beginDate']->format('d');
			}
			else
			{
				$colId = 'M' . $r['beginDate']->format('Ym');
				//$colName = $r['beginDate']->format('m');
				$colName = Utils::$monthSc3[intval($r['beginDate']->format('m')) - 1];
			}

			$workOrderNdx = $r['workOrder'];

			if (!isset($this->header[$colId]))
				$this->header[$colId] = '+'.$colName;

			if (!isset($this->data[$workOrderNdx]))
			{
				$this->data[$workOrderNdx] = ['workOrder' => $r['woDocNumber'], 'A00' => 0,0];
			}

			if (!isset($this->data[$workOrderNdx][$colId]))
			{
				$this->data[$workOrderNdx][$colId] = 0.0;
			}

			$this->data[$workOrderNdx][$colId] += $r['timeLen'] / 3600;
			$this->data[$workOrderNdx]['A00'] += $r['timeLen'] / 3600;
		}

		ksort($this->header);
		$this->header = array_merge(['#' => '#', 'workOrder' => 'Zakázka', 'A00' => '+Celkem'], $this->header);


		$this->addContent (['type' => 'table', 'header' => $this->header, 'table' => $this->data, 'main' => TRUE, 'params' => ['precision' => 1, 'tableClass' => 'rowsSmall']]);
	}

	public function subReportsList ()
	{
		$useWorkOrders = intval($this->app()->cfgItem('options.e10doc-commerce.useWorkOrders', 0));

		$d[] = ['id' => 'persons', 'icon' => 'detailPersons', 'title' => 'Lidé'];
		if ($useWorkOrders)
			$d[] = ['id' => 'workOrders', 'icon' => 'detailWorkOrders', 'title' => 'Zakázky'];
		return $d;
	}
}
