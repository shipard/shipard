<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


use \e10\utils;


/**
 * Class reportFees
 * @package e10pro\zus
 */
class reportFees extends \E10\GlobalReport
{
	var $dataStudies = [];
	var $summary = [];
	var $heads = [];
	var $persons = [];
	var $personsToDemand = [];
	var $outbox = [];
	var $demandsForPay = [];
	var $demandsForPayDates = [];

	var $periodBegin;
	var $periodEnd;
	var $periodHalf;

	var $minDate;

	var $fees;
	var $enumFees = ['1' => 'Školné 1. pololetí', '2' => 'Školné 2. pololetí', '3' => 'Půjčovné', '0' => 'Vše'];

	var $viewType;
	var $viewTypes = ['debts' => 'Nedoplatky', 'overpaid' => 'Přeplatky', 'all' => 'Všechny', 'errors' => 'Chybné'];

	function init()
	{
		if ($this->subReportId === '')
			$this->subReportId = 'overview';

		$this->minDate = utils::today();

		$activeHalfYear = in_array(utils::today('m'), [2, 3, 4, 5, 6, 7, 8]) ? 2 : 1;
		$this->addParam('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		if ($this->subReportId !== 'overview')
			$this->addParam('switch', 'fees', ['title' => 'Faktury', 'switch' => $this->enumFees, 'defaultValue' => $activeHalfYear]);

		if ($this->subReportId === 'all')
			$this->addParam('switch', 'viewType', ['title' => 'Zobrazit', 'switch' => $this->viewTypes, 'radioBtn' => 1, 'defaultValue' => 'debts']);

		if ($this->subReportId === 'teachers')
			$this->addParam('switch', 'viewType', ['title' => 'Zobrazit', 'switch' => ['all' => 'Vše', 'debts' => 'Neuhrazeno', 'invoices' => 'Dlužníci'], 'radioBtn' => 1, 'defaultValue' => 'all']);

		parent::init();

		$this->setInfo('icon', 'system/iconFile');
		$this->setInfo('title', 'Přehled školného');

		if ($this->subReportId !== 'overview')
			$this->fees = $this->reportParams ['fees']['value'];
		else
			$this->fees = '0';

		$this->viewType = $this->reportParams ['viewType']['value'];
		$this->setPeriod();
	}

	function setPeriod ()
	{
		$academicYearCfg = $this->app->cfgItem ('e10pro.zus.roky.'.$this->reportParams ['skolniRok']['value']);

		$today = utils::createDateTime($academicYearCfg['zacatek']);
		$todayYear = intval($today->format ('Y'));

		switch ($this->fees)
		{
			case '1':
				$beginDateStr = sprintf ("%04d-09-01", $todayYear);
				$endDateStr = sprintf ("%04d-01-31", $todayYear+1);
				break;
			case '2':
				$beginDateStr = sprintf ("%04d-02-01", $todayYear+1);
				$endDateStr = sprintf ("%04d-06-30", $todayYear+1);
				break;
			default:
				$beginDateStr = sprintf ("%04d-09-01", $todayYear);
				$endDateStr = sprintf ("%04d-06-30", $todayYear+1);
				break;
		}
		$halfDateStr = sprintf ("%04d-01-31", $todayYear+1);

		$this->periodBegin = new \DateTime ($beginDateStr);
		$this->periodEnd = new \DateTime ($endDateStr);
		$this->periodHalf = new \DateTime ($halfDateStr);
	}

	function createContent()
	{
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		if ($this->subReportId !== 'overview')
			$this->setInfo('param', $this->reportParams ['fees']['title'], $this->reportParams ['fees']['activeTitle'].' ('.utils::datef($this->periodBegin).' - '.utils::datef($this->periodEnd).')');

		switch ($this->subReportId)
		{
			case '':
			case 'overview': $this->createContent_Overview(); break;
			case 'all': $this->createContent_All(); break;
			case 'teachers': $this->createContent_Teachers(); break;
			case 'old': $this->createContent_Old(); break;
		}
	}

	function createContent_Overview()
	{
		$this->loadData();

		if (0)
		{
			$tableSummary = [];
			$tableSummary[] = [
				'txt' => 'Celková částka',
				'HY1' => $this->summary['HY1']['amount'],
				'HY2' => $this->summary['HY2']['amount'],
				'HY3' => $this->summary['HY3']['amount'],
				'total' => $this->summary['ALL']['amount']
			];
			$tableSummary[] = [
				'txt' => 'Vyfakturováno',
				'HY1' => $this->summary['HY1']['request'],
				'HY2' => $this->summary['HY2']['request'],
				'HY3' => $this->summary['HY3']['request'],
				'total' => $this->summary['ALL']['invAmountTotal']
			];
			$tableSummary[] = [
				'txt' => 'Uhrazeno',
				'HY1' => $this->summary['HY1']['payment'],
				'HY2' => $this->summary['HY2']['payment'],
				'HY3' => $this->summary['HY3']['payment'],
				'total' => $this->summary['ALL']['amountPayed']
			];
			$tableSummary[] = [
				'txt' => 'Přeplatky',
				'HY1' => $this->summary['HY1']['amountOverpaid'],
				'HY2' => $this->summary['HY2']['amountOverpaid'],
				'HY3' => $this->summary['HY3']['amountOverpaid'],
				'total' => $this->summary['ALL']['amountOverpaid']
			];
			$tableSummary[] = [
				'txt' => 'Nedoplatky',
				'HY1' => $this->summary['HY1']['amountUnpaid'],
				'HY2' => $this->summary['HY2']['amountUnpaid'],
				'HY3' => $this->summary['HY3']['amountUnpaid'],
				'total' => $this->summary['ALL']['amountRest']
			];

			$headerSummary = [
				'txt' => '',
				'HY1' => ' 1. pol.',
				'HY2' => ' 2. pol.',
				'HY3' => ' Půjčovné',
				'total' => ' Celkem'
			];

			$this->addContent([
				'title' => 'Přehled',
				'type' => 'table', 'header' => $headerSummary, 'table' => $tableSummary,
				'params' => ['XXhideHeader' => 1]]);
		}
		else
		{
			$hys = ['HY1' => '1. pol.', 'HY2' => '2. pol.', 'HY3' => 'Půjčovné'];
			$tableSummary = [];
			foreach ($hys as $hyId => $hyTitle)
			{
				$hyRes = $this->summary[$hyId];

				$item = [
					'subject' => $hyTitle,
					'amount' => $hyRes['amount'],
					'amountDiff' => $hyRes['request'] - $hyRes['amount'],
					'request' => $hyRes['request'],
					'payment' => $hyRes['payment'],
					'overpaid' => $hyRes['amountOverpaid'],
					'unpaid' => $hyRes['amountUnpaid'],
				];

				if ($hyRes['amount'] != $hyRes['request'])
				{
					$item['_options']['cellClasses']['amount'] = 'e10-warning1';
					$item['_options']['cellClasses']['request'] = 'e10-warning1';
				}

				$tableSummary[] = $item;
			}

			$tableSummary[] = [
				'subject' => 'CELKEM',
				'amount' => $this->summary['ALL']['amount'],
				'request' => $this->summary['ALL']['invAmountTotal'],
				'amountDiff' => $this->summary['ALL']['invAmountTotal'] - $this->summary['ALL']['amount'],
				'payment' => $this->summary['ALL']['amountPayed'],
				'overpaid' => $this->summary['ALL']['amountOverpaid'],
				'unpaid' => $this->summary['ALL']['amountRest'],
				'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']
			];

			$headerSummary = [
				'subject' => 'Období',
				'amount' => ' K fakturaci',
				'request' => ' Vyfakturováno',
				'amountDiff' => ' Rozdíl',
				'payment' => ' Uhrazeno',
				'overpaid' => ' Přeplatky',
				'unpaid' => ' Nedoplatky'
			];

			$this->addContent([
				'XXtitle' => 'Přehled',
				'type' => 'table', 'header' => $headerSummary, 'table' => $tableSummary,
				'params' => ['XXhideHeader' => 1]]);

		}

		$amountDiff = abs($this->summary['ALL']['amount'] - $this->summary['ALL']['invAmountTotal']);

		if ($amountDiff > 1)
			$this->setInfo('note', '1', 'Celková částka školného nesedí na celkovou částku faktur (o '.utils::nf ($amountDiff).',-) Zkontrolujte chybně vystavené faktury.');

		if (count($this->personsToDemand))
			$this->setInfo('note', '2', 'Upomínek k rozeslání: '.count($this->personsToDemand));
	}

	function createContent_Teachers()
	{
		$this->loadData();

		if ($this->viewType === 'invoices')
		{
			$this->createContent_Teachers_Invoices();
			return;
		}

		$tableTeachers = [];
		foreach ($this->summary['TCH'] as $r)
		{
			if ($this->viewType === 'debts' && $r['amountRest'] <= 0.0)
				continue;

			$tableTeachers[] = $r;
		}
		$headerTeachers = [
			'#' => '#', 'name' => 'Učitel', /*'amount' => '+Částka',*/ 'invAmountTotal' => '+Fakturace',
			'amountPayed' => '+Uhrazeno', 'amountUnpaid' => '+Nedopatek', 'amountOverpaid' => '+Přeplatek',
			];

		switch ($this->viewType)
		{
			case 'debts': unset ($headerTeachers['amountOverpaid']); break;
			//case 'overpaid': unset ($headerTeachers['amountUnpaid']); break;
		}

		$this->addContent (['type' => 'table', 'header' => $headerTeachers, 'table' => $tableTeachers]);

		$this->setInfo('icon', 'system/iconFile');
		$this->setInfo('title', 'Přehled školného podle učitelů');
		$this->setInfo('param', $this->reportParams ['viewType']['title'], $this->reportParams ['viewType']['activeTitle']);
	}

	function createContent_Teachers_Invoices()
	{
		foreach ($this->summary['TCH'] as $teacherNdx => $teacher)
		{
			if ($teacher['amountRest'] <= 0.0)
				continue;

			$t = [];
			foreach ($this->dataStudies as $r)
			{
				if ($r['teacherNdx'] !== $teacherNdx)
					continue;
				if ($r['amountRest'] <= 0.0)
					continue;


				$item = [
						'student' => $r['studentName'],
						'studium' => ['text' => strval($r['id']), 'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10pro.zus.studium', /*'title' => $r['studiumTitle']*/],
						'amount' => $r['amount'], 'amountOverpaid' => $r['amountOverpaid'], 'amountUnpaid' => $r['amountUnpaid'],
						'invAmountTotal' => $r['invAmountTotal'], 'amountPayed' => $r['amountPayed'], 'amountRest' => $r['amountRest'],
				];
				$t[] = $item;
			}

			if (!count($t))
				continue;

			$h = [
					'#' => '#',
					'student' => '_Student',
					'studium' => 'Studium',
					'amount' => '+Částka', 'amountPayed' => '+Uhrazeno',
					'amountUnpaid' => '+Nedoplatek',
			];

			$this->addContent ([
					'type' => 'table', 'header' => $h, 'table' => $t,
					'title' => ['text' => $teacher['name'], 'class' => 'h2'],
					'params' => ['tableClass' => 'pageBreakAfter']
			]);
		}
	}

	function createContent_All()
	{
		$this->setInfo('param', $this->reportParams ['viewType']['title'], $this->reportParams ['viewType']['activeTitle']);
		$this->paperOrientation = 'landscape';

		$this->loadData();

		$t = [];

		foreach ($this->dataStudies as $r)
		{
			$item = [
				'student' => $r['studentName'],
				'studium' => ['text' => strval($r['id']), 'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10pro.zus.studium', /*'title' => $r['studiumTitle']*/],
				'amount' => $r['amount'], 'amountUnpaid' => $r['amountUnpaid'], 'amountOverpaid' => $r['amountOverpaid'],
				'invAmountTotal' => $r['invAmountTotal'], 'amountPayed' => $r['amountPayed'], 'amountRest' => $r['amountRest'],
			];

			if ($this->viewType === 'debts' && $r['amountRest'] <= 0.0)
				continue;
			if ($this->viewType === 'errors' && $r['amount'] == $r['invAmountTotal'])
				continue;
			if ($this->viewType === 'overpaid' && $r['amountRest'] >= 0.0)
				continue;

			if ($this->viewType !== 'errors' && $r['amount'] != $r['invAmountTotal'])
			{
				$item['_options']['cellClasses']['amount'] = 'e10-warning1';
				$item['_options']['cellClasses']['invAmountTotal'] = 'e10-warning1';
			}

			if (in_array($r['studentNdx'], $this->personsToDemand))
			{
				$item['_options']['cellClasses']['amountUnpaid'] = 'e10-warning1';
			}

			$invoices = [];
			foreach ($r['invoices'] as $inv)
			{
				if (count($invoices))
					$invoices[] = ['text' => '', 'class' => 'block'];

				$invoices[] = $inv;
				if (isset($this->outbox[$inv['ndx']]))
				{
					$invoices = array_merge($invoices, $this->outbox[$inv['ndx']]);
				}
			}

			if (isset($this->demandsForPay[$r['studentNdx']]))
			{
				foreach ($this->demandsForPay[$r['studentNdx']] as $dfp)
				{
					$invoices[] = $dfp;
				}
			}

			// -- request for payment button
			if (!$this->app()->printMode && $r['amountRest'] > 0.0)
			{
				$btn = [
						'type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => '', 'title' => 'Upomínka',
						'data-report' => 'e10doc.balance.RequestForPayment',
						'data-table' => 'e10.persons.persons', 'data-pk' => $r['studentNdx'], 'actionClass' => 'btn-xs btn-primary', 'class' => 'pull-right'];
				$btn['subButtons'] = [];
				$btn['subButtons'][] = [
						'type' => 'action', 'action' => 'addwizard', 'icon' => 'system/iconEmail', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-primary btn-xs',
						'data-table' => 'e10.persons.persons', 'data-pk' => $r['studentNdx'], 'data-class' => 'e10.SendFormReportWizard',
						'data-addparams' => 'reportClass=' . 'e10doc.balance.RequestForPayment' . '&documentTable=' . 'e10.persons.persons'
				];
				$invoices[] = $btn;
			}

			if (count($invoices))
				$item['invoices'] = $invoices;

			$t[] = $item;
		}

		$h = [
				'#' => '#',
				'student' => '_Student',
				'studium' => 'Studium',
				'amount' => '+Částka', 'invAmountTotal' => '+Fakturace',
				'amountPayed' => '+Uhrazeno',
				'amountUnpaid' => '+Nedoplatek',
				'amountOverpaid' => '+Přeplatek',
				'invoices' => 'Faktury'];

		switch ($this->viewType)
		{
			case 'debts': unset ($h['amountOverpaid']); break;
			case 'overpaid': unset ($h['amountUnpaid']); break;
		}

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE]);
	}

	function loadData()
	{
		$this->loadStudies();
		$this->loadOutbox ();
		$this->loadDemandsForPay();
	}

	function loadStudies ()
	{
		$this->summary ['ALL'] = [
			'amount' => 0.0, 'invAmountTotal' => 0.0, 'amountPayed' => 0.0,
			'amountRest' => 0.0,
			'amountOverpaid' => 0.0, 'amountUnpaid' => 0.0
		];

		$balanceSymbol = ($this->fees === '0') ? '' : $this->fees;

		$q [] = 'SELECT studium.ndx, studium.cisloStudia, studium.student, studium.ucitel, ';
		array_push ($q, ' studium.skolVyPrvniPol, studium.skolVyDruhePol, studium.pujcovne,');
		array_push ($q, ' studium.datumNastupuDoSkoly, studium.datumUkonceniSkoly,');
		array_push ($q, ' students.fullName as studentName, teachers.fullName as teacherName');
		array_push ($q, ' FROM [e10pro_zus_studium] as studium');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS students ON studium.student = students.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS teachers ON studium.ucitel = teachers.ndx');
		array_push ($q, ' WHERE studium.[skolniRok] = %s', $this->reportParams ['skolniRok']['value']);

		array_push ($q, ' AND (studium.datumNastupuDoSkoly IS NULL OR studium.datumNastupuDoSkoly < %d)', $this->periodEnd);
		array_push ($q, ' AND (studium.datumUkonceniSkoly IS NULL OR studium.datumUkonceniSkoly > %d)', $this->periodBegin);

		array_push ($q, ' AND studium.stavHlavni != 4');

		if ($this->subReportId === 'teachers')
			array_push ($q, ' ORDER BY teachers.lastName, studium.ndx');
		else
			array_push ($q, ' ORDER BY students.fullName, studium.cisloStudia');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($this->fees === '3' && $r['pujcovne'] == 0.0)
				continue;

			$balance = zusutils::saldoSkolneho($this->app, $r['student'], $this->reportParams ['skolniRok']['value'], $r['cisloStudia'], $balanceSymbol, 3);
			$this->heads = array_merge ($this->heads, $balance['heads']);
			if ($balance['minDate'] < $this->minDate)
				$this->minDate = $balance['minDate'];

			if (!in_array($r['student'], $this->persons))
				$this->persons[] = $r['student'];

			$item = [
					'ndx' => $r['ndx'], 'id' => $r['cisloStudia'], 'studentNdx' => $r['student'], 'studentName' => $r['studentName'],
					'teacherNdx' => $r['ucitel'], 'amount' => 0.0,
					'invAmountTotal' => $balance['celkKUhrade'], 'amountPayed' => $balance['celkUhrazeno'],
					'amountRest' => $balance['celkKUhrade'] - $balance['celkUhrazeno'],
					'amountOverpaid' => 0.0, 'amountUnpaid' => 0.0,
					'invoices' => $balance['docPredpisy']
			];

			switch ($this->fees)
			{
				case '1':
					if (utils::dateIsBlank($r['datumNastupuDoSkoly']) || $r['datumNastupuDoSkoly'] < $this->periodHalf)
						$item['amount'] = $r['skolVyPrvniPol'];
					break;
				case '2':
					if (utils::dateIsBlank($r['datumNastupuDoSkoly']) || $r['datumNastupuDoSkoly'] < $this->periodEnd)
						$item['amount'] = $r['skolVyDruhePol'];
					break;
				case '3': $item['amount'] = $r['pujcovne']; break;
				case '0':
					{
						$item['amount'] = 0;
						if (utils::dateIsBlank($r['datumNastupuDoSkoly']) || $r['datumNastupuDoSkoly'] < $this->periodHalf)
							$item['amount'] += $r['skolVyPrvniPol'];
						if (utils::dateIsBlank($r['datumNastupuDoSkoly']) || $r['datumNastupuDoSkoly'] < $this->periodEnd)
							$item['amount'] += $r['skolVyDruhePol'];
						$item['amount'] += $r['pujcovne'];
					}
					break;
			}

			if ($item['amountRest'] >= 0.0)
			{
				$item['amountUnpaid'] = $item['amountRest'];
				$this->summary['ALL']['amountPayed'] += $item['amountPayed'];
				$this->summary['ALL']['amountRest'] += $item['amountRest'];
			}
			else
			{
				$item['amountOverpaid'] = -$item['amountRest'];
				$this->summary['ALL']['amountPayed'] += $balance['celkKUhrade'];
				$this->summary['ALL']['amountOverpaid'] += - $item['amountRest'];
			}

			$this->dataStudies[$r['ndx']] = $item;

			$this->summary['ALL']['amount'] += $item['amount'];
			$this->summary['ALL']['invAmountTotal'] += $item['invAmountTotal'];

			$teacherNdx = $r['ucitel'];
			if (!isset($this->summary['TCH'][$teacherNdx]))
				$this->summary['TCH'][$teacherNdx] = [
					'name' => $r['teacherName'], 'amount' => 0.0, 'invAmountTotal' => 0.0, 'amountPayed' => 0.0, 'amountRest' => 0.0,
					'amountUnpaid' => 0.0, 'amountOverpaid' => 0.0,
					];
			$this->summary['TCH'][$teacherNdx]['amount'] += $item['amount'];
			$this->summary['TCH'][$teacherNdx]['invAmountTotal'] += $item['invAmountTotal'];

			if ($item['amountRest'] >= 0.0)
			{
				$this->summary['TCH'][$teacherNdx]['amountUnpaid'] += $item['amountRest'];
				$this->summary['TCH'][$teacherNdx]['amountPayed'] += $item['amountPayed'];
				$this->summary['TCH'][$teacherNdx]['amountRest'] += $item['amountRest'];
			}
			else
			{
				$this->summary['TCH'][$teacherNdx]['amountPayed'] += $balance['celkKUhrade'];
				$this->summary['TCH'][$teacherNdx]['amountOverpaid'] += - $item['amountRest'];
			}

			foreach ($balance['totals'] as $hyId => $hyBal)
			{
				if (!isset ($this->summary[$hyId]))
					$this->summary[$hyId] = [
						'amount' => 0.0, 'request' => 0.0, 'payment' => 0.0, 'amountUnpaid' => 0.0, 'amountOverpaid' => 0.0, 'amountRest' => 0.0
					];

				$hyNumber = substr ($hyId, -1, 1);
				switch ($hyNumber)
				{
					case '1':
						if (utils::dateIsBlank($r['datumNastupuDoSkoly']) || $r['datumNastupuDoSkoly'] < $this->periodHalf)
							$this->summary[$hyId]['amount'] += $r['skolVyPrvniPol'];
						break;
					case '2':
						if ((utils::dateIsBlank($r['datumNastupuDoSkoly']) || $r['datumNastupuDoSkoly'] < $this->periodEnd) &&
							(utils::dateIsBlank($r['datumUkonceniSkoly']) || $r['datumUkonceniSkoly'] > $this->periodHalf))
							$this->summary[$hyId]['amount'] += $r['skolVyDruhePol'];
						break;
					case '3':
						$this->summary[$hyId]['amount'] += $r['pujcovne'];
						break;
				}

				$this->summary[$hyId]['request'] += $hyBal['request'];
				$this->summary[$hyId]['payment'] += $hyBal['payment'];

				if ($hyBal['payment'] <= $hyBal['request'])
				{
					$this->summary[$hyId]['amountUnpaid'] += $hyBal['request'] - $hyBal['payment'];
					$this->summary[$hyId]['amountRest'] += $hyBal['request'] - $hyBal['payment'];
					//$this->summary[$hyId]['payment'] += $hyBal['payment'];
				}
				else
				{
					$this->summary[$hyId]['amountOverpaid'] += $hyBal['payment'] - $hyBal['request'];
					//$this->summary[$hyId]['payment'] += $hyBal['request'];
				}
			}
		}
	}

	function loadOutbox ()
	{
		if (!count($this->heads))
			return;

		$q[] = 'SELECT * FROM [wkf_core_issues] ';
		array_push ($q, ' WHERE tableNdx = %i', 1078);
		array_push ($q, ' AND recNdx IN %in', $this->heads);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$docNdx = $r['recNdx'];

			if (!isset($this->outbox[$docNdx]))
				$this->outbox[$docNdx] = [];

			$item = [
					'icon' => 'user/paperPlane', 'text' => utils::datef($r['dateCreate']), 'class' => 'label label-default',
					'docAction' => 'edit', 'table' => 'wkf.core.issues', 'pk' => $r['ndx']
			];
			$this->outbox[$docNdx][] = $item;
		}
	}

	function loadDemandsForPay ()
	{
		/** @var \wkf\core\TableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');
		$demandForPaySectionNdx = $tableIssues->defaultSection (121);

		$q[] = 'SELECT * FROM [wkf_core_issues] ';
		array_push($q, ' WHERE tableNdx = %i', 1000);
		array_push($q, ' AND section = %i', $demandForPaySectionNdx);
		array_push($q, ' AND dateCreate > %d', $this->minDate);
		array_push($q, ' AND recNdx IN %in', $this->persons);
		array_push($q, ' ORDER BY [dateCreate]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$docNdx = $r['recNdx'];

			if (!isset($this->demandsForPay[$docNdx]))
				$this->demandsForPay[$docNdx] = [];

			$item = [
					'icon' => 'balanceAlerts', 'text' => utils::datef($r['dateCreate']), 'class' => 'label label-info',
					'docAction' => 'edit', 'table' => 'wkf.core.issues', 'pk' => $r['ndx']
			];
			$this->demandsForPay[$docNdx][] = $item;
			$this->demandsForPayDates[$docNdx] = $r['dateCreate'];
		}

		// -- prepare persons to demand
		$dateLimit = new \DateTime('1 week ago');
		foreach ($this->dataStudies as $item)
		{
			$studentNdx = $item['studentNdx'];
			if ($item['amountRest'] > 10.0 && !in_array($studentNdx, $this->personsToDemand)
				/*&& isset($this->demandsForPayDates[$studentNdx]) && $this->demandsForPayDates[$studentNdx] < $dateLimit*/)
			{
				$this->personsToDemand[] = $studentNdx;
			}
		}
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'overview', 'icon' => 'system/detailDetail', 'title' => 'Přehled'];
		$d[] = ['id' => 'all', 'icon' => 'detailReportAnalytic', 'title' => 'Seznam'];
		$d[] = ['id' => 'teachers', 'icon' => 'iconTeachers', 'title' => 'Učitelé'];

		return $d;
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();
		$buttons[] = [
				'text' => 'Rozeslat upomínky', 'icon' => 'system/iconEmail',
				'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10pro.zus.RequestForPaymentWizard',
				'data-table' => 'e10.persons.persons', 'data-pk' => '0',
				'class' => 'btn-primary'
		];
		return $buttons;
	}
}
