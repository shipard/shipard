<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use e10\GlobalReport, e10\utils;


/**
 * Class ReportInvoicesAccruedRevenue
 * @package e10pro\zus
 */
class ReportInvoicesDeferredRevenue extends GlobalReport
{
	var $list = [];
	var $tableDocs;

	var $academicYearDef = NULL;
	var $academicYearNumber = 0;

	function init()
	{
		set_time_limit(3000);
		$this->tableDocs = $this->app->table('e10doc.core.heads');

		// -- toolbar
		$this->addParam('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);
		$this->addParam('switch', 'viewType', ['title' => 'Zobrazit', 'switch' => ['school' => 'Školné', 'rental' => 'Půjčovné'], 'radioBtn' => 1, 'defaultValue' => 'school']);

		parent::init();

		$this->academicYearDef = $this->app->cfgItem ('e10pro.zus.roky.'.$this->reportParams ['skolniRok']['value'], NULL);
		if ($this->academicYearDef)
			$this->academicYearNumber = intval(substr($this->academicYearDef['zacatek'], 0, 4));

		$this->setInfo('icon', 'reportInvoices');

		if ($this->reportParams ['viewType']['value'] === 'school')
			$this->setInfo('title', 'Faktury za školné - výnosy příštích období');
		else
			$this->setInfo('title', 'Faktury za půjčovné - výnosy příštích období');
	}

	function createContent ()
	{
		parent::createContent();
		$this->loadList();

		$this->setInfo('param', 'Rok', $this->reportParams ['skolniRok']['activeTitle']);
		if ($this->reportParams ['ucitel']['value'])
			$this->setInfo('param', 'Učitel', $this->reportParams ['ucitel']['activeTitle']);

		$h = [
				'#' => '#', 'docNumber' => 'Faktura', 'person' => 'Student', 'studium' => ' Studium',
				'toPay' => '+Částka', 'dateFrom' => 'Od', 'dateTo' => 'do',
				'daysAll' => ' dnů', 'daysNext' => ' dnů \''.strval($this->academicYearNumber - 1999),
				'amountNext' => '+částka \''.strval($this->academicYearNumber - 1999)
		];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list, 'main' => TRUE]);
	}

	protected function loadList()
	{
		$symbol2Base = strval($this->academicYearNumber - 2000).strval($this->academicYearNumber - 1999);
		$symbol2 = $symbol2Base . (($this->reportParams ['viewType']['value'] === 'school') ? '1' : '3');

		$q[] = 'SELECT heads.*, persons.fullName as personFullName, studium.cisloStudia as cisloStudia, studium.ndx as studiumNdx,';
		array_push($q, ' studium.nazev as studiumNazev, studium.datumUkonceniSkoly, studium.datumNastupuDoSkoly');
		array_push($q, ' FROM [e10doc_core_heads] AS heads');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10pro_zus_studium] AS studium ON ',
				' heads.symbol1 = studium.cisloStudia AND studium.[skolniRok] = %i', $this->reportParams ['skolniRok']['value']
				/*' AND studium.stavHlavni = 1'*/);
		array_push($q, ' WHERE 1');
		array_push($q, ' AND heads.docType = %s', 'invno');
		if ($this->reportParams ['viewType']['value'] === 'school')
			array_push($q, ' AND heads.dbCounter = %i', 3);
		else
			array_push($q, ' AND heads.dbCounter = %i', 4);
		array_push($q, ' AND heads.docState = %i', 4000);
		array_push($q, ' AND heads.symbol2 = %s', $symbol2);

		array_push($q, ' ORDER BY persons.lastName, persons.firstName, heads.docNumber');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$dateFrom = NULL;
			$dateFrom = $r['datePeriodBegin'];
			$dateTo = $r['datePeriodEnd'];

			//if ($r['datumNastupuDoSkoly'])
			//	$dateFrom = $r['datumNastupuDoSkoly'];
			if ($r['datumUkonceniSkoly'] && !utils::dateIsBlank($r['datePeriodEnd']) && $r['datumUkonceniSkoly'] < $r['datePeriodEnd'])
				$dateTo = $r['datumUkonceniSkoly'];

			$this->pks[] = $r['ndx'];
			$item = [
					'docNumber' => ['text' => $r['docNumber'], 'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10doc.core.heads', 'title' => $r['title']],
					'person' => $r['personFullName'],
					'toPay' => $r['toPay'],
					'dateFrom' => $dateFrom, 'dateTo' => $dateTo
			];

			if ($dateFrom && $dateTo)
			{
				$intervalAll = $dateTo->diff($dateFrom);
				$daysAll = $intervalAll->days + 1;
				$item['daysAll'] = $daysAll;

				$dateBeginNextYear = new \DateTime(($this->academicYearNumber - 1999).'-01-01');
				if ($dateTo > $dateBeginNextYear)
				{
					$intervalNext = $dateBeginNextYear->diff($dateTo);
					$daysNext = $intervalNext->days + 1;
				}
				else
					$daysNext = 0;
				$item['daysNext'] = $daysNext;

				$amountNext = 0.0;
				if ($daysNext)
					$amountNext = round ($daysNext / $daysAll * $r['toPay'], 2);
				$item['amountNext'] = $amountNext;
			}


			if ($r['cisloStudia'])
				$item['studium'] = ['text' => strval($r['cisloStudia']), 'docAction' => 'edit', 'pk' => $r['studiumNdx'], 'table' => 'e10pro.zus.studium', 'title' => $r['studiumNazev']];
			else
				$item['_options']['class'] = 'e10-warning2';

			if ($r['toPay'] == 0.0)
				$item['_options']['class'] = 'e10-warning2';

			$this->list[] = $item;
		}
	}
}


