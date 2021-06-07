<?php

namespace e10pro\reports\tests\debs;
use e10doc\core\libs\E10Utils, \Shipard\Utils\Utils;


/**
 * Class ReportBadAccounting
 */
class ReportBadAccounting extends \e10doc\core\libs\reports\GlobalReport
{
	/** @var \e10doc\core\TableHeads */
	var $tableHeads;
	var $docTypes;
	var $defaultFiscalPeriod = FALSE;

	function init ()
	{
		$this->tableHeads = $this->app()->table('e10doc.core.heads');
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		if ($this->defaultFiscalPeriod === FALSE)
			$this->defaultFiscalPeriod = E10Utils::prevFiscalMonth($this->app());

		if ($this->subReportId !== 'accounts')
			$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['enableAll', 'quarters', 'halfs', 'years'], 'defaultValue' => $this->defaultFiscalPeriod]);

		parent::init();

		$this->setInfo('icon', 'icon-warning');
		if ($this->subReportId !== 'accounts')
			$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
	}

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

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'baddocs': $this->createContent_BadDocuments(); break;
			case 'docstroubles': $this->createContent_DocumentTroubles(); break;
			case 'outdatedAccounts': $this->createContent_OutdatedAccounts(); break;
			case 'accounts': $this->createContent_NonexAccounts(); break;
			case 'otherErrors': $this->createContent_OtherErrors(); break;
			case 'ALL': $this->createContent_All(); break;
		}
	}

	function createContent_All ()
	{
		$this->createContent_BadDocuments();
		$this->createContent_DocumentTroubles();
		$this->createContent_OutdatedAccounts();
		$this->createContent_NonexAccounts();
		$this->createContent_OtherErrors();
	}

	function createContent_BadDocuments ()
	{
		$q [] = 'SELECT heads.*, persons.fullName as personName ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.docState = 4000');
		array_push ($q, ' AND docStateAcc > 1');
		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);
		array_push ($q, ' ORDER BY dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

			$newItem = [
					'dn' => [
						'text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx'], 'icon' => $docType ['icon']],
						'person' => $r['personName'], 'title' => $r['title'], 'date' => Utils::datef($r['dateAccounting'], '%d'), 'dt' => $docType ['shortcut'],
			];
			$newItem['_options'] = ['cellClasses' => ['dn' => $this->docStateClass($r)]];
			$data[] = $newItem;
		}

		$this->setInfo('title', 'Chybně zaúčtované doklady');
		if (count($data))
		{
			$h = ['#' => '#', 'dn' => '_Doklad', 'dt' => 'DD', 'date' => 'Datum', 'person' => 'Osoba', 'title' => 'Popis'];
			$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Chybně zaúčtované doklady', 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	function createContent_DocumentTroubles ()
	{
		$q [] = 'SELECT journal.*, persons.fullName as personName FROM e10doc_debs_journal AS journal ';
		array_push ($q, ' LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON journal.person = persons.ndx');
		array_push ($q, ' WHERE accounts.ndx IS NULL');
		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value'], 'journal.');

		if ($this->testEngine)
		{
			$maxDate = Utils::today();
			$maxDate->sub (new \DateInterval('P5D'));
			array_push ($q, ' AND journal.dateAccounting <= %d', $maxDate);
		}

		array_push ($q, ' ORDER BY dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			if (!isset($this->docTypes [$r['docType']]))
			{
				error_log("Invalid doctype `{$r['docType']}`: ".json_encode($r));
				continue;
			}
			
			$docType = $this->docTypes [$r['docType']];

			$newItem = [
					'dn' => ['text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['document'], 'icon' => $docType ['icon']],
					'accountId' => ['text'=> $r['accountId'], 'docAction' => 'new', 'table' => 'e10doc.debs.accounts', 'addParams' => '__id='.$r['accountId'], 'icon' => 'icon-plus-circle'],
					'person' => $r['personName'], 'title' => $r['text'], 'date' => Utils::datef($r['dateAccounting'], '%d'), 'dt' => $docType ['shortcut']
			];
			$newItem['_options'] = ['cellClasses' => ['dn' => $this->docStateClass($r)]];
			$data[] = $newItem;
		}

		$this->setInfo('title', 'Účtování na neexistující účty');
		if (count($data))
		{
			$h = ['#' => '#', 'dn' => '_Doklad', 'dt' => 'DD', 'date' => 'Datum', 'accountId' => '_Účet', 'person' => 'Osoba', 'title' => '_Popis'];
			$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Účtování na neexistující účty', 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');

		$this->paperOrientation = 'landscape';
	}

	function createContent_NonexAccounts ()
	{
		$q [] = 'SELECT journal.accountId as accountId, count(*) as cnt FROM e10doc_debs_journal AS journal ';
		array_push ($q, '	LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');
		array_push ($q, ' WHERE accounts.ndx IS NULL');
		array_push ($q, ' GROUP BY journal.accountId');
		array_push ($q, ' ORDER BY 1');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$newItem = [
					'a' => ['text'=> $r['accountId'], 'docAction' => 'new', 'table' => 'e10doc.debs.accounts', 'addParams' => '__id='.$r['accountId'], 'icon' => 'icon-plus-circle'],
					'c' => $r['cnt']];
			$data[] = $newItem;
		}

		$this->setInfo('title', 'Účty neexistující v účtovém rozvrhu');
		if (count($data))
		{
			$h = ['#' => '#', 'a' => '_Účet', 'c' => '+Počet výskytů v účetním deníku'];
			$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Účty neexistující v účtovém rozvrhu', 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	function createContent_OutdatedAccounts ()
	{
		$fp = $this->reportParams ['fiscalPeriod']['value'];
		$fpDef = $this->reportParams ['fiscalPeriod']['values'][$fp];


		$q [] = 'SELECT journal.accountId as accountId, accounts.ndx as accountNdx, journal.money as money,';
		array_push ($q, '	journal.dateAccounting as accDate, journal.document as docNdx,');
		array_push ($q, '	heads.docNumber as docNumber, heads.docType as docType');
		array_push ($q, '	FROM e10doc_debs_journal AS journal');
		array_push ($q, '	LEFT JOIN e10doc_debs_accounts AS accounts ON journal.accountId = accounts.id');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON journal.document = heads.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND journal.dateAccounting >= %d', $fpDef['dateBegin']);
		array_push ($q, ' AND journal.dateAccounting <= %d', $fpDef['dateEnd']);

		array_push ($q, ' AND (');
		array_push ($q, '(accounts.validFrom IS NOT NULL AND accounts.validFrom > journal.dateAccounting)');
		array_push ($q, ' OR ');
		array_push ($q, '(accounts.validTo IS NOT NULL AND accounts.validTo < journal.dateAccounting)');
		array_push ($q, ')');

		array_push ($q, ' ORDER BY 1');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

			$newItem = [
					'a' => ['text'=> $r['accountId'], 'docAction' => 'edit', 'table' => 'e10doc.debs.accounts', 'pk' => $r['accountNdx']],
					'd' => ['text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['docNdx'], 'icon' => $docType['icon']],
					'accDate' => Utils::datef($r['accDate'], '%d'), 'money' => $r['money']
					];
			$data[] = $newItem;
		}

		$this->setInfo('title', 'Účtování na časově neplatné účty');
		if (count($data))
		{
			$h = ['#' => '#', 'a' => '_Účet', 'accDate' => 'Datum', 'money' => ' Částka', 'd' => 'Doklad'];
			$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data]);

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Účtování na časově neplatné účty', 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	function createContent_OtherErrors ()
	{
		$this->createContent_OtherErrors_WrongJournal();
	}

	function createContent_OtherErrors_WrongJournal ()
	{
		$q [] = 'SELECT heads.*, persons.fullName as personName ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.docState != 4000');
		array_push ($q, " AND EXISTS (SELECT ndx FROM e10doc_debs_journal WHERE heads.ndx = e10doc_debs_journal.document)");
		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);
		array_push ($q, ' ORDER BY dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);
		$data = [];
		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

			$newItem = [
				'dn' => ['text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx'], 'icon' => $docType ['icon']],
				'person' => $r['personName'], 'title' => $r['title'], 'date' => Utils::datef($r['dateAccounting'], '%d'), 'dt' => $docType ['shortcut']
			];
			$newItem['_options'] = ['cellClasses' => ['dn' => $this->docStateClass($r)]];
			$data[] = $newItem;
		}

		$this->setInfo('title', 'Neuzavřené doklady s účetním deníkem');
		if (count($data))
		{
			$h = ['#' => '#', 'dn' => '_Doklad', 'dt' => 'DD', 'date' => 'Datum', 'person' => 'Osoba', 'title' => 'Popis'];
			$this->addContent (array ('type' => 'table', 'header' => $h, 'table' => $data));

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'line', 'line' => ['text' => 'Neuzavřené doklady s účetním deníkem', 'class' => 'h2 block pt1']]);
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $data]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'baddocs', 'icon' => 'detailReportDocuments', 'title' => 'Doklady'];
		$d[] = ['id' => 'docstroubles', 'icon' => 'system/detailAccounting', 'title' => 'Účtování'];
		$d[] = ['id' => 'outdatedAccounts', 'icon' => 'detailReportOutdatedAccounts', 'title' => 'Neplatné účty'];
		$d[] = ['id' => 'accounts', 'icon' => 'detailReportAccounts', 'title' => 'Účty'];
		$d[] = ['id' => 'otherErrors', 'icon' => 'detailReportOther', 'title' => 'Ostatní'];

		return $d;
	}

	function docStateClass($r)
	{
		$docStates = $this->tableHeads->documentStates($r);
		$docStateClass = $this->tableHeads->getDocumentStateInfo($docStates, $r, 'styleClass');
		return $docStateClass;
	}
}
