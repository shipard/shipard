<?php

namespace e10pro\reports\waste_cz\libs;
use e10doc\core\libs\E10Utils, \Shipard\Utils\Utils;


/**
 * Class ReportBadWasteReturn
 */
class ReportBadWasteReturn extends \e10doc\core\libs\reports\GlobalReport
{
	/** @var \e10doc\core\TableHeads */
	var $tableHeads;
	var $docTypes;

  var $periodBegin = NULL;
	var $periodEnd = NULL;


	function init ()
	{
    set_time_limit (0);
    ini_set('memory_limit', '1024M');

		$this->tableHeads = $this->app()->table('e10doc.core.heads');
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

    $this->addParam ('calendarMonth', 'calendarMonth', ['flags' => ['quarters', 'halfs', 'years']]);

    parent::init();

    $this->setInfo('icon', 'reportMonthlyReport');


    if (!$this->periodBegin)
			$this->periodBegin = Utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);

		if (!$this->periodEnd)
			$this->periodEnd = Utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);

    $this->setInfo('param', 'Období', $this->reportParams ['calendarMonth']['activeTitle'].' ('.Utils::datef($this->periodBegin, '%d').' až '.Utils::datef($this->periodEnd, '%d').')');
    $this->setInfo('title', 'Kontrola Hlášení o odpadech');

	}

  /*
	public function setTestCycle ($cycle, $testEngine)
	{
		parent::setTestCycle($cycle, $testEngine);

		$this->subReportId = 'ALL';

		switch ($cycle)
		{
			case 'thisMonth': $this->defaultFiscalPeriod = E10Utils::todayFiscalMonth($this->app()); break;
			case 'prevMonth': $this->defaultFiscalPeriod = E10Utils::prevFiscalMonth($this->app()); break;
		}
	}

	public function testTitle ()
	{
		$t = [];
		$t[] = [
				'text' => 'Byly nalezeny problémy v účtování dokladů '.$this->reportParams ['fiscalPeriod']['activeTitle'],
				'class' => 'subtitle e10-me h1 block mt1 bb1 lh16'
		];
		return $t;
	}
  */

	function createContent ()
	{
		$this->createContent_BadDocuments();
	}

	function createContent_BadDocuments ()
	{
    $q [] = 'SELECT heads.*, persons.fullName as personName ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.docState = %i', 4000);

    array_push ($q, ' AND heads.docType IN %in', ['purchase', 'invno']);
    array_push ($q, ' AND heads.dateAccounting >= %d', $this->periodBegin);
    array_push ($q, ' AND heads.dateAccounting <= %d', $this->periodEnd);

		array_push ($q, ' ORDER BY dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);
		$data = [];
    $wce = new \e10pro\reports\waste_cz\libs\WasteCheckEngine($this->app);

		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

      $wce->setDocument($r['ndx']);
      $wce->checkDocument();

      if ($wce->checkOk)
        continue;

      $newItem = [
        'dn' => ['text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx'], 'icon' => $docType ['icon']],
        'person' => $r['personName'], 'title' => $r['title'], 'date' => Utils::datef($r['dateAccounting'], '%d'), 'dt' => $docType ['shortcut']
      ];
			//$newItem['_options'] = ['cellClasses' => ['dn' => $this->docStateClass($r)]];
			$data[] = $newItem;


    }

		if (count($data))
		{
			$h = ['#' => '#', 'dn' => '_Doklad', 'dt' => 'DD', 'date' => 'Datum', 'person' => 'Osoba', 'title' => 'Popis'];
			$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

      /*
      if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Chybně zaúčtované doklady', 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
      */
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	function docStateClass($r)
	{
		$docStates = $this->tableHeads->documentStates($r);
		$docStateClass = $this->tableHeads->getDocumentStateInfo($docStates, $r, 'styleClass');
		return $docStateClass;
	}
}
