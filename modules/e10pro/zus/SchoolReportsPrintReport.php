<?php

namespace e10pro\zus;

use e10\GlobalReport, e10\utils;


/**
 * Class SchoolReportsPrintReport
 * @package e10pro\zus
 */
class SchoolReportsPrintReport extends GlobalReport
{
	var $list = [];
	var $tableVysvedceni;

	function init ()
	{
		set_time_limit (3000);
		$this->tableVysvedceni = $this->app->table('e10pro.zus.vysvedceni');

		// -- toolbar
		$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);
		$this->addParam ('switch', 'ucitel', ['title' => 'Učitel', 'switch' => zusutils::ucitele($this->app, TRUE)]);
		$this->addParam ('switch', 'tisk', ['title' => 'Tisknout', 'switch' => ['opis' => 'Výpis', 'vysvedceni' => 'Vysvědčení']]);

		parent::init();

		$this->setInfo('icon', 'reportPrintCertificates');
		$this->setInfo('title', 'Hromadný tisk vysvědčení');
	}

	function createContent ()
	{
		parent::createContent();
		$this->loadList();

		$this->setInfo('param', 'Rok', $this->reportParams ['skolniRok']['activeTitle']);
		if ($this->reportParams ['ucitel']['value'])
			$this->setInfo('param', 'Učitel', $this->reportParams ['ucitel']['activeTitle']);

		$h = ['#' => '#', 'name' => 'Jméno'];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list]);
	}

	protected function loadList()
	{
		$q[] = 'SELECT vysvedceni.*';
		array_push($q, ' FROM e10pro_zus_vysvedceni AS vysvedceni');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND vysvedceni.[skolniRok] = %i', $this->reportParams ['skolniRok']['value']);

		if ($this->reportParams ['ucitel']['value'])
			array_push($q, ' AND vysvedceni.[ucitel] = %i', $this->reportParams ['ucitel']['value']);

		array_push($q, ' AND vysvedceni.[stav] IN %in', [1200, 4000]);


		array_push($q, ' ORDER BY vysvedceni.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->pks[] = $r['ndx'];
			$item = [
					'ndx' => $r['ndx'], 'name' => $r['jmeno'],
			];

			$this->list[$r['ndx']] = $item;
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
		$fn = 'tisk-'.$this->reportParams ['tisk']['value'];
		if ($this->reportParams ['ucitel']['value'])
			$fn .= '-'.utils::safeChars($this->reportParams ['ucitel']['activeTitle'], TRUE);
		$fn .= '.pdf';
		return $fn;
	}

	public function saveReportAs ()
	{
		$this->loadList();

		$engine = new \lib\core\SaveDocumentAsPdf ($this->app);
		$engine->attachmentsPdfOnly = TRUE;

		foreach ($this->list as $ndx => $row)
		{
			$recData = $this->tableVysvedceni->loadItem ($ndx);

			if ($this->reportParams ['tisk']['value'] === 'opis')
				$engine->addDocument($this->tableVysvedceni, $ndx, $recData, 'e10pro.zus.VysvedceniReportOpis');
			else
				$engine->addDocument($this->tableVysvedceni, $ndx, $recData, 'e10pro.zus.VysvedceniBReportTisk');
		}

		$engine->run();

		$this->fullFileName = $engine->fullFileName;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
	}
}

