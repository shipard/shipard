<?php

namespace terminals\ros;

use \Shipard\Utils\Utils, e10doc\core\libs\E10Utils;


/**
 * Class ReportTest
 */
class ReportTest extends \e10doc\core\libs\reports\GlobalReport
{
	var $tableHeads;
	var $docTypes;

	var $badDocs = [];
	var $rosStates;

	var $badRegs = [];

	function init ()
	{
		if ($this->subReportId === '')
			$this->subReportId = 'docs';

		$this->tableHeads = $this->app->table ('e10doc.core.heads');
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');
		$this->rosStates = $this->tableHeads->columnInfoEnum ('rosState');

		if ($this->subReportId !== 'regs')
			$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['enableAll', 'quarters', 'halfs', 'years']]);

		parent::init();

		$this->setInfo('icon', 'tables/e10doc.ros.journal');
	}

	function createContent ()
	{
		switch ($this->subReportId)
		{
			case '':
			case 'docs': $this->createContent_BadDocs(); break;
			case 'regs': $this->createContent_BadRegs(); break;
			case 'ALL': $this->createContent_All(); break;
		}
	}

	function createContent_All ()
	{
		$this->createContent_BadDocs();
		$this->createContent_BadRegs();

	}

	function createContent_BadDocs ()
	{
		$this->setInfo('title', 'Kontrola EET - Doklady');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);

		$rosClasses = [0 => 'e10-row-plus', 1 => 'e10-row-plus', 2 => 'e10-warning3', 3 => 'e10-warning3'];


		$q [] = 'SELECT heads.*, persons.fullName as personName, rosJournal.resultCode1, rosJournal.resultCode2, rosJournal.dateSent ';
		array_push ($q, ' FROM e10doc_core_heads as heads');
		array_push ($q, '	LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx');
		array_push ($q, '	LEFT JOIN e10doc_ros_journal as rosJournal ON heads.rosRecord = rosJournal.ndx');

		array_push ($q, ' WHERE heads.docState IN %in', [4000, 4100]);

		array_push ($q, ' AND heads.rosReg != 0');

		if ($this->testEngine)
		{
			$maxDate = Utils::today();
			$maxDate->sub (new \DateInterval('P10D'));
			array_push ($q, ' AND heads.dateAccounting >= %d', $maxDate);
		}

		array_push ($q, ' AND (');
		array_push ($q, ' heads.rosState > 1');
		array_push ($q, ' OR rosJournal.resultCode1 = %s', '');
		array_push ($q, ' OR rosJournal.resultCode1 IS NULL');
		array_push ($q, ' OR rosJournal.resultCode2 = %s', '');
		array_push ($q, ' OR rosJournal.resultCode2 IS NULL');
		array_push ($q, ' )');

		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);
		array_push ($q, ' ORDER BY dateAccounting, docNumber');

		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
			$docType = $this->docTypes [$r['docType']];

			$rosState = $this->rosStates[$r['rosState']];

			$newItem = [
				'dn' => ['text'=> $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['ndx'], 'icon' => $docType ['icon']],
				'person' => $r['personName'], 'title' => $r['title'], 'date' => Utils::datef ($r['dateAccounting'], '%d'),
				'amount' => $r['toPay'], 'state' => $rosState, 'rc1' => $r['resultCode1'], 'rc2' => $r['resultCode2'],
				'dateSent' => Utils::datef ($r['dateSent'], '%d, %T')
			];

			$newItem['_options']['cellClasses']['state'] = $rosClasses[$r['rosState']];

			if (!$newItem['rc1'] || $newItem['rc1'] === '')
				$newItem['_options']['cellClasses']['rc1'] = 'e10-warning3';
			if (!$newItem['rc2'] || $newItem['rc2'] === '')
				$newItem['_options']['cellClasses']['rc2'] = 'e10-warning3';

			$this->badDocs[] = $newItem;
		}

		if (count($this->badDocs))
		{
			$h = [
				'#' => '#', 'dn' => 'Doklad', 'date' => 'Úč. datum', 'amount' => ' Částka',
				'dateSent' => 'Čas odeslání', 'state' => 'Stav', 'rc1' => 'Kód 1', 'rc2' => 'Kód 2'
			];
			$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->badDocs]);

			if ($this->testEngine)
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $this->badDocs]);
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	function createContent_BadRegs ()
	{
		$this->setInfo('title', 'Kontrola EET - Registrace a certifikáty');

		/** @var $tableRosRegs \terminals\ros\TableRosRegs */
		$tableRosRegs = $this->app()->table('terminals.ros.rosRegs');

		$q [] = 'SELECT * FROM [terminals_ros_rosRegs]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docState] IN %in', [4000, 8000]);
		array_push ($q, ' ORDER BY ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$rosTypeCfg = $this->app()->cfgItem('terminals.ros.types.'.$r['rosType'], NULL);
			if (!$rosTypeCfg)
			{
				$newItem = ['title' => $r['title'], 'vatId' => $r['vatIdPrimary']];
				$newItem['status'] = 'Chybný typ registrace EET';
				$this->badRegs[] = $newItem;
				continue;
			}

			if ($rosTypeCfg['validTo'] !== '0000-00-00')
			{
				$rtValidTo = Utils::createDateTime($rosTypeCfg['validTo']);
				if (Utils::dateIsBlank($r['validTo']) || $r['validTo'] > $rtValidTo)
				{
					$newItem = ['title' => $r['title'], 'vatId' => $r['vatIdPrimary']];
					$newItem['status'] = "Typ EET `{$rosTypeCfg['name']}` je ukončen k  ".Utils::datef($rtValidTo, '%d').'. Ukončete platnost registrace.';
					$newItem['_options'] = ['cellClasses' => ['status' => 'e10-warning1']];
					$this->badRegs[] = $newItem;
					continue;
				}
			}

			$ci = $tableRosRegs->certificateInfo($r);
			if (isset($ci['status']) && $ci['status'])
				continue;

			$newItem = ['title' => $r['title'], 'vatId' => $r['vatIdPrimary']];
			if (isset($ci['validTo']))
				$newItem['certValidTo'] = $ci['validTo'];

			$newItem['status'] = $ci['msg'];

			if (!isset($ci['status']) || !$ci['status'])
			{
				$newItem['_options'] = ['cellClasses' => ['certValidTo' => 'e10-warning1', 'status' => 'e10-warning1']];
			}

			$this->badRegs[] = $newItem;
		}

		if (count($this->badRegs))
		{
			$h = [
				'#' => '#', 'title' => 'Registrace', 'vatId' => 'DIČ',
				'certValidTo' => '_Platnost certifikátu', 'status' => 'Stav'
			];
			$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->badRegs]);

			if ($this->testEngine)
			{
				$this->testEngine->addCycleContent(['type' => 'table', 'header' => $h, 'table' => $this->badRegs]);
			}
		}
		else
			$this->setInfo('note', '1', 'Nebyl nalezen žádný problém');
	}

	public function setTestCycle ($cycle, $testEngine)
	{
		parent::setTestCycle($cycle, $testEngine);
		$this->subReportId = 'ALL';
	}

	public function testTitle ()
	{
		$t = [];
		$t[] = [
			'text' => 'Byly nalezeny problémy s EET',
			'class' => 'subtitle e10-warning1'
		];
		return $t;
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'docs', 'icon' => 'detailReportDocuments', 'title' => 'Doklady'];
		$d[] = ['id' => 'regs', 'icon' => 'detailReportRegistration', 'title' => 'Registrace'];
		return $d;
	}
}
