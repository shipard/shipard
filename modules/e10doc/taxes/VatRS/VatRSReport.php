<?php

namespace e10doc\taxes\VatRS;

use e10\utils;


/**
 * Class VatRSReport
 * @package e10doc\taxes\VatRS
 */
class VatRSReport extends \e10doc\taxes\TaxReportReport
{
	var $taxCodes;
	var $previewRowNumberTotal = 0;
	var $previewRowNumberPage = 0;
	var $previewCode = '';
	var $previewSettings;

	function init()
	{
		$this->taxReportTypeId = 'eu-vat-rs';
		$this->previewReportTemplate = 'reports.default.e10doc.taxes.tax-eu-vat-rs/cz';
		$this->taxCodes = $this->app->cfgItem ('e10.base.taxCodes');
		$this->filingTypeEnum = ['B' => 'Řádné', 'O' => 'Opravné', 'D' => 'Dodatečné'];
		
		parent::init();
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'recStatement', 'icon' => 'detailReportSummaryReport', 'title' => 'Souhrnné hlášení'];
		$d[] = ['id' => 'docs', 'icon' => 'detailReportDocuments', 'title' => 'Doklady'];
		$d[] = ['id' => 'preview', 'icon' => 'detailReportTranscript', 'title' => 'Opis'];
		$d[] = ['id' => 'errors', 'icon' => 'detailReportProblems', 'title' => 'Problémy'];

		return $d;
	}

	function createContent ()
	{
		if (!$this->taxReportDef)
			return;

		$this->loadData();

		if ($this->cntErrors && $this->subReportId != 'errors' && $this->format !== 'pdf')
		{
			$msg = ['text' => 'Souhrnné hlášení patrně obsahuje chyby. Zkontrolujte prosím pravou záložku Problémy.', 'class' => 'padd5 e10-warning2 h2 block center'];
			$this->addContent(['type' => 'line', 'line' => $msg]);
		}

		switch ($this->subReportId)
		{
			case '':
			case 'recStatement': $this->createContent_RecapitulativeStatement(); break;
			case 'docs': $this->createContent_Docs(); break;
			case 'preview': $this->createContent_Preview (); break;
			case 'errors': $this->createContent_Errors (); break;
			case 'ALL': $this->createContent_All (); break;
		}
		$this->setInfo('saveFileName', $this->taxReportRecData['title']);

		$this->createContentXml();
	}

	function createContent_RecapitulativeStatement()
	{
		$table = [];

		foreach ($this->data['rs'] as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			$item = ['vatId' => $r['vatId'], 'base' => $r['sumBase'], 'baseRounded' => $r['sumBaseRounded'], 'cnt' => $r['cnt'], 'code' => $taxCode['intraCommunityCode']];

			$table[] = $item;
		}

		$title = [['text' => 'Souhrnné hlášení', 'class' => 'h2']];
		$h = ['#' => '#', 'vatId' => 'DIČ', 'code' => ' Kód plnění', 'cnt' => '+Počet plnění', 'base' => '+Základ přesně', 'baseRounded' => '+Základ zaokr.'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE]);
	}

	function createContent_Docs ()
	{
		$table = [];
		foreach ($this->data['docs'] as $r)
		{
			$item = ['dateTax' => $r['dateTax'], 'base' => $r['base'], 'person' => $r['personName']];
			$item['docNumber'] = $this->docNumber($r, $item);
			$item['vatId'] = $this->vatId($r);

			$table[] = $item;
		}

		$title = [['text' => 'Položkový soupis', 'class' => 'h2']];
		$h = ['#' => '#', 'docNumber' => 'Doklad', 'dateTax' => 'DUZP', 'vatId' => 'DIČ', 'base' => '+Základ', 'person' => 'Odběratel'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE]);
	}

	public function createContent_Errors ()
	{
		$this->createContent_Errors_InvalidDocs();
		$this->createContent_Errors_InvalidPersons();
	//	$this->createContent_Errors_BadVatIds();
	}

	public function createContent_Preview ()
	{
		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div style='text-align: center;'>"]);

		$this->previewSettings = [
				'rs' => ['rowsPerPage' => 52, 'cols' => 6],
		];

		$this->data['currentPageNumber'] = 1;
		$this->data['cntPagesTotal'] = 1 + intval(count($this->data['rs'] ?? []) / $this->previewSettings['rs']['rowsPerPage']) + 1;

		$c = $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-eu-vat-rs/cz', 'header');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		$this->createContent_Preview_RS();

		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
	}

	public function createContent_Preview_RS ()
	{
		if (!isset($this->data['rs']))
			return;

		$this->createContent_Preview_OpenSection ('rs');
		foreach ($this->data['rs'] as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];

			$rc  = "<td>".utils::es(substr($r['vatId'], 0, 2)).'</td>';
			$rc .= "<td>".utils::es(substr($r['vatId'], 2)).'</td>';
			$rc .= "<td class='number'>".$taxCode['intraCommunityCode'].'</td>';
			$rc .= "<td class='number'>".utils::nf($r['cnt'], 0).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['sumBaseRounded'], 0).'</td>';

			$this->createContent_Preview_AddSectionRow ('rs', $rc);
		}
		$this->createContent_Preview_CloseSection ('rs');
	}

	public function createContent_Preview_AddSectionRow ($section, $rowCode)
	{
		if ($this->previewRowNumberPage === 1)
			$this->createContent_Preview_OpenPage ($section);

		$this->previewCode .= '<tr>';
		$this->previewCode .= "<td class='number'>".$this->previewRowNumberTotal.'</td>';
		$this->previewCode .= $rowCode;
		$this->previewCode .= "</tr>\n";

		$this->previewRowNumberTotal++;
		$this->previewRowNumberPage++;

		if ($this->previewRowNumberPage > $this->previewSettings[$section]['rowsPerPage'])
		{
			$this->createContent_Preview_ClosePage($section);
			$this->previewRowNumberPage = 1;
		}
	}

	public function createContent_Preview_OpenSection ($section)
	{
		$this->previewRowNumberTotal = 1;
		$this->previewRowNumberPage = 1;
		$this->previewCode = '';
	}

	public function createContent_Preview_CloseSection ($section)
	{
		$this->createContent_Preview_ClosePage ($section);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->previewCode]);
	}

	public function createContent_Preview_OpenPage ($section)
	{
		$this->data['currentPageNumber']++;
		$this->previewCode .= $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-eu-vat-rs/cz', 'section-'.strtolower($section).'-begin');
	}

	public function createContent_Preview_ClosePage ($section)
	{
		$this->createContent_Preview_FillPage ($this->previewSettings[$section]['rowsPerPage'] - $this->previewRowNumberPage, $this->previewSettings[$section]['cols']);
		$this->previewCode .= $this->renderFromTemplate ('reports.default.e10doc.taxes.tax-vat-cs', 'section-'.strtolower($section).'-end');
	}

	public function createContent_Preview_FillPage ($cntRows, $cntCols)
	{
		$row = '<tr>'.str_repeat('<td></td>', $cntCols).'</tr>';
		$c = str_repeat($row, $cntRows);
		$this->previewCode .= $c;
	}

	public function createContent_All ()
	{
		$this->createContent_RecapitulativeStatement();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='pageBreakAfter'></div>"]);
		$this->createContent_Docs();
	}

	public function loadData ()
	{
		$this->loadData_RecapitulativeStatement();
		$this->loadData_Docs();

		$this->loadInvalidDocs([1]); // intrakomunitární
		$this->loadInvalidPersons();

		$this->data['properties'] = $this->propertiesEngine->properties;
	}

	public function loadData_RecapitulativeStatement ()
	{
		$rsTaxCodes = [];
		foreach ($this->taxCodes as $key => $c)
		{
			if (isset ($c['intraCommunityCode']))
				$rsTaxCodes[] = $key;
		}

		if (!count($rsTaxCodes))
			return;

		$q[] = 'SELECT vatId, taxCode, SUM(base) AS sumBase, COUNT(*) as cnt';
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatRS] AS [rows]');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);
		array_push($q, ' AND [taxCode] IN %in', $rsTaxCodes);

		array_push($q, ' GROUP BY taxCode, vatId');
		array_push($q, ' ORDER BY vatId, taxCode');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$newItem['sumBaseRounded'] = ceil($newItem['sumBase']);
			$this->data['rs'][] = $newItem;
		}
	}

	public function loadData_Docs ()
	{
		$rsTaxCodes = [];
		foreach ($this->taxCodes as $key => $c)
		{
			if (isset ($c['intraCommunityCode']))
				$rsTaxCodes[] = $key;
		}

		if (!count($rsTaxCodes))
			return;

		$q[] = 'SELECT [rows].*, docs.docState as docState, ';
		array_push($q, ' docs.person AS personNdx, docs.docType AS docType, persons.fullName AS personName, persons.id AS personId, ');
		array_push($q, ' validity.valid AS valid, validity.msg AS personMsg, validity.revalidate AS personRevalidate');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatRS] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] as docs ON [rows].document = docs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] as persons ON docs.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsValidity] AS validity ON persons.ndx = validity.person');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);
		array_push($q, ' AND [taxCode] IN %in', $rsTaxCodes);

		array_push($q, ' ORDER BY taxCode, docNumber');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$this->data['docs'][] = $newItem;
		}
	}

	public function loadInvalidPersons()
	{
		$q[] = 'SELECT [rows].vatId as vatId, [rows].ndx as rowNdx, [rows].docNumber as docNumber, [rows].document as docNdx,';
		array_push($q, ' docs.person as personNdx, docs.docType as docType, persons.fullName as personFullName,');
		array_push($q, ' validity.valid as personValid, validity.msg as personMsg, validity.revalidate as personRevalidate');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatRS] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] as docs ON [rows].document = docs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] as persons ON docs.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsValidity] AS validity ON persons.ndx = validity.person');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [rows].[report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [rows].[filing] = %i', $this->filingNdx);
		array_push($q, ' AND docs.[person] != 0');
		array_push($q, 'AND (',
				' validity.[valid] != %i', 1,
				' OR',
				'NOT EXISTS (SELECT ndx FROM [e10_persons_personsValidity] WHERE person = docs.person)',
				')');
		array_push($q, ' ORDER BY persons.fullName');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$personNdx = $r['personNdx'];
			$item = [
					'rowNdx' => $r['rowNdx'], 'docNumber' => $r['docNumber'], 'docNdx' => $r['docNdx'], 'docType' => $r['docType'],
					'personNdx' => $r['personNdx'], 'personFullName' => $r['personFullName']
			];

			if (!isset($this->invalidPersons[$personNdx]))
			{
				$this->invalidPersons[$personNdx] = [
						'fullName' => $r['personFullName'],
						'valid' => $r['personValid'], 'msg' => $r['personMsg'],
						'revalidate' => $r['personRevalidate'],
						'docs' => []
				];
			}

			$this->invalidPersons[$personNdx]['docs'][] = $item;
			$this->cntErrors++;
		}
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

	public function vatId ($r)
	{
		if ($this->format === 'pdf')
			return $r['vatId'];

		if (!$r['personNdx'])
			return '';

		$vatId = ['table' => 'e10.persons.persons', 'pk' => $r['personNdx'], 'docAction' => 'edit'];
		if ($r['vatId'] === '')
		{
			$vatId['text'] = '#'.$r['personId'];
			$vatId['class'] = 'e10-small';
		}
		else
			$vatId['text'] = $r['vatId'];
		$vatId['title'] = $r['personName'];

		$badVatId = FALSE;
		if (isset($this->badVatIds[$r['vatId']]))
			$badVatId = TRUE;

		if ($r['valid'] !== 1 || $badVatId)
			$vatId = [$vatId];

		if ($r['valid'] !== 1)
		{
			if ($r['valid'] == 2)
				$vatId[] = ['text' => '', 'icon' => 'system/iconWarning', 'class' => 'e10-error pull-right'];
			elseif ($r['valid'] == 0)
				$vatId[] = ['text' => '', 'icon' => 'icon-question-circle', 'class' => 'e10-off pull-right'];
		}

		if ($badVatId)
		{
			$vatId[] = ['text' => '', 'icon' => 'icon-file', 'class' => 'e10-error pull-right', 'title' => 'DIČ dokladu nesouhlasí s DIČ v evidenci Osob'];
		}

		return $vatId;
	}

	public function createContentXml_Begin ()
	{
		$this->xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$this->xml .= "<Pisemnost nazevSW=\"Shipard\" verzeSW=\"".__E10_VERSION__."\">\n";
		$this->xml .= "<DPHSHV verzePis=\"01.02\">\n";
	}

	public function createContentXml_End ()
	{
		$this->xml .= "</DPHSHV>\n</Pisemnost>\n";
	}

	public function createContentXlmDP ()
	{
		$rp = $this->propertiesEngine->properties;

		// -- věta D
		$D = [];
		$this->addDPItem('dokument', $rp['xml'], $D);
		$this->addDPItem('k_uladis', $rp['xml'], $D);
		$this->addDPItem('shvies_forma', $rp['xml'], $D);

		$this->addDPItem('d_poddp', $rp['xml'], $D);

		$this->addDPItem('rok', $rp['xml'], $D);
		$this->addDPItem('mesic', $rp['xml'], $D);
		$this->addDPItem('ctvrt', $rp['xml'], $D);

		$this->addXmlRow('VetaD', $D);


		// -- věta P
		$P = [];

		$this->addDPItem('c_pracufo', $rp['xml'], $P);
		$this->addDPItem('c_ufo', $rp['xml'], $P);

		$this->addDPItem('typ_ds', $rp['xml'], $P);
		$this->addDPItem('dic', $rp['xml'], $P);
		$this->addDPItem('zkrobchjm', $rp['xml'], $P);

		$this->addDPItem('prijmeni', $rp['xml'], $P);
		$this->addDPItem('jmeno', $rp['xml'], $P);
		$this->addDPItem('titul', $rp['xml'], $P);

		$this->addDPItem('ulice', $rp['xml'], $P);
		$this->addDPItem('c_pop', $rp['xml'], $P);
		$this->addDPItem('c_orient', $rp['xml'], $P);
		$this->addDPItem('naz_obce', $rp['xml'], $P);
		$this->addDPItem('psc', $rp['xml'], $P);
		$this->addDPItem('stat', $rp['xml'], $P);

		$this->addDPItem('sest_prijmeni', $rp['xml'], $P);
		$this->addDPItem('sest_jmeno', $rp['xml'], $P);
		$this->addDPItem('sest_telef', $rp['xml'], $P);

		$this->addDPItem('zast_typ', $rp['xml'], $P);
		$this->addDPItem('zast_kod', $rp['xml'], $P);
		$this->addDPItem('zast_nazev', $rp['xml'], $P);
		$this->addDPItem('zast_ic', $rp['xml'], $P);
		$this->addDPItem('zast_prijmeni', $rp['xml'], $P);
		$this->addDPItem('zast_jmeno', $rp['xml'], $P);
		$this->addDPItem('zast_dat_nar', $rp['xml'], $P);
		$this->addDPItem('zast_ev_cislo', $rp['xml'], $P);

		$this->addDPItem('opr_prijmeni', $rp['xml'], $P);
		$this->addDPItem('opr_jmeno', $rp['xml'], $P);
		$this->addDPItem('opr_postaveni', $rp['xml'], $P);

		$this->addXmlRow('VetaP', $P);
	}

	function addDPItem ($key, $src, &$dest)
	{
		if (isset($src[$key]))
		{
			$dest[$key] = $src[$key];
			return;
		}

		return;
	}

	public function createContentXml ()
	{
		$this->createContentXml_Begin();
		$this->createContentXlmDP();
		$this->createContentXml_R();
		$this->createContentXml_End();

		$fn = utils::tmpFileName('xml', 'souhrnne-hlaseni');
		file_put_contents($fn, $this->xml);

		return $fn;
	}

	public function createContentXml_R ()
	{
		if (!isset($this->data['rs']))
			return;

		foreach ($this->data['rs'] as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			$newRow =
					[
							'c_vat' => substr($r['vatId'], 2),
							'k_stat' => substr($r['vatId'], 0, 2),
							'k_pln_eu' => $taxCode['intraCommunityCode'],
							'pln_hodnota' => $r['sumBaseRounded'],
							'pln_pocet' => $r['cnt'],
					];

			$this->addXmlRow('VetaR', $newRow);
		}
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
