<?php

namespace e10doc\contracts\core\libs;
use e10doc\core\e10utils, \e10\utils, e10doc\core\CreateDocumentUtility;


/**
 * Class ContractsSaleInvoiceGenerator
 * @package e10doc\contracts\core
 */
class ContractsSaleInvoiceGenerator extends \E10\Utility
{
	public $invHead = [];
	public $invRows = [];
	public $invNote = '';

	private $periodBegin;
	private $periodEnd;
	private $dateAccounting;

	/** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;
	/** @var \e10doc\core\TableRows */
	var $tableDocsRows;
	/** @var \e10doc\contracts\core\TableHeads */
	var $tableContractsHeads;

	var $today = NULL;
	var $maxCheckDays = 10;
	var $checkDirection = -1;
	var $debug = 0;
	var $save = 0;
	var $cntGeneratedDocs = 0;

	public function createInvoiceHead ($contract)
	{
		$this->invHead = ['docType' => 'invno', 'contract' => $contract['ndx']];
		$this->tableDocsHeads->checkNewRec($this->invHead);

		$this->invHead ['docType'] = $contract['dstDocType'];

		$this->invHead ['docKind'] = $contract['dstDocKind'];

		$this->invHead ['datePeriodBegin'] = $this->periodBegin;
		$this->invHead ['datePeriodEnd'] = $this->periodEnd;
		$this->invHead ['person'] = $contract['person'];
		$this->invHead ['title'] = $contract['title'];
		$this->invHead ['dateIssue'] = new \DateTime();
		$this->invHead ['dateAccounting'] = $this->dateAccounting;
		$this->invHead ['dateTax'] = $this->dateAccounting;

		$this->invHead ['dateDue'] = new \DateTime ($this->invHead ['dateTax']->format('Y-m-d'));

		$dd = $contract['dueDays'];
		if ($dd === 0)
			$dd = intval($this->app()->cfgItem ('options.e10doc-sale.dueDays', 14));
		if (!$dd)
			$dd = 14;
		$this->invHead ['dateDue']->add (new \DateInterval('P'.$dd.'D'));

		$this->invHead ['currency'] = $contract['currency'];
		$this->invHead ['paymentMethod'] = $contract['paymentMethod'];
		if ($contract['myBankAccount'])
			$this->invHead ['myBankAccount'] = $contract['myBankAccount'];

		if ($contract['taxCalc'] == 0) // price is without VAT
			$this->invHead ['taxCalc'] = 1;
		else // including VAT
			$this->invHead ['taxCalc'] = e10utils::taxCalcIncludingVATCode($this->app(), $this->invHead ['dateAccounting']);

		$this->invHead ['automaticRound'] = intval($this->app()->cfgItem ('options.e10doc-sale.automaticRoundOnSale', 0));
		$this->invHead ['roundMethod'] = intval($this->app()->cfgItem ('options.e10doc-sale.roundInvoice', 0));
		$this->invHead ['author'] = intval($this->app()->cfgItem ('options.e10doc-sale.author', 0));

		$this->invHead ['centre'] = $contract['centre'];
		$this->invHead ['wkfProject'] = $contract['wkfProject'];
		$this->invHead ['workOrder'] = $contract['workOrder'];

		// -- dbCounter: TODO: settings in contract kind
		$dbCounters = $this->app->cfgItem ('e10.docs.dbCounters.'.$this->invHead ['docType'], FALSE);
		$this->invHead ['dbCounter'] = intval(key($dbCounters));
		if (!$this->invHead ['dbCounter'])
			$this->invHead ['dbCounter'] = 1;

		$this->invNote = $contract['invNote'];

		if ($this->debug === 2)
		{
			if (!$this->cntGeneratedDocs)
				echo "\n    | begin        end        | dateIssue  | dateAcc    | dateDue\n";
			//            - 2021-04-01 - 2021-04-30 | 2021-02-05 | 2021-04-01 | 2021-04-01
			echo "    - ".$this->periodBegin->format('Y-m-d').' - '.$this->periodEnd->format('Y-m-d');
			echo " | ".$this->invHead ['dateIssue']->format('Y-m-d')." | ".$this->invHead ['dateAccounting']->format('Y-m-d');
			echo " | ".$this->invHead ['dateDue']->format('Y-m-d');
			echo "\n";
		}

		$this->invRows = [];
		$this->cntGeneratedDocs++;
	}

	public function createInvoiceRow ($contract, $row)
	{
		$r = [];
		$this->tableDocsRows->checkNewRec($r);

		$r['item'] = $row['item'];
		$r['text'] = $row['text'];
		$r['quantity'] = $row['quantity'];
		$r['unit'] = $row['unit'];
		$r['priceItem'] = $row['priceItem'];
		$r['rowOrder'] = (count($this->invRows) + 1) * 100;

		$item = $this->tableDocsRows->loadItem ($row['item'], 'e10_witems_items');
		$r['taxCode'] = $this->tableDocsHeads->taxCode (1, $item['vatRate']);

		$this->invRows[] = $r;
	}

	public function createPeriod ($contract, $todayProposed)
	{
		$today = clone $todayProposed;

		if ($contract['createOffsetValue'] !== 0)
		{
			$intUnit = '';
			if ($contract['createOffsetUnit'] == 0)  // days
				$intUnit = 'D';
			elseif ($contract['createOffsetUnit'] == 1) // months
				$intUnit = 'M';

			if ($intUnit !== '')
			{
				if ($contract['createOffsetValue'] < 0)
					$today->add(new \DateInterval('P' . abs($contract['createOffsetValue']) . $intUnit));
				else
					$today->sub(new \DateInterval('P' . abs($contract['createOffsetValue']) . $intUnit));
			}
		}

		$todayDay = $today->format ('d');
		$todayMonth = intval($today->format ('m'));
		$todayYear = intval($today->format ('Y'));

		$periodMonths = 1;

		switch ($contract['period'])
		{
			case 1: // month
				$periodMonths = 1;
				break;
			case 2: // quarter of the year
				$periodMonths = 3;
				break;
			case 3: // half-year
				$periodMonths = 6;
				break;
			case 4: // year
				$periodMonths = 12;
				break;
		}

		// -- first day
		$beginMonth = intval(floor(($todayMonth - 1) / $periodMonths)) * $periodMonths + 1;
		$endMonth = $beginMonth + $periodMonths - 1;

		$beginDateStr = sprintf ("%04d-%02d-01", $todayYear, $beginMonth);
		$beginDate = new \DateTime ($beginDateStr);

		$endDateStr = sprintf ("%04d-%02d-%02d", $todayYear, $endMonth, cal_days_in_month(CAL_GREGORIAN, $endMonth, $todayYear));
		$endDate = new \DateTime ($endDateStr);

		$this->periodBegin = new \DateTime ($beginDateStr);
		$this->periodEnd = new \DateTime ($endDateStr);

		if ($contract['invoicingDay'] == 0) // begin
		{
			$this->dateAccounting = clone $this->periodBegin;
			if ($beginDate->format ('Y-m-d') != $today->format ('Y-m-d'))
				return FALSE;
		}
		else
		{ // end
			$this->dateAccounting = clone $this->periodEnd;
			if ($endDate->format ('Y-m-d') != $today->format ('Y-m-d'))
				return FALSE;
		}

		if ($contract['createOffsetValue'] !== 0)
			$this->dateAccounting = utils::today();

		return TRUE;
	}

	public function generateDate ($today)
	{
		$this->cntGeneratedDocs = 0;

		$qh = [];
		array_push($qh, 'SELECT * FROM [e10doc_contracts_heads] ');
		array_push($qh, ' WHERE docState = %i', 4000);
		array_push($qh, ' AND ([start] <= %d', $today, ' OR [start] IS NULL) AND ([end] IS NULL OR [end] >= %d)', $today);
		//array_push($qh, '');
		//array_push($qh, '');
		array_push($qh, 'ORDER BY [ndx]');
		$heads = $this->db()->query ($qh);

		$cnt = 0;
		forEach ($heads as $hr)
		{
			$h = $hr->toArray();
			$this->tableContractsHeads->applyContractKind($h);

			if (!$this->createPeriod ($h, $today))
				continue;

			if ($this->invoiceExist($h))
				continue;

			$this->createInvoiceHead ($h);

			$qr = [];
			array_push($qr, 'SELECT * FROM [e10doc_contracts_rows]');
			array_push($qr, ' WHERE contract = %i', $h['ndx']);
			array_push($qr, ' AND ([start] IS NULL OR [start] <= %d', $today, ') AND ([end] IS NULL OR [end] >= %d)', $today);
			//array_push($qr, '');
			//array_push($qr, '');
			array_push($qr, ' ORDER BY [ndx]');
			//array_push($qr, '');

			$rows = $this->db()->query ($qr);
			forEach ($rows as $r)
				$this->createInvoiceRow ($h, $r);

			if ($this->save)
				$this->saveInvoice ($h);
			$cnt++;
		}

		if ($this->debug)
		{
			if ($this->debug > 1 && $cnt)
				echo "      ";
			echo $cnt;
		}

		return $cnt;
	}

	public function invoiceExist ($contract)
	{
		$q = [];
		array_push($q, 'SELECT COUNT(*) AS cnt FROM e10doc_core_heads');
		array_push($q, ' WHERE docType IN %in', ['invno', 'invpo']);
		array_push($q, ' AND docState <= %i', 4000);
		array_push($q, ' AND (datePeriodBegin IS NOT NULL AND datePeriodBegin <= %d)', $this->periodBegin);
		array_push($q, ' AND (datePeriodEnd IS NOT NULL AND datePeriodEnd >= %d)', $this->periodEnd);
		array_push($q, ' AND person = %i', $contract['person']);
		array_push($q, ' AND contract = %i', $contract['ndx']);
		array_push($q, ' ORDER BY [ndx]');
		array_push($q, '');
		$r = $this->db()->query ($q)->fetch();

		//echo (\dibi::$sql . " - ". json_encode ($r) . "\n");

		if ($r['cnt'] > 0)
			return TRUE;

		return FALSE;
	}

	function saveInvoice ($contract)
	{
		$docNdx = $this->tableDocsHeads->dbInsertRec ($this->invHead);
		$this->invHead['ndx'] = $docNdx;

		$f = $this->tableDocsHeads->getTableForm ('edit', $docNdx);

		if ($contract['dstDocState'] == CreateDocumentUtility::sdsConcept)
		{
			$f->recData['docStateMain'] = 0;
			$f->recData['docState'] = 1000;
		}
		elseif ($contract['dstDocState'] == CreateDocumentUtility::sdsConfirmed)
		{
			$f->recData['docStateMain'] = 1;
			$f->recData['docState'] = 1200;
		}
		elseif ($contract['dstDocState'] == CreateDocumentUtility::sdsDone)
		{
			$f->recData['docStateMain'] = 2;
			$f->recData['docState'] = 4000;
		}

		forEach ($this->invRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableDocsRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
		{
			$this->tableDocsHeads->dbUpdateRec($f->recData);
		}
		if ($this->invNote !== '')
		{
			$newProp = [
				'property' => 'note-ext', 'group' => 'notes', 'tableid' => 'e10doc.core.heads',
				'recid' => $docNdx, 'valueMemo' => $this->invNote
			];
			$this->db()->query ('INSERT INTO e10_base_properties', $newProp);
		}

		$this->tableDocsHeads->docsLog ($f->recData['ndx']);
	}

	public function run ()
	{
		$this->tableDocsHeads = $this->app()->table('e10doc.core.heads');
		$this->tableDocsRows = $this->app()->table('e10doc.core.rows');
		$this->tableContractsHeads = $this->app()->table('e10doc.contracts.core.heads');

		if ($this->today === NULL)
			$today = new \DateTime();
		else
			$today = $this->today;
		$cntTry = 0;
		while (1)
		{
			if ($this->debug)
				echo "--- DATE: ".$today->format ('Y-m-d').": ";

			$this->generateDate ($today);

			if ($this->debug)
				echo "\n";

			if ($this->checkDirection === -1)
				$today->sub (new \DateInterval('P1D'));
			else
				$today->add (new \DateInterval('P1D'));
			$cntTry++;

			if ($cntTry > $this->maxCheckDays)
				break;
		}
	}
}
