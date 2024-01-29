<?php

namespace e10pro\soci\libs;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Str;

/**
 * class EntriesInvoicingEngine
 */
class EntriesInvoicingEngine extends \Shipard\Base\Utility
{
	var $entryNdx = 0;
	var $entryRecData = NULL;
	var $periodNdx = 0;
	var $periodCfg = NULL;
	var $periodBegin;
	var $periodEnd;
	var $periodHalf;

	var $workOrderRecData = NULL;

	var $invHead = [];
	var $invRows = [];


	/** @var \e10pro\soci\TableEntries */
	var $tableEntries;
	/** @var \E10Doc\Core\TableHeads */
	var $tableHeads;
	/** @var \E10Doc\Core\TableRows */
	var $tableRows;


	var $existedInvoicesTable;
	var $existedInvoicesHead = [
		'docNumber' => 'Doklad',
		'symbol1' => 'VS',
		'symbol2' => 'SS',
		'datePeriodBegin' => 'Období od',
		'datePeriodEnd' => 'Období do',
		'dateIssue' => 'Vystaveno',
		'price' => '+Cena',
	];

	var $planInvoicesTable;
	var $planInvoicesHead = [
		'symbol1' => 'VS',
		'symbol2' => 'SS',
		'datePeriodBegin' => 'Období od',
		'datePeriodEnd' => 'Období do',
		'dateAccounting' => 'Účetní datum',
		'itemsCell' => 'Položky',
		'priceAll' => '+Cena',
	];

	public function init()
	{
		$this->tableEntries = $this->app()->table('e10pro.soci.entries');
		$this->tableHeads = $this->app()->table('e10doc.core.heads');
		$this->tableRows = $this->app()->table('e10doc.core.rows');
	}

	function createHead ($invoice)
	{
		$this->invHead = ['docType' => 'invno'];
		$this->tableHeads->checkNewRec($this->invHead);

		$this->invHead ['docState'] = 4000;
		$this->invHead ['docStateMain'] = 2;

		$this->invHead ['docType'] = 'invno';
		$this->invHead ['dbCounter'] = 1;
		$this->invHead ['datePeriodBegin'] = $invoice['datePeriodBegin'];
		$this->invHead ['datePeriodEnd'] = $invoice['datePeriodEnd'];
		$this->invHead ['person'] = $this->entryRecData['dstPerson'];
		$this->invHead ['workOrder'] = $this->entryRecData['entryTo'];

		$this->invHead ['dateIssue'] = $invoice['dateAccounting'];
		$this->invHead ['dateTax'] = $invoice['dateAccounting'];
		$this->invHead ['dateAccounting'] = $invoice['dateAccounting'];
		$this->invHead ['dateDue'] = Utils::createDateTime($invoice['dateAccounting']);
		$this->invHead ['dateDue']->add (new \DateInterval('P30D'));

		$this->invHead ['symbol1'] = $invoice['symbol1'];
		$this->invHead ['symbol2'] = $invoice['symbol2'];

		$this->invHead ['paymentMethod'] = '0';
		$this->invHead ['roundMethod'] = intval($this->app->cfgItem ('options.e10doc-sale.roundInvoice', 0));
		$this->invHead ['author'] = intval($this->app->cfgItem ('options.e10doc-sale.author', 0));
		$this->invHead ['title'] = Str::upToLen($invoice['items'][0]['text'] ?? '', 120);

		$this->invRows = [];
	}

	function createRow ($row)
	{
		$r = [];
		$this->tableRows->checkNewRec($r);

		$r['item'] = $row['item'];
		$r['text'] =  $row['text'];
		$r['quantity'] = 1;
		$r['operation'] = 1010001;
		$r['priceItem'] = $row['priceAll'];

		$this->invRows[] = $r;
	}

	function saveInvoice ()
	{
		$docNdx = $this->tableHeads->dbInsertRec ($this->invHead);
		$this->invHead['ndx'] = $docNdx;

		$f = $this->tableHeads->getTableForm ('edit', $docNdx);

		forEach ($this->invRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$this->tableHeads->dbUpdateRec ($f->recData);

		$f->checkAfterSave();
		$this->tableHeads->checkDocumentState ($f->recData);
		$this->tableHeads->dbUpdateRec ($f->recData);
		$this->tableHeads->checkAfterSave2 ($f->recData);
		$this->tableHeads->docsLog($f->recData['ndx']);
	}

	public function setEntry($entryNdx)
	{
		$this->entryNdx = $entryNdx;
		$this->entryRecData = $this->tableEntries->loadItem($this->entryNdx);
		$this->workOrderRecData = $this->app()->loadItem($this->entryRecData['entryTo'], 'e10mnf.core.workOrders');
		$this->periodNdx = $this->entryRecData['entryPeriod'];
		$this->periodCfg = $this->app()->cfgItem('e10pro.soci.periods.'.$this->periodNdx, NULL);

		if (!Utils::dateIsBlank($this->entryRecData['datePeriodBegin']))
			$this->periodBegin = Utils::createDateTime($this->entryRecData['datePeriodBegin']);
		else
			$this->periodBegin = Utils::createDateTime($this->periodCfg['dateBegin']);

		if (!Utils::dateIsBlank($this->entryRecData['datePeriodEnd']))
			$this->periodEnd = Utils::createDateTime($this->entryRecData['datePeriodEnd']);
		else
			$this->periodEnd = Utils::createDateTime($this->periodCfg['dateEnd']);

		$this->periodHalf = Utils::createDateTime($this->periodCfg['dateHalf']);
	}

	public function loadInvoices()
	{
		$this->existedInvoicesTable = [];

		$q = [];
		array_push($q, 'SELECT heads.*');
		array_push($q, ' FROM e10doc_core_heads AS heads');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND workOrder = %i', $this->entryRecData['entryTo']);
		array_push($q, ' AND person = %i', $this->entryRecData['dstPerson']);
		array_push($q, ' ORDER BY docNumber');

		$rows = $this->db()->query($q);
		foreach($rows as $r)
		{
			$ti = [
				'docNumber' => ['text' => $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['ndx']],
				'symbol1' => $r['symbol1'],
				'symbol2' => $r['symbol2'],
				'dateAccounting' => $r['dateAccounting'],
				'dateIssue' => $r['dateIssue'],
				'datePeriodBegin' => $r['datePeriodBegin'],
				'datePeriodEnd' => $r['datePeriodEnd'],
				'price' => $r['sumTotal']
			];

			$this->existedInvoicesTable[] = $ti;
		}
	}

	public function planInvoices()
	{
		$this->planInvoicesTable = [];

		if ($this->entryRecData['paymentPeriod'] === 0)
		{ // full
			$this->planInvoice(0);
		}
		elseif ($this->entryRecData['paymentPeriod'] === 1)
		{ // halfs
			$this->planInvoice(1);
			$this->planInvoice(2);
		}
	}

	public function planInvoice($entryPeriod)
	{
		$pi = [
			'priceAll' => 0.0
		];

		if ($entryPeriod === 0)
		{
			$pi['dateAccounting'] = Utils::createDateTime($this->periodBegin);
			$pi['datePeriodBegin'] = Utils::createDateTime($this->periodBegin);
			$pi['datePeriodEnd'] = Utils::createDateTime($this->periodEnd);
		}
		elseif ($entryPeriod === 1)
		{ // first half
			$endFirstHalfPeriod = Utils::createDateTime($this->periodCfg['dateHalf']);
			$endFirstHalfPeriod->sub(new \DateInterval('P1D'));
			if ($this->periodBegin > $endFirstHalfPeriod)
				return;

			$pi['dateAccounting'] = Utils::createDateTime($this->periodBegin);
			$pi['datePeriodBegin'] = Utils::createDateTime($this->periodBegin);
			$pi['datePeriodEnd'] = Utils::createDateTime($this->periodHalf);
			$pi['datePeriodEnd']->sub(new \DateInterval('P1D'));
		}
		elseif ($entryPeriod === 2)
		{ // second half
			$pi['dateAccounting'] = Utils::createDateTime($this->periodHalf);
			$pi['datePeriodBegin'] = Utils::createDateTime($this->periodHalf);
			$pi['datePeriodEnd'] = Utils::createDateTime($this->periodEnd);
		}

		$pi['symbol1'] = $this->entryRecData['docNumber'];
		$pi['symbol2'] = substr($this->periodCfg['dateBegin'], 2, 2).substr($this->periodCfg['dateEnd'], 2, 2);
		if ($entryPeriod)
			$pi['symbol2'] .= $entryPeriod;

		$this->planInvoiceItems($pi, $entryPeriod);
		if (!$this->searchExistedIncoice($pi))
			$this->planInvoicesTable[] = $pi;
	}

	public function planInvoiceItems(&$invoice, $entryPeriod)
	{
		$periodTitle = ' '.substr($this->periodCfg['dateBegin'], 0, 4).'/'.substr($this->periodCfg['dateEnd'], 2, 2);
		if ($entryPeriod)
			$periodTitle .= ' '.$entryPeriod.'. pol.';

		$q = [];
		array_push($q, 'SELECT [ei].* ');
		array_push($q, ' FROM [e10pro_soci_entriesInvoicing] AS [ei]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [entryKind] = %i', $this->entryRecData['entryKind']);
		array_push($q, ' AND [saleType] = %i', $this->entryRecData['saleType']);
		array_push($q, ' AND [paymentPeriod] = %i', $this->entryRecData['paymentPeriod']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$invoice['items'][] = [
				'item' => $r['item'], 'priceAll' => $r['priceAll'],
				'text' => $r['fullName']." '".$this->workOrderRecData['title']."'".$periodTitle,
			];
			$invoice['itemsCell'][] = ['text' => $r['fullName'], 'class' => 'block'];
			$invoice['priceAll'] += $r['priceAll'];
		}
	}

	protected function searchExistedIncoice($pi)
	{
		foreach ($this->existedInvoicesTable as $ei)
		{
			if ($pi['symbol1'] !== $ei['symbol1'])
				continue;
			if ($pi['symbol2'] !== $ei['symbol2'])
				continue;

			return TRUE;
		}
		return FALSE;
	}

	public function generatePlan()
	{
		foreach ($this->planInvoicesTable as $pi)
		{
			$this->createHead($pi);
			foreach ($pi['items'] as $piRow)
			{
				$this->createRow($piRow);
			}

			$this->saveInvoice();
		}
	}

	public function generateAll()
	{
		$q = [];
		array_push($q, 'SELECT [entries].* ');
		array_push($q, ' FROM [e10pro_soci_entries] AS [entries]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [entries].[docState] = %i', 4000);
		array_push($q, ' AND [entries].[entryState] = %i', 0);
		array_push($q, ' ORDER BY ndx DESC');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->setEntry($r['ndx']);
			$this->loadInvoices();
			$this->planInvoices();

			if (!count($this->planInvoicesTable))
				continue;

			echo "# ".$r['docNumber'].' - '.$r['firstName'].' '.$r['lastName']."\n";

			$this->generatePlan();

			$cnt++;

			if ($cnt > 20)
				break;
		}
	}
}
