<?php

namespace e10doc\taxes\VatOSS;

use e10\utils, e10\uiutils, e10\json, e10doc\core\e10utils;

class VatOSSReport extends \e10doc\taxes\TaxReportReport
{
	var $oldVatReturn = NULL;
	var $disableOldVatReturn = FALSE;

	function init()
	{
		$this->taxReportTypeId = 'eu-vat-oss';
		$this->previewReportTemplate = 'reports.default.e10doc.taxes.tax-eu-vat-tr/cz';
		$this->filingTypeEnum = ['B' => 'Řádné', 'O' => 'Opravné', 'D' => 'Dodatečné'];
		
		parent::init();
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'sum', 'icontxt' => '∑', 'title' => 'Sumárně'];
    /*
		$d[] = ['id' => 'preview', 'icon' => 'detailReportTranscript', 'title' => 'Opis'];
		$d[] = ['id' => 'errors', 'icon' => 'detailReportProblems', 'title' => 'Problémy'];
		$d[] = ['id' => 'filings', 'icon' => 'detailReportDifferences', 'title' => 'Rozdíly'];
    */
		return $d;
	}



	function createContent ()
	{
		if (!$this->taxReportDef)
			return;

		$this->loadData();

    /*
		if ($this->cntErrors && $this->subReportId != 'errors' && $this->format !== 'pdf')
		{
			$msg = ['text' => 'Přiznání DPH patrně obsahuje chyby. Zkontrolujte prosím pravou záložku Problémy.', 'class' => 'padd5 e10-warning2 h2 block center'];
			$this->addContent(['type' => 'line', 'line' => $msg]);
		}
    */

		switch ($this->subReportId)
		{
			case '':
			case 'sum': $this->createContent_Sum (); break;
//			case 'out': $this->createContent_Dir (1); break;
//			case 'preview': $this->createContent_Preview (); break;
//			case 'errors': $this->createContent_Errors (); break;
//			case 'filings': $this->createContent_Filings(); break;
			case 'ALL': $this->createContent_All (); break;
		}
		//$this->setInfo('saveFileName', $this->taxReportRecData['title']);

		//$this->createContentXml();
	}

	public function createContent_All ()
	{
		$this->createContent_Sum();
    /*
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='pageBreakAfter'></div>"]);

		$this->createContent_Dir (1);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='pageBreakAfter'></div>"]);

		$this->createContent_Dir (0);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='pageBreakAfter'></div>"]);

		$this->createContent_ReverseCharge();
    */
	}

	function createContent_Sum ()
	{
		$table = [];

		$sum = [];
		$sum['total'] = [
				'cc' => ['text' => 'CELKEM'],
				'base' => 0.0, 'tax' => 0.0, 'total' => 0.0,
				'_options' => ['class' => 'sumtotal']
		];

    foreach ($this->data['SUM'] as $r)
    {
      $itm = [
        'cc' => $this->taxRegCountries[$r['countryConsumption']]['fn'],
        'taxPercents' => $r['taxPercents'],
				'docCurrency' => strtoupper($r['docCurrency']),
        'baseDC' => $r['sumBaseDC'],
        'taxDC' => $r['sumTaxDC'],
      ];

      $sum['total']['baseTC'] += $r['sumBaseTC'];
      $sum['total']['taxTC'] += $r['sumTaxTC'];

      $table[] = $itm;
    }

		$table[] = $sum['total'];
		$title = [['text' => 'Sumární přehled', 'class' => 'h2']];
		$h = [
			'#' => '#', 'cc' => 'Země', 
			'docCurrency' => 'Měna',
			'taxPercents' => ' %', 
			'baseDC' => ' Základ', 'taxDC' => ' Daň',
			'baseTC' => ' Základ EUR', 'taxTC' => ' Daň EUR',
		];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'params' => ['_hideHeader' => 1]]);
	}

	public function createContent_Preview ()
	{
    /*
		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div style='text-align: center;'>"]);

		$this->data['currentPageNumber'] = 1;
		$this->data['cntPagesTotal'] = 2;

		$c = $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-eu-vat-tr/cz', 'header');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		$c = $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-eu-vat-tr/cz', 'content');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
     */ 
	}

	public function createContent_Filings ()
	{
    /*
		$prevFilingNdx = -1;
		$prevFilingName = '';
		foreach ($this->enumFilings as $filingNdx => $filingName)
		{
			if ($prevFilingNdx != -1)
			{
				$this->addContent(['type' => 'line', 'line' => ['text' => $prevFilingName.' -> '.$filingName, 'class' => 'h1 block e10-row-this padd5']]);
				$cntChanges = $this->createContent_FilingsDiff ($prevFilingNdx, $filingNdx);
				if (!$cntChanges)
				{
					$this->addContent(['type' => 'line', 'line' => ['text' => 'Nebyly nalezeny žádné rozdíly', 'class' => 'block padd5']]);
				}
			}
			$prevFilingNdx = $filingNdx;
			$prevFilingName = $filingName;
		}
    */
	}

	public function createContentXml_Begin ()
	{
		$this->xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$this->xml .= "<Pisemnost nazevSW=\"Shipard\" verzeSW=\"".__E10_VERSION__."\">\n";
		$this->xml .= "<DPHDP3 verzePis=\"01.02\">\n";
	}

	public function createContentXml_End ()
	{
		$this->xml .= "</DPHDP3>\n</Pisemnost>\n";
	}

	public function createContentXml ()
	{
		$this->createContentXml_Begin();
		$this->createContentXml_End();

		$fn = utils::tmpFileName('xml', 'priznani-oss');
		file_put_contents($fn, $this->xml);

		return $fn;
	}

	public function loadData ()
	{
		$this->loadData_Sum();
		//$this->loadData_Rows();

		//$this->loadInvalidDocs();
/*
		if (!$this->filingNdx && isset($this->reportParams ['filingType']['value']))
		{
			$this->propertiesEngine->properties['xml']['dapdph_forma'] = $this->reportParams ['filingType']['value'];
			unset($this->propertiesEngine->properties['flags']['forma']);
			$this->propertiesEngine->properties['flags']['forma'][$this->reportParams ['filingType']['value']] = 'X';
		}
		$this->data['properties'] = $this->propertiesEngine->properties;

		if (isset($this->data['properties']['xml']['d_zjist']) && $this->data['properties']['xml']['d_zjist'] != '')
		{
			$d_zjist = utils::createDateTime($this->data['properties']['xml']['d_zjist']);
			$this->data['properties']['xml']['d_zjist'] = $d_zjist->format('d.m.Y');
			$this->propertiesEngine->properties['xml']['d_zjist'] = $this->data['properties']['xml']['d_zjist'];
		}
*/
		$this->loadData_OldFiling();

		//$this->calcTaxReturn();
	}

	public function loadData_Sum ()
	{
		$q[] = 'SELECT countryConsumption, docCurrency, taxPercents, SUM(baseDC) AS sumBaseDC, SUM(taxDC) AS sumTaxDC, SUM(totalDC) AS sumTotalDC';
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatOSS] AS [rows]');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);

		array_push($q, ' GROUP BY countryConsumption, docCurrency, taxPercents');
		array_push($q, ' ORDER BY countryConsumption, docCurrency, taxPercents');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$this->data['SUM'][] = $newItem;
		}
	}

	public function loadData_Rows ()
	{
    /*
		$did = 'D'.$dir;

		$q[] = 'SELECT [rows].*, docs.docState as docState, ';
		array_push($q, ' docs.person AS personNdx, docs.docType AS docType, persons.fullName AS personName, persons.id AS personId, ');
		array_push($q, ' validity.valid AS valid, validity.msg AS personMsg, validity.revalidate AS personRevalidate');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatReturn] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] as docs ON [rows].document = docs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] as persons ON docs.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsValidity] AS validity ON persons.ndx = validity.person');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);
		array_push($q, ' AND [taxDir] = %i', $dir);

		array_push($q, ' ORDER BY taxCode, docNumber');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$this->data[$did][] = $newItem;
		}
    */
	}

	public function loadData_OldFiling ()
	{
    /*
		$this->oldVatReturn = NULL;

		if ($this->disableOldVatReturn)
			return;

		if (!isset($this->propertiesEngine->properties['flags']['forma']['D']))
			return;

		$q[] = 'SELECT * FROM [e10doc_taxes_filings] AS filings';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND filings.[report] = %i', $this->taxReportNdx);
		array_push($q, ' ORDER BY filings.ndx DESC');

		$ef = ['0' => 'Aktuální stav'];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
			$ef[$r['ndx']] = $r['title'];

		$oldFilingNdx = 0;
		$prevFilingNdx = -1;
		foreach ($ef as $filingNdx => $filingName)
		{
			if ($prevFilingNdx == $this->filingNdx)
			{
				$oldFilingNdx = $filingNdx;
				break;
			}
			$prevFilingNdx = $filingNdx;
		}

		if (!$oldFilingNdx)
			return;

		$this->oldVatReturn = new \e10doc\taxes\VatReturn\VatReturnReport ($this->app);
		$this->oldVatReturn->disableOldVatReturn = TRUE;
		$this->oldVatReturn->taxReportNdx = $this->taxReportNdx;
		$this->oldVatReturn->filingNdx = $oldFilingNdx;
		$this->oldVatReturn->subReportId = 'ALL';

		$this->oldVatReturn->init();
		$this->oldVatReturn->renderReport();
		$this->oldVatReturn->createReport();
    */
	}

	public function docNumber ($r, &$row)
	{
		if ($this->format === 'pdf')
			return $r['docNumber'];

		$docId = ['table' => 'e10doc.core.heads', 'pk' => $r['document'], 'docAction' => 'edit'];
		$docId['text'] = $r['docNumber'];
		$docId['icon'] = $this->docTypes[$r['docType']]['icon'];
		$docId['title'] = $this->docTypes[$r['docType']]['fullName'].': '.$r['docNumber'];

		$docState = $this->tableDocs->getDocumentState ($r);
		$docStateClass = $this->tableDocs->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
		$row['_options']['cellClasses'] = ['docNumber' => $docStateClass];

		return $docId;
	}


	function addXmlRow ($itemId, $row)
	{
		$xml = '<'.$itemId;
		foreach ($row as $k => $v)
		{
			$xml .= ' '.$k.'="'.utils::es($v).'"';
		}
		$xml .= " />\n";

		$this->xml .= $xml;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Uložit jako XML soubor pro elektronické podání', 'icon' => 'system/actionDownload',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'xml',
				'data-filename' => $this->saveAsFileName('xml')
		];
	}

	public function saveAsFileName ($type)
	{
		$fn = $this->taxReportRecData['title'];
		$fn .= '.xml';
		return $fn;
	}

	public function saveReportAs ()
	{
		$this->createContent_All();

		$this->fullFileName = $this->createContentXml();
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'application/xml';
	}
}
