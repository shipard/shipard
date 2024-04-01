<?php

namespace e10mnf\reports\analysis;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \Shipard\Utils\Utils, e10doc\core\libs\E10Utils;


/**
 * Class WorkOrdersAnalysisReport
 */
class WorkOrdersAnalysisReport extends \e10doc\core\libs\reports\GlobalReport
{
	var $tableWorkOrders;
	var $fiscalPeriod = 0;
	var $fiscalYear = 0;

	var $currencies;
	var $dbCounters;

	var $workOrders = [];
	var $data = [];

	var $viewTypes = ['all' => 'Všechny', 'loss' => 'Ztrátové', 'errors' => 'Chybné'];

	function init ()
	{
		set_time_limit (0);
		ini_set('memory_limit', '1024M');

		$this->tableWorkOrders = $this->app()->table('e10mnf.core.workOrders');

		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years']]);
		$this->addFiltersParams();
		$this->addAdminsParam();
		$this->addParam ('switch', 'viewType', ['title' => 'Zobrazit', 'switch' => $this->viewTypes, 'radioBtn' => 1, 'defaultValue' => 'all']);

		parent::init();

		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->dbCounters = $this->app->cfgItem ('e10mnf.workOrders.dbCounters', []);

		if ($this->fiscalPeriod === 0)
			$this->fiscalPeriod = $this->reportParams ['fiscalPeriod']['value'];
		if ($this->fiscalYear === 0)
			$this->fiscalYear = $this->reportParams ['fiscalPeriod']['values'][$this->fiscalPeriod]['fiscalYear'];

		$this->setInfo('title', 'Výsledky zakázek');
		$this->setInfo('icon', 'reportWorkOrdersResults');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		$this->setInfo('param', 'Filtr', $this->reportParams ['filter']['activeTitle']);

		if ($this->reportParams ['viewType']['value'] !== 'all')
			$this->setInfo('param', 'Zakázky', $this->reportParams ['viewType']['activeTitle']);

		$this->paperOrientation = 'landscape';
	}

	function createContent ()
	{
		$this->loadData();
		$this->calcData();

		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum (); break;
		}
	}

	function createContent_Sum ()
	{
		$h = [
				'#' => '#', 'docNumber' => 'Zakázka',
				'dbCounter' => 'Č.ř.',
				'closed' => 'Ukončeno',
				't' => '|',
				'admins' => 'Garant',
				'customer' => 'Odběratel', 'price' => ' Cena', 'curr' => 'Měna',
				'd' => '+Náklady', 'c' => '+Výnosy', 'b' => '+Výsledek',
				'gm' => ' Marže %'
		];

		if ($this->reportParams ['admin']['value'])
			unset ($h['admins']);

		$filter = $this->reportParams ['filter']['value'];
		if ($filter[0] === 'C')
			unset ($h['dbCounter']);

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data, 'main' => TRUE]);
	}

	function loadData()
	{
		$q [] = 'SELECT workOrders.*, ';
		array_push ($q, ' customers.fullName as customerFullName ');
		array_push ($q, ' FROM [e10mnf_core_workOrders] as workOrders');
		array_push ($q, ' LEFT JOIN e10_persons_persons as customers ON workOrders.customer = customers.ndx');
		array_push ($q, ' WHERE 1');

		$filter = $this->reportParams ['filter']['value'];
		if ($filter[0] === 'C')
			array_push ($q, ' AND workOrders.[dbCounter] = %i', substr($filter, 1));
		elseif ($filter[0] === 'D')
			array_push ($q, ' AND workOrders.[docKind] = %i', substr($filter, 1));

		array_push ($q, ' AND workOrders.[dateClosed] IS NOT NULL');
		E10Utils::fiscalPeriodDateQuery ($this->app, $q, '[workOrders].[dateClosed]', $this->reportParams ['fiscalPeriod']['value']);

		$admin = $this->reportParams ['admin']['value'];
		if ($admin)
		{
			array_push ($q,
			' AND EXISTS (SELECT ndx FROM e10_base_doclinks AS l WHERE linkId = %s', 'e10mnf-workRecs-admins',
			' AND srcTableId = %s', 'e10mnf.core.workOrders', ' AND l.srcRecId = workOrders.ndx',
			' AND l.dstRecId = %i', $admin, ')'
			);
			$this->setInfo('param', 'Garant', $this->reportParams ['admin']['activeTitle']);
		}

		array_push ($q, ' ORDER BY workOrders.docNumber');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$lp = \E10\Base\linkedPersons ($this->app(), $this->tableWorkOrders, $r['ndx'], '');
			$admins = isset($lp[$r['ndx']]['e10mnf-workRecs-admins']) ? $lp[$r['ndx']]['e10mnf-workRecs-admins'] : '';
			if (is_array($admins))
			{
				foreach ($admins as &$a)
					unset ($a['icon']);
			}
			$dbCounter = $this->dbCounters[$r['dbCounter']];
			$item = [
				'ndx' => $r['ndx'],
				'docNumber' => ['text' => $r['docNumber'], 'docAction' => 'show', 'table' => 'e10mnf.core.workOrders', 'pk' => $r['ndx']],
				'customer' => $r['customerFullName'],
				'admins' => $admins,
				'closed' => $r['dateClosed'],
				'price' => $r['sumPrice'],
				'curr' => $this->currencies[$r ['currency']]['shortcut'],
				'dbCounter' => ($dbCounter['tabName']) ? $dbCounter['tabName'] : $dbCounter['shortName'],
			];
			$this->workOrders[] = $item;
		}
	}

	function calcData()
	{
		foreach ($this->workOrders as $r)
		{
			$item = $r;

			$e = $this->tableWorkOrders->analysisEngine();
			$e->setWorkOrder($r['ndx']);
			$e->doIt();

			$item['d'] = isset($e->viewsData['sums']['d']) ? $e->viewsData['sums']['d']['amountHc'] : 0.0;
			$item['c'] = isset($e->viewsData['sums']['c']) ? $e->viewsData['sums']['c']['amountHc'] : 0.0;
			$item['b'] = $item['c'] - $item['d'];

			if ($this->reportParams ['viewType']['value'] === 'errors' && !count($e->troubles))
				continue;
			if ($this->reportParams ['viewType']['value'] === 'loss' && $item['b'] >= -0.1)
				continue;

			if (count($e->troubles))
			{
				$item['t'] = ['text' => '', 'icon' => 'system/iconWarning', 'xxsuffix' => Utils::nf(count($e->troubles))];
			}

			$item['gm'] = ($item['c']) ? round ($item['b'] / $item['c'] * 100, 2) : 0.0;

			if ($item['b'] < 0.0)
				$item['_options']['class'] = 'e10-row-minus';

			unset($e);

			$this->data[] = $item;
		}
	}

	function addFiltersParams ()
	{
		$enum = [];

		$docKinds = $this->app->cfgItem('e10mnf.workOrders.kinds');
		foreach ($docKinds as $dkNdx => $dkDef)
		{
			if ($dkNdx)
				$enum ['D' . $dkNdx] = $dkDef['fullName'];
		}

		$q [] = 'SELECT * from [e10mnf_base_docnumbers] WHERE docState != 9800 ORDER BY [fullName]';
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$id = 'C'.$r['ndx'];
			$enum[$id] = $r['fullName'];
		}

		$enum['ALL'] = 'Vše';
		$this->addParam ('switch', 'filter', ['title' => 'Filtr', 'switch' => $enum]);
	}

	function addAdminsParam ()
	{
		$enum = [];
		$enum[0] = 'Všichni';

		$this->params->detectParamValue ('fiscalPeriod');
		$params = $this->params->getParams();
		$fp = $params ['fiscalPeriod']['value'];
		$db = isset ($params ['fiscalPeriod']['values'][$fp]['dateBegin']) ? $params ['fiscalPeriod']['values'][$fp]['dateBegin'] : '0';
		$year = intval (substr ($db, 0, 4));

		$q[] = 'SELECT persons.ndx as personNdx, persons.fullName as personName FROM [e10_base_statsCounters] AS stats';
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON stats.i1 = persons.ndx');
		array_push ($q, ' WHERE stats.id = %s', 'e10-mnf-workOrdersAdmins-yearly');
		if ($year)
			array_push ($q, ' AND stats.s1 = %s', $year);
		array_push ($q, ' ORDER BY persons.fullName');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if (!$r['personNdx'])
				continue;
			if (!isset($enum[$r['personNdx']]))
				$enum[$r['personNdx']] = $r['personName'];
		}

		$this->addParam ('switch', 'admin', ['title' => 'Garant', 'switch' => $enum]);
	}
}
