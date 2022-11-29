<?php

namespace e10doc\templates\libs;

use \Shipard\Base\Utility;
use \e10doc\core\libs\E10Utils, \Shipard\Utils\Utils, e10doc\core\CreateDocumentUtility;
use \e10doc\templates\TableHeads;

/**
 * Class TemplatesScheduler
 */
class TemplatesScheduler extends Utility
{
	public $invHead = [];
	public $invRows = [];
	public $docNote = '';

	var $periodBegin;
	var $periodEnd;
	var $dateAccounting;

	/** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;
	/** @var \e10doc\core\TableRows */
	var $tableDocsRows;
	/** @var \e10doc\templates\TableHeads */
	var $tableTemplatesHeads;

	var $today = NULL;
	var $dateId = '';
	var $maxCheckDays = 10;
	var $checkDirection = -1;
	var $debug = 0;
	var $save = 0;
	var $resetOutbox = 0;
	var $cntGeneratedDocs = 0;

	var $reviewData = [];



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
			$this->invHead ['taxCalc'] = E10Utils::taxCalcIncludingVATCode($this->app(), $this->invHead ['dateAccounting']);

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

		$this->docNote = $contract['docNote'];

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
		if ($item) // TODO: log invalid item
			$r['taxCode'] = $this->tableDocsHeads->taxCode (1, $this->invHead, $item['vatRate']);

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

		if ($contract['creatingDay'] == 0)
		{ // begin
			$this->dateAccounting = clone $this->periodBegin;
			if ($beginDate->format ('Y-m-d') != $today->format ('Y-m-d'))
			{
				return FALSE;
			}
		}
		else
		{ // end
			$this->dateAccounting = clone $this->periodEnd;
			if ($endDate->format ('Y-m-d') != $today->format ('Y-m-d'))
			{
				return FALSE;
			}
		}

		if ($contract['createOffsetValue'] !== 0)
			$this->dateAccounting = Utils::today();

		return TRUE;
	}

	public function generateDate ($today)
	{
		$this->cntGeneratedDocs = 0;
		$this->dateId = $today->format ('Y-m-d');

		$qh = [];
		array_push($qh, 'SELECT * FROM [e10doc_templates_heads] ');
		array_push($qh, ' WHERE docState = %i', 4000);
		array_push($qh, ' AND ([validFrom] <= %d', $today, ' OR [validFrom] IS NULL) AND ([validTo] IS NULL OR [validTo] >= %d)', $today);
		array_push($qh, 'ORDER BY [ndx]');
		$heads = $this->db()->query ($qh);

		$cnt = 0;
		forEach ($heads as $hr)
		{
			$h = $hr->toArray();

			if (!$this->createPeriod ($h, $today))
				continue;

			if (!isset($this->reviewData [$this->dateId]))
			{
				$this->reviewData [$this->dateId] = ['title' => Utils::datef($today), 'templates' => []];
			}
			$reviewItem = ['templateNdx' => $hr['ndx'], 'title' => $hr['title'], 'cntDocs' => 0];
			$this->reviewData [$this->dateId]['templates'][$hr['ndx']] = $reviewItem;

			$generator = NULL;
			if ($hr['templateType'] === TableHeads::ttWOGen)
			{
				$generator = new \e10doc\templates\libs\GeneratorWorkOrders($this->app());
			}
			elseif ($hr['templateType'] === TableHeads::ttTemplateFromExistedDoc)
			{
				$generator = new \e10doc\templates\libs\GeneratorFromExistedDoc($this->app());
			}

			if ($generator)
			{
				$generator->init();
				$generator->setTemplate($hr['ndx']);
				$generator->setScheduler($this);
				$generator->run();
			}

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

	public function reviewContent()
	{
		$t = [];
		foreach ($this->reviewData as $dateId => $dateContent)
		{
			$t[] = [
				'title' => $dateContent['title'],

				'_options' => ['class' => 'subheader'],
			];

			foreach ($dateContent['templates'] as $template)
			{
				$t[] = ['title' => $template['title'], 'cntDocs' => Utils::nf($template['cntDocs'])];
			}
		}

		$h = ['#' => '#', 'title' => 'Název', 'cntDocs' => ' Počet dokladů'];
		$content =
		[
			'pane' => 'e10-pane e10-pane-table',
			'type' => 'table', 'table' => $t, 'header' => $h
		];

		return $content;
		/*
				$this->reviewData [$dateId] = ['title' => $dateId, 'templates' => []];
			}
			$reviewItem = ['templateNdx' => $hr['ndx'], 'title' => $hr['title']];
			$this->reviewData [$dateId]['templates'][] = $reviewItem;
		*/
	}

	public function run ()
	{
		$this->tableDocsHeads = $this->app()->table('e10doc.core.heads');
		$this->tableDocsRows = $this->app()->table('e10doc.core.rows');
		$this->tableTemplatesHeads = $this->app()->table('e10doc.templates.heads');

		if ($this->today === NULL)
			$this->today = Utils::today();

		$today = $this->today;
		$cntTry = 0;
		while (1)
		{
			if ($this->debug)
				echo "--- DATE: ".$today->format ('Y-m-d').": ";

			/*
			$dateId = $today->format ('Y-m-d');
			if (!isset($this->reviewData [$dateId]))
			{
				$this->reviewData [$dateId] = ['title' => Utils::datef($today), 'templates' => []];
			}
			*/

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
