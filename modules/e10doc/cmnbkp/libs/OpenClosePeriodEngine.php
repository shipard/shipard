<?php

namespace e10doc\cmnbkp\libs;
use e10\utils;


/**
 * Class OpenClosePeriodEngine
 * @package e10doc\cmnbkp\libs
 */
class OpenClosePeriodEngine extends \E10\Utility
{
	var $closeDocs = FALSE;
	var $fiscalYear;
	var $fiscalYearCfg;
	/** @var \e10doc\core\TableHeads */
	var $tableDocs;
	/** @var \e10doc\core\TableRows */
	var $tableRows;

	var $isOpening;
	var $statesFiscalYear;
	var $statesFiscalPeriod;

	var $totals = [];

	function createDocHead ($accountKind)
	{
		$docSubtitles = [0 => 'Aktiva', 1 => 'Pasiva', 2 => 'Náklady', 3 => 'Výnosy', 999 => 'Hospodářský výsledek'];
		if ($this->isOpening)
		{
			$linkId = "OPENACCPER;{$this->fiscalYear};$accountKind";
			$title = 'Otevření účetního období '.$this->fiscalYearCfg['fullName'].' - '.$docSubtitles[$accountKind];
			$docDate = $this->fiscalYearCfg['begin'];
		}
		else
		{
			$linkId = "CLOSEACCPER;{$this->fiscalYear};$accountKind";
			$title = 'Uzavření účetního období '.$this->fiscalYearCfg['fullName'].' - '.$docSubtitles[$accountKind];
			$docDate = $this->fiscalYearCfg['end'];
		}

		$q = 'SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s AND [linkId] = %s AND docState != 9800';
		$existedDocs = $this->db()->query ($q, 'cmnbkp', $linkId)->fetch();
		if ($existedDocs && $existedDocs['docState'] === 4000)
		{
			return FALSE;
		}

		if ($existedDocs && ($existedDocs['docState'] === 1000 || $existedDocs['docState'] === 1200 || $existedDocs['docState'] === 8000))
		{ // new/confirmed/edited
			$docH = $existedDocs->toArray ();
		}
		else
		{
			$docH = array ();
			$docH ['docType'] = 'cmnbkp';
			$this->tableDocs->checkNewRec ($docH);
		}

		// dbcounter id
		$dbCounter = $this->dbCounter();
		if (!$dbCounter)
		{
			error_log ("ERROR - InitStatesBalanceEngine: dbCounter not found.");
			return FALSE;
		}

		// docKind
		$docKinds = $this->app->cfgItem ('e10.docs.kinds', FALSE);
		if ($this->isOpening)
			$dk = utils::searchArray($docKinds, 'activity', 'ocpOpen');
		else
			$dk = utils::searchArray($docKinds, 'activity', 'ocpClose');

		$docH ['dateAccounting']		= $docDate;
		$docH ['dateIssue']					= $docDate;
		$docH ['person'] 						= $docH ['owner'];
		$docH ['title'] 						= $title;
		$docH ['taxCalc']						= 0;
		$docH ['currency']					= utils::homeCurrency($this->app, $docDate);
		$docH ['dbCounter']					= $dbCounter;
		$docH ['initState']					= 0;
		$docH ['linkId']						= $linkId;
		$docH ['docKind']						= $dk['ndx'];
		$docH ['activity']					= $dk['activity'];

		return $docH;
	}

	function appendDocRows ($statesRows, $accountKind, &$newRows)
	{
		$total = 0.0;

		forEach ($statesRows as $r)
		{
			if (!isset($r['accountKind']))
				continue;
			if ($r['accountKind'] != $accountKind)
				continue;

			if ($this->isOpening && isset($r['toBalance']))
				continue;

			if (round($r['endState'], 2) == 0.0)
				continue;

			$newRow = array ();
			if (!isset($r['debsAccountId']))
				$r['debsAccountId'] = '';

			$newRow ['operation'] = 1099999;
			$newRow ['debsAccountId'] = $r['accountId'];
			$newRow ['text'] = $r['title'];
			$newRow ['quantity'] = 1;
			$newRow ['priceItem'] = $r['endState'];

			$newRow ['credit'] = 0.0;
			$newRow ['debit'] = 0.0;

			if ($this->isOpening)
			{
				if ($accountKind == 0)
					$newRow ['debit'] = $r['endState'];
				else
					$newRow ['credit'] = -$r['endState'];
			}
			else
			{
				if ($accountKind == 0 || $accountKind == 2)
					$newRow ['credit'] = $r['endState'];
				else
					$newRow ['debit'] = -$r['endState'];
			}

			$total += $r['endState'];

			$newRows[] = $newRow;
		}

		return $total;
	}

	function createDocRows ($head, $accountKind)
	{
		$glr = new \e10doc\debs\libs\reports\GeneralLedger ($this->app);
		$glr->fiscalYear = $this->statesFiscalYear;
		$glr->fiscalPeriod = $this->statesFiscalPeriod;
		$statesRows = $glr->createContent_Data();

		$docRows = [];

		if ($this->isOpening)
		{
			$total = $this->appendDocRows ($statesRows, $accountKind, $docRows);
			if ($accountKind === 0)
			{ // aktiva
				$closeRow = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('701'), 'text' => 'Otevření účetního období - Aktiva',
					'credit' => $total, 'debit' => 0];
			}
			else
			{ // pasiva
				$closeRow = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('701'), 'text' => 'Otevření účetního období - Pasiva',
					'credit' => 0, 'debit' => -$total];
			}
			$docRows[] = $closeRow;
		}
		else
		{
			$total = $this->appendDocRows ($statesRows, $accountKind, $docRows);
			switch ($accountKind)
			{
				case 0: $closeRow = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('702'), 'text' => 'Aktiva',
					'credit' => 0, 'debit' => $total]; break;
				case 1: $closeRow = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('702'), 'text' => 'Pasiva',
					'credit' => -$total, 'debit' => 0]; break;
				case 2: $closeRow = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('710'), 'text' => 'Náklady',
					'credit' => 0, 'debit' => $total]; break;
				case 3: $closeRow = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('710'), 'text' => 'Výnosy',
					'credit' => -$total, 'debit' => 0]; break;
			}
			$docRows[] = $closeRow;
		}
		$this->totals[$accountKind] = $total;

		return $docRows;
	}

	function setParams ($fiscalYear, $isOpening)
	{
		$this->fiscalYear = intval($fiscalYear);
		$this->fiscalYearCfg = $this->app->cfgItem ('e10doc.acc.periods.'.$fiscalYear);
		$this->isOpening = $isOpening;

		// -- search fiscal year and period for source states
		$this->statesFiscalYear = $this->fiscalYear;
		if ($this->isOpening)
			$this->statesFiscalYear = $this->fiscalYearCfg['prevNdx'];

		$period = $this->app->db()->query('SELECT * FROM [e10doc_base_fiscalmonths] WHERE fiscalType = 0 AND fiscalYear = %i ORDER BY [globalOrder] DESC',
			$this->statesFiscalYear)->fetch();
		if ($period['ndx'])
			$this->statesFiscalPeriod = $period['ndx'];
	}

	function run ()
	{
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);

		$this->app->db->begin();

		if ($this->isOpening)
		{
			$this->createDocument(0); // aktiva
			$this->createDocument(1); // pasiva
		}
		else
		{
			$this->createDocument(2); // náklady
			$this->createDocument(3); // výnosy

			$this->createDocument(999); // zisk/ztráta

			$this->createDocument(0); // aktiva
			$this->createDocument(1); // pasiva
		}

		$this->app->db->commit();
	}

	protected function createDocument ($accountKind)
	{
		$docHead = $this->createDocHead ($accountKind);
		if ($docHead === FALSE)
			return;

		if ($accountKind === 999)
		{
			$amount = $this->totals[2] + $this->totals[3];
			$docRows = [];
			if ($amount < 0)
			{
				$docRows[] = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('710'),
					'text' => 'Zúčtování zisku', 'debit' => -$amount, 'credit' => 0];
				$docRows[] = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('702'),
					'text' => 'Zúčtování zisku', 'debit' => 0, 'credit' => -$amount];
			}
			else
			{
				$docRows[] = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('710'),
					'text' => 'Zúčtování ztráty', 'debit' => 0, 'credit' => $amount];
				$docRows[] = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('702'),
					'text' => 'Zúčtování ztráty', 'debit' => $amount, 'credit' => 0];
			}
		}
		else
		{
			if ($this->isOpening && $accountKind == 1)
			{
				$docRows = $this->createDocRows ($docHead, 2);
				$docRows = $this->createDocRows ($docHead, 3);
				unset ($docRows);
			}
			$docRows = $this->createDocRows ($docHead, $accountKind);
			if ($this->isOpening && $accountKind == 1)
			{
				$amount = $this->totals[2] + $this->totals[3];
				$docRows[] = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('431'),
					'text' => 'Výsledek hospodaření ve schvalovacím řízení', 'debit' => 0, 'credit' => -$amount];
				$docRows[] = ['operation' => 1099999, 'debsAccountId' => $this->searchAccountFromMask ('701'),
					'text' => 'Výsledek hospodaření ve schvalovacím řízení', 'debit' => -$amount, 'credit' => 0];
			}
		}
		$this->save ($docHead, $docRows);
	}

	protected function save ($head, $rows)
	{
		if (!isset ($head['ndx']))
		{
			$docNdx = $this->tableDocs->dbInsertRec ($head);
		}
		else
		{
			$docNdx = $head['ndx'];
			$this->db()->query ('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $docNdx);
			$this->tableDocs->dbUpdateRec ($head);
		}

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);
		if ($f->checkAfterSave())
			$this->tableDocs->dbUpdateRec ($f->recData);

		forEach ($rows as $row)
		{
			$row['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($row, $f->recData);
		}

		if ($this->closeDocs)
		{
			$f->recData ['docState'] = 4000;
			$f->recData ['docStateMain'] = 2;
			$this->tableDocs->checkDocumentState($f->recData);
		}
		$f->checkAfterSave();
		$this->tableDocs->dbUpdateRec ($f->recData);
		$this->tableDocs->checkAfterSave2 ($f->recData);
		$this->tableDocs->docsLog($f->recData['ndx']);
	}

	protected function searchAccountFromMask ($accountMask)
	{
		$row = $this->db()->query ("SELECT * FROM [e10doc_debs_accounts] WHERE [accGroup] = 0 AND [id] LIKE %s ORDER by id, ndx", $accountMask.'%')->fetch();
		if ($row)
			return $row['id'];

		return $accountMask.str_repeat('9', 6 - strlen ($accountMask));
	}

	protected function dbCounter()
	{
		if ($this->isOpening)
			$dbCounter = $this->db()->query('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s', 'cmnbkp',
				' AND [docKeyId] = %s', '9', ' AND docState != %i', 9800)->fetch();
		else
			$dbCounter = $this->db()->query('SELECT * FROM [e10doc_base_docnumbers] WHERE [docType] = %s', 'cmnbkp',
				' AND [docKeyId] = %s', 'X', ' AND docState != %i', 9800)->fetch();

		if (!isset ($dbCounter['ndx']))
			return 0;

		return $dbCounter['ndx'];
	}
}
