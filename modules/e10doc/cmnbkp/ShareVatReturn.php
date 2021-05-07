<?php

namespace E10Doc\CmnBkp;
use \E10\utils, \E10\HeaderData, E10Doc\Core\e10utils;


/**
 * Class ShareVatReturn
 * @package E10Doc\CmnBkp
 */
class ShareVatReturn extends \e10\share\ShareEngine
{
	var $vatReturnRecData;
	var $vatPeriodNdx;
	var $tableHeads;

	var $enabledDocsTypes = ['invni', 'invno', 'cashreg', 'cash'];

	public function init ()
	{
		parent::init();
		$this->classId = 'e10doc.cmnbkp.ShareVatReturn';
		$this->tableHeads = $this->app->table ('e10doc.core.heads');

		$this->vatPeriodNdx = intval($this->params['vatPeriod']);
	}

	public function actionName()
	{
		return 'Sdílení Přiznání DPH';
	}

	public function actionParams()
	{
		$params = [
			['name' => 'Období DPH', 'id' => 'vatPeriod', 'type' => 'vatPeriod']
		];

		return $params;
	}

	public function setCoreParams (array $params)
	{
		parent::setCoreParams($params);
		if (isset ($params['bank']))
			$this->enabledDocsTypes[] = 'bank';
	}

	public function createShareHeader ()
	{
		//$this->vatReturnRecData = $this->tableHeads->loadItem ($this->coreParams['srcNdx']);

		$vatPeriod = $this->app->cfgItem ('e10doc.vatPeriods.'.$this->vatPeriodNdx, FALSE);
		$this->shareRecData['name'] = 'Přiznání DPH '.$vatPeriod['fullName'];
		$this->shareRecData['shareType'] = 2;
		$this->shareRecData['tableId'] = 'e10doc.core.heads';
		$this->shareRecData['recId'] = $this->coreParams['srcNdx'];

		parent::createShareHeader();

		$this->addFolder ('reports', 'Přehledy');
		foreach ($this->enabledDocsTypes as $dt)
		{
			$docType = $this->app->cfgItem ('e10.docs.types.' . $dt, FALSE);
			$this->addFolder ($dt, $docType['pluralName']);
		}
	}

	public function addReports ()
	{
		// -- vatReturn
		//$report = $this->tableHeads->getReportData ('e10doc.cmnbkp.CmnBkp_TaxVatReturn_Report', $this->vatReturnRecData['ndx']);
		//$this->addReport($report, 'Přiznání DPH', 'priznani-dph', 'reports');

		// -- summary report
		$report = $this->app->createObject('e10doc.finance.reportVAT');
		$report->setParamsValues (['vatPeriod' => $this->vatPeriodNdx]);
		$report->subReportId = 'sum';
		$report->format = 'pdf';
		$report->init ();
		$this->addReport($report, 'Sumární přehled DPH', 'sumarni-prehled', 'reports');

		// -- items report 1
		$report = $this->app->createObject('e10doc.finance.reportVAT');
		$report->setParamsValues (['vatPeriod' => $this->vatPeriodNdx]);
		$report->subReportId = 'items1';
		$report->format = 'pdf';
		$report->init ();
		$this->addReport($report, 'Položkový soupis DPH 1', 'polozkovy-soupis-1', 'reports');

		// -- items report 2
		$report = $this->app->createObject('e10doc.finance.reportVAT');
		$report->setParamsValues (['vatPeriod' => $this->vatPeriodNdx]);
		$report->subReportId = 'items2';
		$report->format = 'pdf';
		$report->init ();
		$this->addReport($report, 'Položkový soupis DPH 2', 'polozkovy-soupis-2', 'reports');

		// -- reverse charge report
		$report = $this->app->createObject('e10doc.finance.reportVAT');
		$report->setParamsValues (['vatPeriod' => $this->vatPeriodNdx]);
		$report->subReportId = 'revCharge';
		$report->format = 'pdf';
		$report->init ();
		$this->addReport($report, 'Přehled PDP', 'prehled-pdp', 'reports');

		// -- intra community report
		$report = $this->app->createObject('e10doc.finance.reportVAT');
		$report->setParamsValues (['vatPeriod' => $this->vatPeriodNdx]);
		$report->subReportId = 'intraCommunity';
		$report->format = 'pdf';
		$report->init ();
		$this->addReport($report, 'Souhrnné hlášení DPH', 'souhrnne-hlaseni', 'reports');

		// -- general ledger report
		if (isset($this->coreParams['generalLedger']))
		{
			$vatPeriod = $this->app->cfgItem ('e10doc.vatPeriods.'.$this->vatPeriodNdx, FALSE);
			$fiscalPeriod = e10utils::todayFiscalMonth($this->app, $vatPeriod['begin']);

			$report = $this->app->createObject('e10doc.debs.libs.reports.GeneralLedger');
			$report->setParamsValues(['fiscalPeriod' => $fiscalPeriod]);
			$report->format = 'pdf';
			$report->init();
			$this->addReport($report, 'Hlavní kniha', 'hlavni-kniha', 'reports');
		}
	}

	function addDocuments_OLD ()
	{
		$cfgTaxCodes = $this->app->cfgItem ('e10.base.taxCodes');
		$validTaxCodes = array ();
		foreach ($cfgTaxCodes as $key => $c)
			if ((isset ($c['rowTaxReturn'])) && ($c['rowTaxReturn'] != 0))
				$validTaxCodes[] = $key;

		$q[] = 'SELECT heads.taxPeriod, heads.docNumber, heads.docType, heads.dateTax as dateTax, taxes.taxCode as taxCode,';
		array_push ($q, ' persons.fullName as personName, heads.personVATIN as personVATIN, heads.ndx as headNdx, heads.cashBoxDir,');
		array_push ($q, ' heads.toPayHc as toPayHc,');
		array_push ($q, ' SUM(taxes.sumBaseHc+taxes.sumTaxHc) as sumTotal, SUM(taxes.sumBaseHc) as sumBase, SUM(taxes.sumTaxHC) as sumTax');
		array_push ($q, ' FROM e10doc_core_taxes as taxes');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON taxes.document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.taxPeriod = %i', $this->vatPeriodNdx, ' AND heads.docState = 4000 ');
		if (count($validTaxCodes))
			array_push ($q, ' AND taxes.taxCode IN %in', $validTaxCodes);
		array_push ($q, ' GROUP BY taxes.document');
		array_push ($q, ' ORDER BY taxes.dateTax, heads.docNumber');

		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$docType = $this->app->cfgItem ('e10.docs.types.' . $r['docType']);

			$info = [
				't1' => $r['docNumber'].' ▪︎ '.$docType['shortcut'], 'i1' => utils::nf($r['toPayHc'], 2),
				't2' => $r['personName'], 'i2' => utils::datef ($r['dateTax'], '%d')
			];
			$id = $docType['shortcut'].'-'.$r['docNumber'];
			$this->addE10Document($r['headNdx'], $r, $info, $id);
		}
	}

	function addDocuments ()
	{
		foreach ($this->enabledDocsTypes as $dt)
		{
			$docType = $this->app->cfgItem ('e10.docs.types.' . $dt);

			$dbCounters = $this->addDocuments_GetDocTypeDbCounters ($dt);
			foreach ($dbCounters as $dbCounterId)
			{
				$firstDoc = $this->addDocuments_GetBorderDoc('first', $dt, $dbCounterId);
				if ($firstDoc === FALSE)
					continue;
				$lastDoc = $this->addDocuments_GetBorderDoc('last', $dt, $dbCounterId);

				$q[] = 'SELECT heads.*, persons.fullName as personName FROM [e10doc_core_heads] AS heads';
				array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
				array_push ($q, ' WHERE heads.docState = 4000');
				array_push ($q, ' AND [docType] = %s', $dt);

				switch ($dt)
				{
					case 'cash':
					case 'cashreg':
						array_push($q, ' AND cashBox = %i', $dbCounterId);
						break;
					case 'bank':
						array_push($q, ' AND myBankAccount = %i', $dbCounterId);
						break;
					default:
						array_push($q, ' AND dbCounter = %i', $dbCounterId);
						break;
				}

				array_push ($q, ' AND (',
					'heads.taxPeriod = %i', $this->vatPeriodNdx,
					' OR ',
						'(', 'heads.docNumber >= %s', $firstDoc['docNumber'], ' AND heads.docNumber <= %s', $lastDoc['docNumber'], ')',
					')'
				);

				array_push ($q, ' ORDER BY heads.docNumber');


				$rows = $this->db()->query($q);
				foreach ($rows as $r)
				{
					$info = [
						't1' => $r['docNumber'].' ▪︎ '.$docType['shortcut'], 'i1' => utils::nf($r['toPayHc'], 2),
						't2' => $r['personName'], 'i2' => utils::datef ($r['dateTax'], '%d')
					];
					$id = $docType['shortcut'].'-'.$r['docNumber'];
					$this->addE10Document($r['ndx'], $r, $info, $id);
				}

				unset ($q);
			}
		}
	}

	public function addDocuments_GetBorderDoc ($type, $dt, $dbCounterId)
	{
		$q[] = 'SELECT * FROM [e10doc_core_heads] WHERE ';
		switch ($dt)
		{
			case 'cash':
			case 'cashreg':
				array_push($q, 'cashBox = %i', $dbCounterId);
				break;
			case 'bank':
				array_push($q, 'myBankAccount = %i', $dbCounterId);
				break;
			default:
				array_push($q, 'dbCounter = %i', $dbCounterId);
				break;
		}

		array_push($q, ' AND [taxPeriod] = %i', $this->vatPeriodNdx);
		array_push($q, ' AND [docType] = %s', $dt);
		array_push($q, ' AND [docState] = %i', 4000);

		if ($type === 'first')
			array_push($q, ' ORDER BY docNumber ASC');
		else
			array_push($q, ' ORDER BY docNumber DESC');

		array_push($q, ' LIMIT 1');

		$row = $this->db()->query($q)->fetch();
		if ($row)
			return $row->toArray();

		return FALSE;
	}

	public function addDocuments_GetDocTypeDbCounters ($dt)
	{
		$dbCounters = [];

		$q[] = 'SELECT ';
		switch ($dt)
		{
			case 'cash':
			case 'cashreg':
				array_push($q, 'cashBox AS dbci');
				break;
			case 'bank':
				array_push($q, 'myBankAccount AS dbci');
				break;
			default:
				array_push($q, 'dbCounter AS dbci');
				break;
		}
		array_push($q, ' FROM [e10doc_core_heads] WHERE');
		array_push($q, ' [taxPeriod] = %i', $this->vatPeriodNdx);
		array_push($q, ' AND [docType] = %s', $dt);
		array_push($q, ' GROUP BY 1');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$dbCounters[] = $r['dbci'];
		}

		return $dbCounters;
	}

	public function addE10Document ($recNdx, $recData, $info, $id)
	{
		$docType = $recData['docType'];
		$folder = $docType;
		$cntAttachments = 0;
		$this->attOrderCounter = 1;

		$tableId = 'e10doc.core.heads';
		$shareItemNdx = $this->addDocument($tableId, $recNdx, $info, $id, $folder);

		// -- outbox
		if ($docType === 'invno')
		{
			$q[] = 'SELECT * FROM e10pro_wkf_messages WHERE 1';
			array_push($q, 'AND tableid = %s', $tableId, ' AND recid = %i', $recNdx);
			array_push($q, 'AND [docStateMain] = 2');
			array_push($q, ' ORDER BY ndx DESC');
			$rows = $this->db()->query ($q);
			$cntAdded = 0;
			foreach ($rows as $r)
			{
				$cntAdded = $this->addDocumentAttachments ('e10pro.wkf.messages', $r['ndx'], $shareItemNdx);
				$cntAttachments += $cntAdded;
				break;
			}

			if (!$cntAdded)
			{ // generate document report
				$report = $this->tableHeads->getReportData ('e10doc.invoicesout.libs.InvoiceOutReport', $recNdx);
				$this->addItemReport($shareItemNdx, $report);
				$cntAttachments++;
			}
		}

		// -- inbox
		if ($docType === 'invni' || $docType === 'cash' || $docType === 'bank')
		{
			// -- attachments from inbox messages
			$q[] = 'SELECT * FROM e10_base_doclinks WHERE 1';
			array_push($q, ' AND srcTableId = %s', 'e10doc.core.heads', ' AND srcRecId = %i', $recNdx);
			array_push($q, ' AND dstTableId = %s', 'e10pro.wkf.messages', 'AND [linkId] = %s', 'e10doc-inbox');
			array_push($q, ' ORDER BY ndx');
			$rows = $this->db()->query ($q);
			$cntAdded = 0;
			foreach ($rows as $r)
			{
				$cntAdded += $this->addDocumentAttachments ('e10pro.wkf.messages', $r['dstRecId'], $shareItemNdx);
				$cntAttachments += $cntAdded;
			}
		}

		// -- documents attachments
		$cntAdded = $this->addDocumentAttachments ($tableId, $recNdx, $shareItemNdx);
		$cntAttachments += $cntAdded;

		// -- accounting report
		$report = $this->tableHeads->getReportData ('e10doc.cmnbkp.libs.CmnBkp_Acc_Report', $recNdx);
		$report->data['disableSigns'] = 1;
		$this->addItemReport($shareItemNdx, $report);
		$cntAttachments += $cntAdded;

		$this->saveItemAttachmentsCount ($shareItemNdx, $cntAttachments);
	}

	public function run ()
	{
		$this->createShareHeader();
		$this->addReports();
		$this->addDocuments();
		$this->saveFoldersCounts();
		$this->done();
	}
}
