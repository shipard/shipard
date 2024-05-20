<?php

namespace e10doc\taxes;
use e10\utils, e10\json, e10\uiutils, e10doc\core\libs\E10Utils;


/**
 * Class TaxReportReport
 */
class TaxReportReport extends \e10doc\core\libs\reports\GlobalReport
{
	/** @var  \e10doc\taxes\TableReports */
	var $tableTaxReports;
	/** @var  \e10doc\taxes\TableReportsParts */
	var $tableReportsParts;
	/** @var  \e10doc\taxes\TableFilings */
	var $tableFilings;
	/** @var  \e10doc\core\TableHeads */
	var $tableDocs;
	/** @var  \e10\persons\TablePersons */
	var $tablePersons;

	var $previewReportTemplate = '';

	var $badVatIds = [];
	var $invalidPersons = [];
	var $invalidDocs = [];

	var $taxReportTypeId = '';
	var $taxReportNdx = 0;
	var $taxReportRecData = NULL;
	var $taxReportDef = NULL;
	var $taxRegCfg = NULL;
	var $taxRegCountries = NULL;
	var $useMoreTaxRegs = 0;

	var $filingRecData = NULL;
	var $filingNdx = -1;
	var $filingTypeEnum = [];

	var $propertiesEngine = NULL;
	var $docTypes;
	var $enumFilings;

	var $data = [];
	var $tcSums = [];
	var $partsData;
	var $partsDefs;
	var $xml = '';
	var $cntErrors = 0;

	function init()
	{
		$this->useMoreTaxRegs = intval($this->app()->cfgItem ('e10doc.base.tax.flags.moreRegs', 0));

		$this->tableTaxReports = $this->app->table('e10doc.taxes.reports');
		$this->tableReportsParts = $this->app()->table('e10doc.taxes.reportsParts');

		$this->tableFilings = $this->app->table('e10doc.taxes.reports');
		$this->tableDocs = $this->app->table('e10doc.core.heads');
		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		$this->addParam('fiscalYear');
		$this->addParamReport();
		$this->addParamFiling();

		parent::init();

		if (!$this->taxReportNdx)
			$this->taxReportNdx = intval($this->reportParams ['taxReport']['value']);
		$this->taxReportRecData = $this->tableTaxReports->loadItem ($this->taxReportNdx);
		$this->taxReportDef = $this->app->cfgItem('e10doc.taxes.reportTypes.'.$this->taxReportRecData['reportType'], NULL);
		$this->taxRegCfg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$this->taxReportRecData['taxReg'], NULL);
		$this->taxRegCountries = E10Utils::taxCountries($this->app(), $this->taxRegCfg['taxArea'], NULL, TRUE);

		if ($this->filingNdx === -1)
			$this->filingNdx = intval($this->reportParams ['filing']['value']);

		if ($this->filingNdx)
			$this->filingRecData = $this->tableFilings->loadItem ($this->filingNdx);

		if ($this->taxReportDef)
		{
			$this->propertiesEngine = $this->app->createObject($this->taxReportDef['propertiesEngine']);
			$this->propertiesEngine->load($this->taxReportNdx, $this->filingNdx);

			$this->setInfo('icon', 'report/VatReturnReport');
			$this->setInfo('title', $this->taxReportRecData['title']);
			$this->setInfo('param', 'Období', utils::datef ($this->taxReportRecData['datePeriodBegin'], '%d').' - '.utils::datef ($this->taxReportRecData['datePeriodEnd'], '%d'));

			$this->setInfo('param', 'DIČ', $this->taxRegCfg['title']);
		}
		else
		{
			$this->setInfo('icon', 'report/VatReturnReport');
			$this->setInfo('title', 'Žádné kontrolní hlášení není k dispozici');
		}

		if ($this->subReportId === 'preview' && $this->format === 'pdf')
		{
			$this->reportTemplate = $this->previewReportTemplate;
			$this->paperMargin = '0cm';
		}
	}

	public function addParamReport ()
	{
		$fiscalYearNdx = uiutils::detectParamValue('fiscalYear', E10Utils::todayFiscalYear($this->app));
		$fiscalYearCfg = $this->app->cfgItem ('e10doc.acc.periods.'.$fiscalYearNdx);

		$q[] = '(';
		array_push($q, 'SELECT * FROM [e10doc_taxes_reports] AS reports');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND reports.[docStateMain] = 0');
		array_push($q, ' AND reports.[reportType] = %s', $this->taxReportTypeId);
		array_push($q, ' AND reports.datePeriodBegin >= %d', $fiscalYearCfg['begin']);
		array_push($q, ' AND reports.datePeriodBegin <= %d', $fiscalYearCfg['end']);
		array_push($q, ' ORDER BY reports.datePeriodBegin, reports.ndx');
		array_push($q, ') UNION (');
		array_push($q, 'SELECT * FROM [e10doc_taxes_reports] AS reports');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND reports.[docStateMain] = 2');
		array_push($q, ' AND reports.[reportType] = %s', $this->taxReportTypeId);
		array_push($q, ' AND reports.datePeriodBegin >= %d', $fiscalYearCfg['begin']);
		array_push($q, ' AND reports.datePeriodBegin <= %d', $fiscalYearCfg['end']);
		array_push($q, ' ORDER BY reports.datePeriodBegin DESC, reports.ndx');
		array_push($q, ')');

		$switch = [];
		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$switch[$r['ndx']] = $r['title'];
			if ($this->useMoreTaxRegs)
			{
				$reportTaxReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$r['taxReg'], NULL);
				if ($reportTaxReg)
					$switch[$r['ndx']] .= ' '.$reportTaxReg['title'];
			}
		}

		$this->addParam('switch', 'taxReport', ['title' => NULL, 'switch' => $switch]);
	}

	public function addParamFiling ()
	{
		$taxReportNdx = uiutils::detectParamValue('taxReport', key($this->params->getParams() ['taxReport']['values']));
		if (!in_array($taxReportNdx, array_keys($this->params->getParams() ['taxReport']['values'])))
			$taxReportNdx = key($this->params->getParams() ['taxReport']['values']);

		$q[] = 'SELECT * FROM [e10doc_taxes_filings] AS filings';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND filings.[report] = %i', $taxReportNdx);
		array_push($q, ' ORDER BY filings.ndx DESC');

		$switch = ['0' => 'Aktuální stav'];
		$rows = $this->db()->query($q);

		foreach ($rows as $r)
		{
			$switch[$r['ndx']] = $r['title'];
		}

		$this->addParam('switch', 'filing', ['title' => 'Podání', 'switch' => $switch]);
		$this->enumFilings = $switch;

		$filingNdx = uiutils::detectParamValue('filing', 0);
		if (!$filingNdx && count($this->enumFilings) > 1)
			$this->addParam('switch', 'filingType', ['title' => 'Druh', 'switch' => $this->filingTypeEnum]);
	}

	public function createContent_Errors_InvalidDocs ()
	{
		if (!count($this->invalidDocs))
			return;

		$table = [];
		foreach ($this->invalidDocs as $r)
		{
			$item = [
					'docNumber' => ['text' => $r['docNumber'], 'table' => 'e10doc.core.heads', 'pk' => $r['ndx'], 'docAction' => 'edit', 'icon' => $this->docTypes[$r['docType']]['icon']],
					'dateAccounting' => $r['dateAccounting'], 'dateTax' => $r['dateTax'], 'dateTaxDuty' => $r['dateTaxDuty'],
					'msg' => $r['msg'],
			];

			$docState = $this->tableDocs->getDocumentState ($r);
			$docStateClass = $this->tableDocs->getDocumentStateInfo ($docState['states'], $r, 'styleClass');
			$item['_options']['cellClasses'] = ['docNumber' => $docStateClass];

			$table[] = $item;
		}

		$h = ['#' => '#', 'docNumber' => 'Doklad', 'dateAccounting' => 'Úč. datum', 'dateTax' => 'DUZP', 'dateTaxDuty' => 'DPPD', 'msg' => 'Problém'];
		$title = [['text' => 'Nesrovnalosti v evidenci Dokladů']];

		$content = ['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE];
		if ($this->detailMode)
			$content['pane'] = 'e10-pane e10-pane-table';
		$this->addContent ($content);
	}

	public function createContent_Errors_InvalidPersons ()
	{
		if (!count($this->invalidPersons))
			return;

		$table = [];
		foreach ($this->invalidPersons as $personNdx => $err)
		{
			$item = ['person' => ['text' => $err['fullName'], 'table' => 'e10.persons.persons', 'pk' => $personNdx, 'docAction' => 'edit']];

			if (!$err['valid'])
			{
				$item['msg'] = ['text' => 'Zatím nezkontrolováno'];
			}
			else
			{
				$line = [];
				$msg = json::decode($err['msg']);
				foreach ($msg as $partId => $part)
				{
					foreach ($part as $valueId => $error)
					{
						$info = ['text' => $valueId.': '.$error['msg'], 'class' => 'block', 'icon' => 'icon-exclamation-triangle fa-fw'];
						if (isset($error['registerName']))
							$info['suffix'] = $error['registerName'];
						$line[] = $info;
					}
				}
				$item['msg'] = $line;
			}

			if ($err['revalidate'])
				$item['msg'][] = ['text' => 'Údaje byly opraveny, je naplánována nová kontrola', 'icon' => 'icon-info-circle fa-fw', 'class' => 'block e10-success e10-off'];

			$table[] = $item;
		}

		$h = ['#' => '#', 'person' => 'Osoba', 'msg' => 'Problém'];
		$title = [['text' => 'Nesrovnalosti v evidenci Osob']];

		$content = ['type' => 'table', 'header' => $h, 'table' => $table, 'title' => $title, 'main' => TRUE];
		if ($this->detailMode)
			$content['pane'] = 'e10-pane e10-pane-table';
		$this->addContent ($content);
	}

	public function loadInvalidDocs($taxType = FALSE)
	{
	    $taxCalc = E10Utils::taxCalcIncludingVATCode ($this->app(), $this->taxReportRecData['datePeriodEnd']);
	    if ($taxCalc == 3)
	    {
            $q[] = 'SELECT * FROM [e10doc_core_heads]';
            array_push($q, ' WHERE 1');

            array_push($q, ' AND [docType] IN %in', ['invno', 'invpo', 'cash', 'cashreg']);

            if ($taxType !== FALSE)
            {
                array_push($q, ' AND [taxType] IN %in', $taxType);
            }

            array_push($q, ' AND (',
                '([dateTax] >= %d', $this->taxReportRecData['datePeriodBegin'],
                ' AND [dateTax] <= %d)', $this->taxReportRecData['datePeriodEnd'],
                ' OR ',
                '([dateAccounting] >= %d', $this->taxReportRecData['datePeriodBegin'],
                ' AND [dateAccounting] <= %d)', $this->taxReportRecData['datePeriodEnd'],
                ')'
            );

            array_push($q, ' AND [docState] = %i', 4000);
            array_push($q, ' AND [taxCalc] = %i', 2);
            array_push($q, ' ORDER BY [dateAccounting], [docNumber]');


            $rows = $this->db()->query($q);
            foreach ($rows as $r)
            {
                $taxCalc = E10Utils::taxCalcIncludingVATCode ($this->app(), $r['dateAccounting']);
                if ($taxCalc == 3)
                {
                    if ($r['docType'] == 'cash' && $r['cashBoxDir'] == 2)
                        break;
                    $item = [
                        'ndx' => $r['ndx'], 'docNumber' => $r['docNumber'], 'docType' => $r['docType'],
                        'docState' => $r['docState'], 'docStateMain' => $r['docStateMain'],
                        'dateAccounting' => $r['dateAccounting'], 'dateTax' => $r['dateTax'], 'dateTaxDuty' => $r['dateTaxDuty'],
                        'msg' => 'Neplatná metoda výpočtu DPH',
                    ];
                    $this->invalidDocs[] = $item;
                    $this->cntErrors++;
                }
            }

            unset($q);
        }

		$q[] = 'SELECT * FROM [e10doc_core_heads]';
		array_push($q, ' WHERE 1');

		array_push($q, ' AND [docType] IN %in', $this->taxReportDef['docTypes']);

		array_push($q, ' AND (',
				'([dateTax] >= %d', $this->taxReportRecData['datePeriodBegin'],
				' AND [dateTax] <= %d)', $this->taxReportRecData['datePeriodEnd'],
				' OR ',
				'([dateAccounting] >= %d', $this->taxReportRecData['datePeriodBegin'],
				' AND [dateAccounting] <= %d)', $this->taxReportRecData['datePeriodEnd'],
				')'
		);

		array_push($q, ' AND [docState] NOT IN %in', [4000, 4100, 9800]);
		array_push($q, ' ORDER BY [dateAccounting], [docNumber]');


		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
					'ndx' => $r['ndx'], 'docNumber' => $r['docNumber'], 'docType' => $r['docType'],
					'docState' => $r['docState'], 'docStateMain' => $r['docStateMain'],
					'dateAccounting' => $r['dateAccounting'], 'dateTax' => $r['dateTax'], 'dateTaxDuty' => $r['dateTaxDuty'],
					'msg' => 'Doklad není uzavřen',
			];
			$this->invalidDocs[] = $item;
			$this->cntErrors++;
		}
	}

	public function loadInvalidPersons($forTableSQLName)
	{
		$q[] = 'SELECT [rows].vatId as vatId, [rows].ndx as rowNdx, [rows].docNumber as docNumber, [rows].document as docNdx,';
		array_push($q, ' docs.person as personNdx, docs.docType as docType, persons.fullName as personFullName, persons.disableRegsChecks AS personDisableRegsChecks,');
		array_push($q, ' validity.valid as personValid, validity.msg as personMsg, validity.revalidate as personRevalidate');
		array_push($q, ' FROM ['.$forTableSQLName.'] AS [rows]');
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
			if ($r['personDisableRegsChecks'])
				continue;

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

	public function renderFromTemplate ($templateId, $fileId)
	{
		$t = new \Shipard\Report\TemplateMustache ($this->app);
		$t->loadTemplate($templateId, $fileId.'.mustache');
		$t->setData ($this);
		$c = $t->renderTemplate ();
		return $c;
	}

	public function createReportContentHeader ($contentPart)
	{
		if (($this->subReportId === 'preview' && $this->format === 'pdf') || $this->detailMode)
			return '';

		return parent::createReportContentHeader ($contentPart);
	}
}

