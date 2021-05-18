<?php

namespace e10doc\taxes\VatCS;

use e10\utils, e10\uiutils, e10\json, e10doc\core\e10utils;


/**
 * Class VatCSReportAll
 * @package e10doc\taxes\VatCS
 */
class VatCSReportAll extends \e10doc\taxes\TaxReportReport
{
	var $disableContent = FALSE;

	var $previewRowNumberTotal = 0;
	var $previewRowNumberPage = 0;
	var $previewCode = '';
	var $previewSettings;


	function init()
	{
		$this->taxReportTypeId = 'cz-vat-cs';
		$this->previewReportTemplate = 'e10doc.taxes.tax-vat-cs';
		$this->filingTypeEnum = ['B' => 'Řádné', 'O' => 'Opravné', 'N' => 'Následné'];

		parent::init();
	}

	public function docIdValue ($r, &$row)
	{
		if ($this->format === 'pdf')
			return $r['docId'];

		$docId = ['table' => 'e10doc.core.heads', 'pk' => $r['document'], 'docAction' => 'edit'];
		$docId['text'] = $r['docId'];
		$docId['icon'] = $this->docTypes[$r['docType']]['icon'];
		$docId['title'] = $this->docTypes[$r['docType']]['fullName'].': '.$r['docNumber'];

		if ($r['vatCS'])
		{
			$docId = [$docId];
			$docId[] = ['text' => '', 'icon' => 'icon-toggle-on', 'class' => 'e10-success pull-right', 'title' => 'Manuální úprava'];
		}

		$docState = $this->tableDocs->getDocumentState ($r);
		$docStateClass = $this->tableDocs->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
		$row['_options']['cellClasses'] = ['docId' => $docStateClass];

		return $docId;
	}

	public function vatIdValue ($r)
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
				$vatId[] = ['text' => '', 'icon' => 'icon-exclamation-triangle', 'class' => 'e10-error pull-right'];
			elseif ($r['valid'] == 0)
				$vatId[] = ['text' => '', 'icon' => 'icon-question-circle', 'class' => 'e10-off pull-right'];
		}

		if ($badVatId)
		{
			$vatId[] = ['text' => '', 'icon' => 'icon-file', 'class' => 'e10-error pull-right', 'title' => 'DIČ dokladu nesouhlasí s DIČ v evidenci Osob'];
		}

		return $vatId;
	}

	public function loadData ()
	{
		$this->loadDataPart('A1');
		$this->loadDataPart('A2');
		$this->loadDataPart('A4');
		$this->loadDataPart('A5');
		$this->loadDataPartSum ('A5');

		$this->loadDataPart('B1');
		$this->loadDataPart('B2');
		$this->loadDataPart('B3');
		$this->loadDataPartSum ('B3');

		$this->loadDataPartC();

		$this->loadBadVatIds();
		$this->loadInvalidPersons();
		$this->loadInvalidDocs();

		if (!$this->filingNdx && isset($this->reportParams ['filingType']['value']))
		{
			$this->propertiesEngine->properties['xml']['khdph_forma'] = $this->reportParams ['filingType']['value'];
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

		if (isset ($this->data['properties']['xml']['vyzva_odp']) && $this->data['properties']['xml']['vyzva_odp'] !== '')
			$this->disableContent = TRUE;
	}

	public function loadBadVatIds ()
	{
		$q[] = 'SELECT [rows].vatId as vatId, [rows].ndx as rowNdx, [rows].docNumber as docNumber, [rows].document as docNdx,';
		array_push($q, ' docs.person as personNdx, docs.docType as docType, persons.fullName as personFullName');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatCS] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] as docs ON [rows].document = docs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] as persons ON docs.person = persons.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [rows].[report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [rows].[filing] = %i', $this->filingNdx);
		array_push($q, ' AND [rows].vatId != %s', '');
		array_push($q, ' AND NOT EXISTS (');
		array_push($q, ' SELECT ndx FROM [e10_base_properties] as props');
		array_push($q, ' WHERE [tableid] = %s', 'e10.persons.persons', ' AND [group] = %s', 'ids', ' AND [property] = %s', 'taxid');
		array_push($q, ' AND [recid] = docs.person AND props.valueString = [rows].vatId');
		array_push($q, ')');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
					'rowNdx' => $r['rowNdx'], 'docNumber' => $r['docNumber'], 'docNdx' => $r['docNdx'], 'docType' => $r['docType'],
					'personNdx' => $r['personNdx'], 'personFullName' => $r['personFullName']
			];
			$this->badVatIds [$r['vatId']][] = $item;
			$this->cntErrors++;
		}
	}

	public function loadInvalidPersons()
	{
		$q[] = 'SELECT [rows].vatId as vatId, [rows].ndx as rowNdx, [rows].docNumber as docNumber, [rows].document as docNdx,';
		array_push($q, ' docs.person as personNdx, docs.docType as docType, persons.fullName as personFullName,');
		array_push($q, ' validity.valid as personValid, validity.msg as personMsg, validity.revalidate as personRevalidate');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatCS] AS [rows]');
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

	public function loadDataPart ($kind)
	{
		$q[] = 'SELECT [rows].*, persons.ndx as personNdx, persons.fullName as personName, persons.id as personId, docs.docType, ';
		array_push($q, ' docs.vatCS, docs.docState, ');
		array_push($q, ' validity.valid AS valid, validity.validVat AS validVat, validity.taxPayer AS taxPayer');
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatCS] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] as docs ON [rows].document = docs.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] as persons ON docs.person = persons.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_personsValidity] AS validity ON persons.ndx = validity.person');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);

		array_push($q, ' AND [rowKind] LIKE %s', $kind.'%');
		array_push($q, ' ORDER BY docs.docType, [rows].docNumber');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = $r->toArray();
			$this->data[$kind][] = $newItem;
		}
	}

	public function loadDataPartSum ($kind)
	{
		$q[] = 'SELECT SUM(base1) AS base1, SUM(tax1) as tax1, SUM(base2) AS base2, SUM(tax2) as tax2, SUM(base3) AS base3, SUM(tax3) as tax3';
		array_push($q, ' FROM [e10doc_taxes_reportsRowsVatCS]');

		array_push($q, ' WHERE 1');
		array_push($q, ' AND [report] = %i', $this->taxReportNdx);
		array_push($q, ' AND [filing] = %i', $this->filingNdx);
		array_push($q, ' AND [rowKind] LIKE %s', $kind.'%');

		$r = $this->db()->query($q)->fetch();
		if ($r)
			$this->data[$kind.'Sum'] = $r->toArray();

		foreach ($this->data[$kind.'Sum'] as $k => $v)
			$this->data[$kind.'SumPrint'][$k] = utils::nf ($v, 2);
	}

	public function loadDataPartC ()
	{
		$l1 = 0.0;
		$l2 = 0.0;
		$l3 = 0.0;
		$l4 = 0.0;
		$l5 = 0.0;
		$l6 = 0.0;
		$l7 = 0.0;
		$l8 = 0.0;

		if (isset($this->data['A4']))
			foreach ($this->data['A4'] as $r)
			{
				$l1 += $r['base1'];
				$l2 += $r['base2'] + $r['base3'];
			}
		if (isset($this->data['A5']))
			foreach ($this->data['A5'] as $r)
			{
				$l1 += $r['base1'];
				$l2 += $r['base2'] + $r['base3'];
			}
		if (isset($this->data['B2']))
			foreach ($this->data['B2'] as $r)
			{
				$l3 += $r['base1'];
				$l4 += $r['base2'] + $r['base3'];
			}
		if (isset($this->data['B3']))
			foreach ($this->data['B3'] as $r)
			{
				$l3 += $r['base1'];
				$l4 += $r['base2'] + $r['base3'];
			}
		if (isset($this->data['A1']))
			foreach ($this->data['A1'] as $r)
			{
				$l5 += $r['base1'] + $r['base2'] + $r['base3'];
			}
		if (isset($this->data['B1']))
			foreach ($this->data['B1'] as $r)
			{
				$l6 += $r['base1'];
				$l7 += $r['base2'] + $r['base3'];
			}
		if (isset($this->data['A2']))
			foreach ($this->data['A2'] as $r)
			{
				$l8 += $r['base1'];
			}

		$this->data['CSum'] = [
				'celk_zd_a2' => $l8,
				'obrat23' => $l1,
				'obrat5' => $l2,
				'pln23' => $l3,
				'pln5' => $l4,
				'pln_rez_pren' => $l5,
				'rez_pren23' => $l6,
				'rez_pren5' => $l7
		];

		foreach ($this->data['CSum'] as $k => $v)
			$this->data['CSumPrint'][$k] = utils::nf ($v, 2);
	}

	function createContent ()
	{
		if (!$this->taxReportDef)
			return;

		$this->loadData();

		if ($this->cntErrors && $this->subReportId != 'errors' && $this->format !== 'pdf')
		{
			$msg = ['text' => 'Kontrolní hlášení patrně obsahuje chyby. Zkontrolujte prosím pravou záložku Problémy.', 'class' => 'padd5 e10-warning2 h2 block center'];
			$this->addContent(['type' => 'line', 'line' => $msg]);
		}

		switch ($this->subReportId)
		{
			case '':
			case 'A': $this->createContent_A (); break;
			case 'B': $this->createContent_B (); break;
			case 'C': $this->createContent_C (); break;
			case 'ALL': $this->createContent_All (); break;
			case 'preview': $this->createContent_Preview (); break;
			case 'errors': $this->createContent_Errors (); break;
			case 'filings': $this->createContent_Filings(); break;
		}
		//$this->paperOrientation = 'landscape';
		$this->setInfo('saveFileName', $this->taxReportRecData['title'].' oddíl '.$this->subReportId);

		$this->createContentXml();
	}

	public function createContent_A1 ()
	{
		if (!isset($this->data['A1']))
			return;

		$table = [];
		foreach ($this->data['A1'] as $r)
		{
			$newRow = ['vatId' => $this->vatIdValue($r),
					'b1' => $r['base1'] + $r['base2'] + $r['base3'],
					'date' => $r['dateTaxDuty'], 'rcc' => $r['reverseChargeCode']];
			$newRow['docId'] = $this->docIdValue($r, $newRow);

			$table[] = $newRow;
		}

		$h = [
				'#' => 'A1 #', 'vatId' => 'DIČ', 'docId' => 'Ev. č. DD', 'date' => ' DUZP',
				'b1' => '+Základ daně',
				'rcc' => ' Kód PP'
		];

		$title = [
				['text' => 'A.1.', 'suffix' => 'Uskutečněná zdanitelná plnění v režimu přenesení daňové povinnosti, u kterých je povinen přiznat daň příjemce plnění podle § 92a']
		];
		$this->addContent ([
				'type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE,
				'params' => ['tableClass' => 'pageBreakAfter']
		]);
	}


	public function createContent_A2 ()
	{
		if (!isset($this->data['A2']))
			return;

		$table = [];
		foreach ($this->data['A2'] as $r)
		{
			$newRow = [
					'vatId' => $this->vatIdValue($r), 'date' => $r['dateTaxDuty'],
					'b1' => $r['base1'], 't1' => $r['tax1'],
					'b2' => $r['base2'], 't2' => $r['tax2'],
					'b3' => $r['base3'], 't3' => $r['tax3'],
			];
			$newRow['docId'] = $this->docIdValue($r, $newRow);
			$table[] = $newRow;
		}

		$h = [
				'#' => 'A2 #', 'vatId' => 'VAT ID', 'docId' => 'Ev. č. DD', 'date' => ' DPPD',
				'b1' => '+Zák. 1', 't1' => '+Daň 1',
				'b2' => '+Zák. 2', 't2' => '+Daň 2',
				'b3' => '+Zák. 3', 't3' => '+Daň 3',
		];

		$title = [
				['text' => 'A.2.', 'suffix' => 'Přijatá zdanitelná plnění, u kterých je povinen přiznat daň příjemce dle § 108 odst. 1 písm. b) a c) (§ 24, § 25)']
		];
		$this->addContent ([
				'type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE,
				'params' => ['tableClass' => 'pageBreakAfter']
		]);
	}

	public function createContent_A4 ()
	{
		if (!isset($this->data['A4']))
			return;

		$table = [];
		foreach ($this->data['A4'] as $r)
		{
			$newRow = [
					'vatId' => $this->vatIdValue($r), 'date' => $r['dateTaxDuty'],
					'b1' => $r['base1'], 't1' => $r['tax1'],
					'b2' => $r['base2'], 't2' => $r['tax2'],
					'b3' => $r['base3'], 't3' => $r['tax3'],
					'vmc' => $r['vatModeCode']
			];
			$newRow['docId'] = $this->docIdValue($r, $newRow);

			$table[] = $newRow;
		}

		$h = [
				'#' => 'A4 #', 'vatId' => 'DIČ', 'docId' => 'Ev. č. DD', 'date' => ' DPPD',
				'b1' => '+Zák. 1', 't1' => '+Daň 1',
				'b2' => '+Zák. 2', 't2' => '+Daň 2',
				'b3' => '+Zák. 3', 't3' => '+Daň 3',
				'vmc' => ' Kód',
				'xx' => ' ZDPH'
		];

		$title = [
				['text' => 'A.4.', 'suffix' => 'Uskutečněná zdanitelná plnění a přijaté úplaty s povinností přiznat daň dle § 108 odst. 1 písm. a) s hodnotou nad 10.000,- Kč včetně daně a všechny provedené opravy podle § 44 bez ohledu na limit']
		];
		$this->addContent ([
				'type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE,
				'params' => ['tableClass' => 'pageBreakAfter']
		]);
	}

	public function createContent_A5All ()
	{
		if (!isset($this->data['A5']))
			return;

		$table = [];
		foreach ($this->data['A5'] as $r)
		{
			$newRow = [
					'vatId' => $this->vatIdValue($r), 'date' => $r['dateTaxDuty'],
					't' => $r['total1'] + $r['total2'] + $r['total3'],
					'b1' => $r['base1'], 't1' => $r['tax1'],
					'b2' => $r['base2'], 't2' => $r['tax2'],
					'b3' => $r['base3'], 't3' => $r['tax3'],
					'vmc' => $r['vatModeCode']
			];
			$newRow['docId'] = $this->docIdValue($r, $newRow);

			$table[] = $newRow;
		}

		$h = [
				'#' => 'A5 #', 'vatId' => 'DIČ', 'docId' => 'Ev. č. DD', 'date' => ' DPPD',
				't' => '+Celkem',
				'b1' => '+Zák. 1', 't1' => '+Daň 1',
				'b2' => '+Zák. 2', 't2' => '+Daň 2',
				'b3' => '+Zák. 3', 't3' => '+Daň 3',
		];

		$title = [
				['text' => 'A.5.', 'suffix' => 'Ostatní uskutečněná zdanitelná plnění a přijaté úplaty s povinností přiznat daň dle §108 odst.1 písm. a) s hodnotou do 10.000,- Kč včetně daně, nebo plnění, u nichž nevznikla povinnost vystavit daňový doklad']
		];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE]);
	}

	public function createContent_Errors ()
	{
		$this->createContent_Errors_InvalidDocs();
		$this->createContent_Errors_InvalidPersons();
		$this->createContent_Errors_BadVatIds();
	}

	public function createContent_Errors_BadVatIds ()
	{
		if (!count($this->badVatIds))
			return;

		$table = [];
		$personPks = [];
		foreach ($this->badVatIds as $vatId => $vatIdErrors)
		{
			foreach ($vatIdErrors as $err)
			{
				$item = [
					'docNumber' => ['text' => $err['docNumber'], 'table' => 'e10doc.core.heads', 'pk' => $err['docNdx'], 'docAction' => 'edit', 'icon' => $this->docTypes[$err['docType']]['icon']],
					'docVatId' => $vatId,
					'person' => ['text' => $err['personFullName'], 'table' => 'e10.persons.persons', 'pk' => $err['personNdx'], 'docAction' => 'edit'],
				];
				$table[] = $item;
				if (!in_array($err['personNdx'], $personPks))
					$personPks[] = $err['personNdx'];
			}
		}

		// -- append VAT IDs
		$personsVatIds = [];
		$rows = $this->db()->query('SELECT recid, valueString FROM [e10_base_properties] WHERE',
				' [group] = %s', 'ids', ' AND [property] = %s', 'taxid', ' AND [tableid] = %s', 'e10.persons.persons',
				' AND [recid] IN %in', $personPks);
		foreach ($rows as $r)
			$personsVatIds[$r['recid']][] = $r['valueString'];

		foreach ($table as &$row)
		{
			$pndx = $row['person']['pk'];
			if (isset($personsVatIds[$pndx]))
				$row['personVatId'] = implode(', ', $personsVatIds[$pndx]);
		}


		$h = ['#' => '#', 'docNumber' => 'Doklad', 'docVatId' => 'DIČ v dokladu', 'person' => 'Osoba', 'personVatId' => 'DIČ osoby'];
		$title = [['text' => 'Doklady, u kterých DIČ dodavatele/odběratele nesouhlasí s evidencí Osob']];

		$content = ['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE];
		if ($this->detailMode)
			$content['pane'] = 'e10-pane e10-pane-table';
		$this->addContent ($content);
	}

	public function createContent_A ()
	{
		$this->createContent_A1();
		$this->createContent_A2();
		$this->createContent_A4();
		$this->createContent_A5All();
	}

	public function createContent_B1 ()
	{
		if (!isset($this->data['B1']))
			return;

		$table = [];
		foreach ($this->data['B1'] as $r)
		{
			$newRow = [
					'vatId' => $this->vatIdValue($r), 'date' => $r['dateTaxDuty'],
					'b1' => $r['base1'], 't1' => $r['tax1'],
					'b2' => $r['base2'], 't2' => $r['tax2'],
					'b3' => $r['base3'], 't3' => $r['tax3'],
					'rcc' => $r['reverseChargeCode']
			];
			$newRow['docId'] = $this->docIdValue($r, $newRow);

			$table[] = $newRow;
		}

		$h = [
				'#' => 'B1 #', 'vatId' => 'DIČ', 'docId' => 'Ev. č. DD', 'date' => ' DUZP',
				'b1' => '+Zák. 1', 't1' => '+Daň 1',
				'b2' => '+Zák. 2', 't2' => '+Daň 2',
				'b3' => '+Zák. 3', 't3' => '+Daň 3',
				'rcc' => ' Kód PP'
		];

		$title = [
				['text' => 'B.1.', 'suffix' => 'Přijatá zdanitelná plnění v režimu přenesení daňové povinnosti, u kterých je povinen přiznat daň příjemce podle § 92a']
		];
		$this->addContent ([
				'type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE,
				'params' => ['tableClass' => 'pageBreakAfter']
		]);
	}

	public function createContent_B2 ()
	{
		if (!isset($this->data['B2']))
			return;

		$table = [];
		foreach ($this->data['B2'] as $r)
		{
			$newRow = [
					'vatId' => $this->vatIdValue($r), 'date' => $r['dateTaxDuty'],
					'b1' => $r['base1'], 't1' => $r['tax1'],
					'b2' => $r['base2'], 't2' => $r['tax2'],
					'b3' => $r['base3'], 't3' => $r['tax3'],
			];
			$newRow['docId'] = $this->docIdValue($r, $newRow);

			$table[] = $newRow;
		}

		$h = [
				'#' => 'B2 #', 'vatId' => 'DIČ', 'docId' => 'Ev. č. DD', 'date' => ' DPPD',
				'b1' => '+Zák. 1', 't1' => '+Daň 1',
				'b2' => '+Zák. 2', 't2' => '+Daň 2',
				'b3' => '+Zák. 3', 't3' => '+Daň 3',
				'xx1' => 'PP', 'xx2' => 'ZDPH',
		];

		$title = [
				['text' => 'B.2.', 'suffix' => 'Přijatá zdanitelná plnění a poskytnuté úplaty, u kterých příjemce uplatňuje nárok na odpočet daně dle § 73 odst. 1 písm. a) s hodnotou nad 10.000,- Kč včetně daně a všechny přijaté opravy podle § 44 bez ohledu na limit']
		];
		$this->addContent ([
				'type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE,
				'params' => ['tableClass' => 'pageBreakAfter']
		]);
	}

	public function createContent_B3All ()
	{
		if (!isset($this->data['B3']))
			return;

		$table = [];
		foreach ($this->data['B3'] as $r)
		{
			$newRow = [
					'vatId' => $this->vatIdValue($r), 'date' => $r['dateTaxDuty'],
					't' => $r['total1'] + $r['total2'] + $r['total3'],
					'b1' => $r['base1'], 't1' => $r['tax1'],
					'b2' => $r['base2'], 't2' => $r['tax2'],
					'b3' => $r['base3'], 't3' => $r['tax3'],
					'vmc' => $r['vatModeCode']
			];
			$newRow['docId'] = $this->docIdValue($r, $newRow);

			$table[] = $newRow;
		}

		$h = [
				'#' => 'B3 #', 'vatId' => 'DIČ', 'docId' => 'Ev. č. DD', 'date' => ' DPPD',
				't' => '+Celkem',
				'b1' => '+Zák. 1', 't1' => '+Daň 1',
				'b2' => '+Zák. 2', 't2' => '+Daň 2',
				'b3' => '+Zák. 3', 't3' => '+Daň 3',
		];

		$title = [
				['text' => 'B.3.', 'suffix' => 'Přijatá zdanitelná plnění a poskytnuté úplaty, u kterých příjemce uplatňuje nárok na odpočet daně dle § 73 odst. 1 písm.a) s hodnotou do 10.000,- Kč včetně daně']
		];
		$this->addContent ([
				'type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE,
				'params' => ['tableClass' => 'pageBreakAfter']
		]);
	}

	public function createContent_B ()
	{
		$this->createContent_B1();
		$this->createContent_B2();
		$this->createContent_B3All();
	}

	public function createContent_C ()
	{
		$table = [
				['line' => '1', 'text' => 'A.4. + A.5. celkem základy daně u základní sazby DPH', 'sumBase' => $this->data['CSum']['obrat23']],
				['line' => '2', 'text' => 'A.4. + A.5. celkem základy daně u první snížené a druhé snížené sazby DPH', 'sumBase' => $this->data['CSum']['obrat5']],
				['line' => '40', 'text' => 'B.2. + B.3. celkem základy daně u základní sazby DPH', 'sumBase' => $this->data['CSum']['pln23']],
				['line' => '41', 'text' => 'B.2. + B.3. celkem základy daně u první snížené a druhé snížené sazby DPH', 'sumBase' => $this->data['CSum']['pln5']],
				['line' => '25', 'text' => 'A.1. celkem základy daně', 'sumBase' => $this->data['CSum']['pln_rez_pren']],
				['line' => '10', 'text' => 'B.1. celkem základy daně u základní sazby DPH', 'sumBase' => $this->data['CSum']['rez_pren23']],
				['line' => '11', 'text' => 'B.1. celkem základy daně u první snížené a druhé snížené sazby DPH', 'sumBase' => $this->data['CSum']['rez_pren5']],
				['line' => '3+4+5+6+9+12+13', 'text' => 'A.2. celkem základy daně', 'sumBase' => $this->data['CSum']['celk_zd_a2']]
		];

		$h = ['line' => ' řádek DaP', 'text' => '', 'sumBase' => ' Základ daně', '_options' => ['cellClasses' => ['state' => 'e10-icon']]];

		$title = [
				['text' => 'C.', 'suffix' => 'Kontrolní řádky na Daňové přiznání k DPH (DaP)']
		];
		$this->addContent ([
				'type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE
		]);
	}

	public function createContent_Filings ()
	{
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
	}

	public function createContent_FilingsDiff ($filing1Ndx, $filing2Ndx)
	{
		$cntChanges = 0;

		// -- changes
		/*$changes = [];
		$q = [];
		array_push ($q, 'SELECT t1.* FROM [e10doc_taxes_reportsRowsVatReturn] AS t1');
		array_push ($q, 'WHERE t1.filing = %i', $filing1Ndx, ' AND [report] = %i', $this->taxReportNdx);

		array_push ($q, ' AND EXISTS (',
				'SELECT ndx FROM e10doc_taxes_reportsRowsVatReturn AS t2 WHERE t1.document = t2.document AND t1.taxCode = t2.taxCode',
				' AND t2.filing = %i', $filing2Ndx, ' AND t2.[report] = %i', $this->taxReportNdx,
				' AND (',
				't1.base != t2.base ', ' OR t1.tax != t2.tax ', ' OR t1.total != t2.total ',
				')',
				')');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			$item = ['base' => $r['base'], 'tax' => $r['tax']];
			$item['docNumber'] = $this->docNumber($r, $item);
			$changes[] = $item;
		}

		if (count($changes))
		{
			$title = 'Změny';
			$h = ['#' => '#', 'docNumber' => 'Doklad', 'vatId' => 'DIČ', 'taxCode' => 'Sazba DPH', 'dateTax' => 'DUZP', 'base' => ' Základ', 'tax' => ' Daň'];
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $changes, 'title' => $title]);
			$cntChanges += count($changes);
		}
*/
		
		// -- new rows
		$changes = [];
		$q = [];
		array_push ($q, 'SELECT t1.* FROM [e10doc_taxes_reportsRowsVatCS] AS t1');
		array_push ($q, 'WHERE t1.filing = %i', $filing1Ndx, ' AND [report] = %i', $this->taxReportNdx);

		array_push ($q, ' AND NOT EXISTS (',
				'SELECT ndx FROM e10doc_taxes_reportsRowsVatCS AS t2 WHERE t1.document = t2.document',
				'AND t2.filing = %i', $filing2Ndx, ' AND t2.[report] = %i', $this->taxReportNdx,
				')');

		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$taxCode = $this->taxCodes[$r['taxCode']];
			$item = ['base1' => $r['base1'], 'tax1' => $r['tax1'], 'rowKind' => $r['rowKind'], 'dateTax' => $r['dateTax']];
			$item['docNumber'] = $r['docNumber'];//$this->docNumber($r, $item);
			$item['vatId'] = $r['vatId'];
			$rowId = substr($r['rowKind'], 0, 2);
			$changes[$rowId][] = $item;
		}

		if (count($changes))
		{
			$title = 'Nové doklady';

			foreach ($changes as $rowId => $rows)
			{
				$h = [
						'#' => '#', 'docNumber' => 'Doklad', 'dateTax' => 'DUZP', 'vatId' => 'DIČ', 'rowKind' => 'Část',
						'base1' => '+Základ 1', 'tax1' => '+Daň 1', 'base2' => '+Základ 2', 'tax2' => '+Daň 2', 'base3' => '+Základ 3', 'tax3' => '+Daň 3'
				];
				$this->addContent(['type' => 'table', 'header' => $h, 'table' => $rows, 'title' => $title]);
				$cntChanges += count($rows);
			}
		}

		// -- missing rows

		return $cntChanges;
	}


	public function createContent_All ()
	{
		$this->createContent_A();
		$this->createContent_B();
		$this->createContent_C();
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'A', 'icontxt' => 'Ⓐ', 'title' => 'Výstup'];
		$d[] = ['id' => 'B', 'icontxt' => 'Ⓑ', 'title' => 'Vstup'];
		$d[] = ['id' => 'C', 'icontxt' => 'Ⓒ', 'title' => 'Kontrolní řádky'];
		$d[] = ['id' => 'preview', 'icon' => 'detailReportTranscript', 'title' => 'Opis'];
		$d[] = ['id' => 'errors', 'icon' => 'detailReportProblems', 'title' => 'Problémy'];
		$d[] = ['id' => 'filings', 'icon' => 'detailReportDifferences', 'title' => 'Rozdíly'];
		//$d[] = ['id' => 'ALL', 'icontxt' => 'X', 'title' => 'Vše'];

		return $d;
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

	public function createContentXml_A1 ()
	{
		if (!isset($this->data['A1']))
			return;

		foreach ($this->data['A1'] as $r)
		{
			$newRow = [
					'c_evid_dd' => $r['docId'],
					'dic_odb' => substr($r['vatId'], 2),
					'duzp' => $r['dateTaxDuty']->format('d.m.Y'),
					'kod_pred_pl' => $r['reverseChargeCode'],
					'zakl_dane1' => $r['base1']+$r['base2']+$r['base3']
			];
			$this->addXmlRow('VetaA1', $newRow);
		}
	}

	public function createContentXml_A2 ()
	{
		if (!isset($this->data['A2']))
			return;

		foreach ($this->data['A2'] as $r)
		{
			$newRow = [
					'c_evid_dd' => $r['docId'],
					'k_stat' => substr($r['vatId'], 0, 2),
					'vatid_dod' => substr($r['vatId'], 2),
					'dppd' => $r['dateTaxDuty']->format('d.m.Y'),

					'zakl_dane1' => $r['base1'], 'dan1' => $r['tax1'],
					'zakl_dane2' => $r['base2'], 'dan2' => $r['tax2'],
					'zakl_dane3' => $r['base3'], 'dan3' => $r['tax3'],
			];
			$this->addXmlRow('VetaA2', $newRow);
		}
	}

	public function createContentXml_A4 ()
	{
		if (!isset($this->data['A4']))
			return;

		foreach ($this->data['A4'] as $r)
		{
			$newRow = [
					'c_evid_dd' => $r['docId'],
					'dic_odb' => substr($r['vatId'], 2),
					'dppd' => $r['dateTaxDuty']->format('d.m.Y'),
					'zakl_dane1' => $r['base1'], 'dan1' => $r['tax1'],
					'zakl_dane2' => $r['base2'], 'dan2' => $r['tax2'],
					'zakl_dane3' => $r['base3'], 'dan3' => $r['tax3'],
					'kod_rezim_pl' => $r['vatModeCode'],
					'zdph_44' => 'N'
			];
			$this->addXmlRow('VetaA4', $newRow);
		}
	}

	public function createContentXml_A5 ()
	{
		if (!isset($this->data['A5Sum']))
			return;
		$r = $this->data['A5Sum'];
		$newRow = [
				'zakl_dane1' => $r['base1'], 'dan1' => $r['tax1'],
				'zakl_dane2' => $r['base2'], 'dan2' => $r['tax2'],
				'zakl_dane3' => $r['base3'], 'dan3' => $r['tax3'],
		];
		$this->addXmlRow('VetaA5', $newRow);
	}

	public function createContentXml_B1 ()
	{
		if (!isset($this->data['B1']))
			return;
		foreach ($this->data['B1'] as $r)
		{
			$newRow = [
					'c_evid_dd' => $r['docId'],
					'dic_dod' => substr($r['vatId'], 2),
					'duzp' => $r['dateTaxDuty']->format('d.m.Y'),
					'zakl_dane1' => $r['base1'], 'dan1' => $r['tax1'],
					'zakl_dane2' => $r['base2'], 'dan2' => $r['tax2'],
					'zakl_dane3' => $r['base3'], 'dan3' => $r['tax3'],
					'kod_pred_pl' => $r['reverseChargeCode']
			];

			$this->addXmlRow('VetaB1', $newRow);
		}
	}

	public function createContentXml_B2 ()
	{
		if (!isset($this->data['B2']))
			return;

		foreach ($this->data['B2'] as $r)
		{
			$newRow = [
					'c_evid_dd' => $r['docId'],
					'dic_dod' => substr($r['vatId'], 2),
					'dppd' => $r['dateTaxDuty']->format('d.m.Y'),
					'zakl_dane1' => $r['base1'], 'dan1' => $r['tax1'],
					'zakl_dane2' => $r['base2'], 'dan2' => $r['tax2'],
					'zakl_dane3' => $r['base3'], 'dan3' => $r['tax3'],
					'zdph_44' => 'N', 'pomer' => 'N',
			];

			$this->addXmlRow('VetaB2', $newRow);
		}
	}

	public function createContentXml_B3 ()
	{
		if (!isset($this->data['B3Sum']))
			return;
		$r = $this->data['B3Sum'];
		$newRow = [
				'zakl_dane1' => $r['base1'], 'dan1' => $r['tax1'],
				'zakl_dane2' => $r['base2'], 'dan2' => $r['tax2'],
				'zakl_dane3' => $r['base3'], 'dan3' => $r['tax3'],
		];
		$this->addXmlRow('VetaB3', $newRow);
	}

	public function createContentXml_C ()
	{
		$this->addXmlRow('VetaC', $this->data['CSum']);
	}

	public function createContentXml_Begin ()
	{
		$this->xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$this->xml .= "<Pisemnost nazevSW=\"Shipard\" verzeSW=\"".__E10_VERSION__."\">\n";
		$this->xml .= "<DPHKH1 verzePis=\"01.02\">\n";
	}

	public function createContentXml_End ()
	{
		$this->xml .= "</DPHKH1>\n</Pisemnost>\n";
	}

	public function createContentXlmDP ()
	{
		$rp = $this->propertiesEngine->properties;

		// -- věta D
		$D = [];
		$this->addDPItem('dokument', $rp['xml'], $D);
		$this->addDPItem('k_uladis', $rp['xml'], $D);
		$this->addDPItem('khdph_forma', $rp['xml'], $D);
		$this->addDPItem('d_zjist', $rp['xml'], $D);
		$this->addDPItem('c_jed_vyzvy', $rp['xml'], $D);
		$this->addDPItem('vyzva_odp', $rp['xml'], $D);

		$this->addDPItem('d_poddp', $rp['xml'], $D);

		$this->addDPItem('rok', $rp['xml'], $D);
		$this->addDPItem('mesic', $rp['xml'], $D);
		$this->addDPItem('ctvrt', $rp['xml'], $D);
		$this->addDPItem('zdobd_od', $rp['xml'], $D);
		$this->addDPItem('zdobd_do', $rp['xml'], $D);

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

		$this->addDPItem('c_telef', $rp['xml'], $P);
		$this->addDPItem('email', $rp['xml'], $P);
		$this->addDPItem('id_dats', $rp['xml'], $P);

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
		if (!$this->disableContent)
		{
			$this->createContentXml_A1();
			$this->createContentXml_A2();
			$this->createContentXml_A4();
			$this->createContentXml_A5();
			$this->createContentXml_B1();
			$this->createContentXml_B2();
			$this->createContentXml_B3();
			$this->createContentXml_C();
		}
		$this->createContentXml_End();

		$fn = utils::tmpFileName('xml', 'kontrolni-hlaseni');
		file_put_contents($fn, $this->xml);

		return $fn;
	}

	public function createContent_Preview ()
	{
		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div style='text-align: center;'>"]);

		$this->previewSettings = [
				'A1' => ['rowsPerPage' => 50, 'cols' => 6],
				'A2' => ['rowsPerPage' => 48, 'cols' => 10],
				'A4' => ['rowsPerPage' => 48, 'cols' => 12],
				'B1' => ['rowsPerPage' => 49, 'cols' => 11],
				'B2' => ['rowsPerPage' => 48, 'cols' => 12],
		];

		$this->data['currentPageNumber'] = 1;
		$this->data['cntPagesTotal'] = $this->createContent_Preview_CountTotalPages();

		$c = $this->renderFromTemplate ('e10doc.taxes.tax-vat-cs', 'headers');
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);

		if (!$this->disableContent)
		{
			$this->createContent_Preview_A1();
			$this->createContent_Preview_A2();
			$this->createContent_Preview_A4();
			$this->createContent_Preview_B1();
			$this->createContent_Preview_B2();

			$this->data['currentPageNumber']++;
			$c = $this->renderFromTemplate('e10doc.taxes.tax-vat-cs', 'section-c');
			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
		}

		if ($this->format === 'widget')
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
	}

	public function createContent_Preview_A1 ()
	{
		if (!isset($this->data['A1']))
			return;

		$this->createContent_Preview_OpenSection ('A1');
		foreach ($this->data['A1'] as $r)
		{
			$rc  = "<td>".utils::es($r['vatId']).'</td>';
			$rc .= "<td>".utils::es($r['docId']).'</td>';
			$rc .= "<td>".utils::datef($r['dateTaxDuty'], '%d').'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base1'] + $r['base2'] + $r['base3'], 2).'</td>';
			$rc .= "<td class='number'>".$r['reverseChargeCode'].'</td>';
			$this->createContent_Preview_AddSectionRow ('A1', $rc);
		}
		$this->createContent_Preview_CloseSection ('A1');
	}

	public function createContent_Preview_A2 ()
	{
		if (!isset($this->data['A2']))
			return;

		$this->createContent_Preview_OpenSection ('A2');
		foreach ($this->data['A2'] as $r)
		{
			$rc  = "<td>".utils::es($r['vatId']).'</td>';
			$rc .= "<td>".utils::es($r['docId']).'</td>';
			$rc .= "<td>".utils::datef($r['dateTaxDuty'], '%d').'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base3'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax3'], 2).'</td>';
			$this->createContent_Preview_AddSectionRow ('A2', $rc);
		}
		$this->createContent_Preview_CloseSection ('A2');
	}

	public function createContent_Preview_A4 ()
	{
		if (!isset($this->data['A4']))
			return;

		$this->createContent_Preview_OpenSection ('A4');
		foreach ($this->data['A4'] as $r)
		{
			$rc  = "<td>".utils::es($r['vatId']).'</td>';
			$rc .= "<td>".utils::es($r['docId']).'</td>';
			$rc .= "<td>".utils::datef($r['dateTaxDuty'], '%d').'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base3'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax3'], 2).'</td>';
			$rc .= "<td class='number'>".$r['vatModeCode'].'</td>';
			$rc .= "<td>".'</td>';
			$this->createContent_Preview_AddSectionRow ('A4', $rc);
		}
		$this->createContent_Preview_CloseSection ('A4');
	}

	public function createContent_Preview_B1 ()
	{
		if (!isset($this->data['B1']))
			return;

		$this->createContent_Preview_OpenSection ('B1');
		foreach ($this->data['B1'] as $r)
		{
			$rc  = "<td>".utils::es($r['vatId']).'</td>';
			$rc .= "<td>".utils::es($r['docId']).'</td>';
			$rc .= "<td>".utils::datef($r['dateTaxDuty'], '%d').'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base3'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax3'], 2).'</td>';
			$rc .= "<td class='number'>".$r['reverseChargeCode'].'</td>';
			$this->createContent_Preview_AddSectionRow ('B1', $rc);
		}
		$this->createContent_Preview_CloseSection ('B1');
	}

	public function createContent_Preview_B2 ()
	{
		if (!isset($this->data['B2']))
			return;

		$this->createContent_Preview_OpenSection ('B2');
		foreach ($this->data['B2'] as $r)
		{
			$rc  = "<td>".utils::es($r['vatId']).'</td>';
			$rc .= "<td>".utils::es($r['docId']).'</td>';
			$rc .= "<td>".utils::datef($r['dateTaxDuty'], '%d').'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax1'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax2'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['base3'], 2).'</td>';
			$rc .= "<td class='number'>".utils::nf($r['tax3'], 2).'</td>';
			$rc .= "<td>".'</td>';
			$rc .= "<td>".'</td>';
			$this->createContent_Preview_AddSectionRow ('B2', $rc);
		}
		$this->createContent_Preview_CloseSection ('B2');
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
		$this->previewCode .= $this->renderFromTemplate ('e10doc.taxes.tax-vat-cs', 'section-'.strtolower($section).'-begin');
	}

	public function createContent_Preview_ClosePage ($section)
	{
		$this->createContent_Preview_FillPage ($this->previewSettings[$section]['rowsPerPage'] - $this->previewRowNumberPage, $this->previewSettings[$section]['cols']);
		$this->previewCode .= $this->renderFromTemplate ('e10doc.taxes.tax-vat-cs', 'section-'.strtolower($section).'-end');
	}

	public function createContent_Preview_FillPage ($cntRows, $cntCols)
	{
		$row = '<tr>'.str_repeat('<td></td>', $cntCols).'</tr>';
		$c = str_repeat($row, $cntRows);
		$this->previewCode .= $c;
	}

	public function createContent_Preview_CountTotalPages ()
	{
		$cnt = 2; // first + last

		foreach ($this->previewSettings as $sectionId => $sectionSettings)
		{
			if (!isset($this->data[$sectionId]))
				continue;
			$cnt += intval(count($this->data[$sectionId]) / $this->previewSettings[$sectionId]['rowsPerPage']) + 1;
		}

		return $cnt;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
				'text' => 'Uložit jako XML soubor pro elektronické podání', 'icon' => 'icon-download',
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


