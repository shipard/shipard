<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use e10\GlobalReport, e10\utils, e10\Utility, e10\uiutils, e10pro\zus\zusutils;


/**
 * Class ReportInvoices
 * @package e10pro\zus
 */
class ReportInvoices extends GlobalReport
{
	var $list = [];
	var $tableDocs;

	var $academicYearDef = NULL;
	var $academicYearNumber = 0;
	var $halfYear = 0;

	function init()
	{
		set_time_limit(3000);
		$this->tableDocs = $this->app->table('e10doc.core.heads');
		$activeHalfYear = in_array(utils::today('m'), [2, 3, 4, 5, 6, 7, 8]) ? 2 : 1;

		// -- toolbar
		$this->addParam('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);
		$this->addParam('switch', 'ucitel', ['title' => 'Učitel', 'switch' => zusutils::ucitele($this->app, TRUE)]);
		$this->addParam('switch', 'pololeti', ['title' => 'Faktury', 'switch' => ['1' => 'Školné 1. pololetí', '2' => 'Školné 2. pololetí', '3' => 'Půjčovné'], 'defaultValue' => $activeHalfYear]);

		parent::init();

		$this->academicYearDef = $this->app->cfgItem ('e10pro.zus.roky.'.$this->reportParams ['skolniRok']['value'], NULL);
		if ($this->academicYearDef)
			$this->academicYearNumber = intval(substr($this->academicYearDef['zacatek'], 0, 4));

		$this->halfYear = $this->reportParams ['pololeti']['value'];

		$this->setInfo('icon', 'reportInvoices');
		$this->setInfo('title', 'Faktury '.$this->reportParams ['pololeti']['activeTitle']);
	}

	function createContent ()
	{
		parent::createContent();
		$this->loadList();

		$this->setInfo('param', 'Rok', $this->reportParams ['skolniRok']['activeTitle']);
		if ($this->reportParams ['ucitel']['value'])
			$this->setInfo('param', 'Učitel', $this->reportParams ['ucitel']['activeTitle']);

		$h = ['#' => '#', 'person' => 'Student', 'docNumber' => 'Faktura', 'studium' => ' Studium', 'toPay' => ' Částka'];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list]);
	}

	protected function loadList()
	{
		$symbol2Base = strval($this->academicYearNumber - 2000).strval($this->academicYearNumber - 1999);
		$symbol2 = $symbol2Base. $this->halfYear;

		$q[] = 'SELECT heads.*, persons.fullName as personFullName, studium.cisloStudia as cisloStudia, studium.ndx as studiumNdx,';
		array_push($q, ' studium.nazev as studiumNazev');
		array_push($q, ' FROM [e10doc_core_heads] AS heads');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10pro_zus_studium] AS studium ON ',
				' heads.symbol1 = studium.cisloStudia AND studium.[skolniRok] = %i', $this->reportParams ['skolniRok']['value'],
				' AND studium.stavHlavni = 1');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND heads.docType = %s', 'invno');
		if ($this->halfYear == 3)
			array_push($q, ' AND heads.dbCounter = %i', 4);
		else
			array_push($q, ' AND heads.dbCounter = %i', 3);
		array_push($q, ' AND heads.docState = %i', 4000);
		array_push($q, ' AND heads.symbol2 = %s', $symbol2);

		if ($this->reportParams ['ucitel']['value'])
			array_push($q, ' AND studium.[ucitel] = %i', $this->reportParams ['ucitel']['value']);

		array_push($q, ' ORDER BY persons.lastName, persons.firstName, heads.docNumber');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$this->pks[] = $r['ndx'];
			$item = [
					'docNumber' => ['text' => $r['docNumber'], 'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10doc.core.heads', 'title' => $r['title']],
					'person' => $r['personFullName'],
					'toPay' => $r['toPay']
			];

			if ($r['cisloStudia'])
				$item['studium'] = ['text' => strval($r['cisloStudia']), 'docAction' => 'edit', 'pk' => $r['studiumNdx'], 'table' => 'e10pro.zus.studium', 'title' => $r['studiumNazev']];
			else
				$item['_options']['class'] = 'e10-warning2';

			if ($r['toPay'] == 0.0)
				$item['_options']['class'] = 'e10-warning2';

			$this->list[] = $item;
		}
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Uložit jako PDF soubor', 'icon' => 'system/actionSave',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xpdf',
				'data-filename' => $this->saveAsFileName('xpdf')
		];
	}

	public function saveAsFileName ($type)
	{
		$fn = 'faktury-'.$this->reportParams ['pololeti']['activeTitle'];
		if ($this->reportParams ['ucitel']['value'])
			$fn .= '-'.utils::safeChars($this->reportParams ['ucitel']['activeTitle'], TRUE);
		$fn .= '.pdf';
		return $fn;
	}

	public function saveReportAs ()
	{
		$engine = new \lib\core\SaveDocumentAsPdf ($this->app);
		$engine->attachmentsPdfOnly = TRUE;

		foreach ($this->list as $row)
		{
			$ndx = $row['docNumber']['pk'];
			$recData = $this->tableDocs->loadItem ($ndx);

			$engine->addDocument($this->tableDocs, $ndx, $recData, 'e10doc.invoicesout.InvoiceReport');
		}

		$engine->run();

		$this->fullFileName = $engine->fullFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
	}
}
