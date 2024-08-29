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
	var $entryKindCfg;
	var $periodBegin;
	var $periodEnd;
	var $periodHalf;

	var $thisMonth = 0;

	var $forPrint = 0;

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
		'docNumber' => '_Doklad',
		'symbol1' => 'VS',
		'symbol2' => 'SS',
		'datePeriodBegin' => 'Období od',
		'datePeriodEnd' => 'Období do',
		'dateIssue' => 'Vystaveno',
		'price' => '+Cena',
		'bi' => '_Stav'
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

		$today = Utils::today();
		$this->thisMonth = intval($today->format('m'));
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

		$this->invHead ['symbol1'] = $invoice['symbol1'] ?? '';
		$this->invHead ['symbol2'] = $invoice['symbol2'] ?? '';

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
		$this->entryKindCfg = $this->app()->cfgItem('e10pro.soci.entriesKinds.'.$this->entryRecData['entryKind']);
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
			$docStates = $this->tableHeads->documentStates ($r);
			$docStateIcon = $this->tableHeads->getDocumentStateInfo ($docStates, $r, 'styleIcon');
			$docStateClass = $this->tableHeads->getDocumentStateInfo ($docStates, $r, 'styleClass');

			$ti = [
				'docNumber' => ['text' => $r['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['ndx'], 'icon' => $docStateIcon],
				'symbol1' => $r['symbol1'],
				'symbol2' => $r['symbol2'],
				'dateAccounting' => $r['dateAccounting'],
				'dateIssue' => $r['dateIssue'],
				'datePeriodBegin' => $r['datePeriodBegin'],
				'datePeriodEnd' => $r['datePeriodEnd'],
				'price' => $r['sumTotal']
			];
			$ti ['_options']['cellClasses']['docNumber'] = $docStateClass;

			$bi = $this->balanceInfo ($r);
			$ti['bi'] = $bi;
			$ti ['_options']['cellClasses']['bi'] = $bi['class'];

			$this->existedInvoicesTable[] = $ti;
		}
	}

	public function planInvoices()
	{
		$this->planInvoicesTable = [];

		$saleType = $this->app()->cfgItem('e10pro.soci.saleTypes.'.$this->entryRecData['saleType'], NULL);
		if (intval($saleType['disableInvoicing'] ?? 0))
			return;

		if (intval($this->entryKindCfg['usePeriods'] ?? 0) === 1)
		{ // YES, entry for period(s)
			if ($this->entryRecData['paymentPeriod'] === 0)
			{ // full
				$this->planInvoicePeriod(0);
			}
			elseif ($this->entryRecData['paymentPeriod'] === 1)
			{ // halfs
				if ($this->thisMonth > 7)
					$this->planInvoicePeriod(1);
				if ($this->thisMonth < 8)
					$this->planInvoicePeriod(2);
			}
		}
		else
		{ // event
			$this->planInvoiceEvent();
		}
	}

	public function planInvoicePeriod($entryPeriod)
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

		$this->planInvoiceItemsPeriod($pi, $entryPeriod);
		if (!$this->searchExistedInvoicePeriod($pi))
			$this->planInvoicesTable[] = $pi;
	}

	public function planInvoiceItemsPeriod(&$invoice, $entryPeriod)
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
		array_push($q, ' AND (');
		array_push($q, ' [entryTo] = %i', $this->entryRecData['entryTo']);
		array_push($q, ' OR [entryTo] = %i', 0);
		array_push($q, ')');
		array_push($q, ' ORDER BY [entryTo] DESC');
		array_push($q, ' LIMIT 1');

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

	public function planInvoiceItemsEvent(&$invoice)
	{
		if (!$this->entryRecData['item1'])
			return;

		$itemNdx = $this->entryRecData['item1'];
		$itemRecData = $this->app()->loadItem($itemNdx, 'e10.witems.items');
		if ($itemRecData)
		{
			$invoice['items'][] = [
				'item' => $itemNdx, 'priceAll' => $itemRecData['priceSellTotal'],
				'text' => $this->workOrderRecData['title'].': '.$itemRecData['fullName'],
			];
			$invoice['itemsCell'][] = ['text' => $itemRecData['fullName'], 'suffix' => $itemRecData['id'], 'class' => 'block'];
			$invoice['priceAll'] += $itemRecData['priceSellTotal'];
		}
	}

	public function planInvoiceEvent()
	{
		$pi = [
			'priceAll' => 0.0
		];

		$pi['dateAccounting'] = Utils::createDateTime($this->workOrderRecData['dateBegin'] ?? Utils::today());
		$pi['datePeriodBegin'] = Utils::createDateTime($this->workOrderRecData['dateBegin']);
		$pi['datePeriodEnd'] = Utils::createDateTime($this->workOrderRecData['dateClosed']);

		$this->planInvoiceItemsEvent($pi);

		if (!$this->searchExistedInvoiceEvent())
			$this->planInvoicesTable[] = $pi;
	}

	protected function searchExistedInvoicePeriod($pi)
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

	protected function searchExistedInvoiceEvent()
	{
		foreach ($this->existedInvoicesTable as $ei)
		{
			return TRUE;
		}
		return FALSE;
	}

	protected function balanceInfo ($item)
	{
		$bi = new \e10doc\balance\BalanceDocumentInfo($this->app());
		$bi->setDocRecData ($item);
		$bi->run ();

		if (!$bi->valid)
			return;

    $balanceInfo = [];

		$line = [];
		$line[] = ['text' => utils::datef($item['dateDue']), 'icon' => 'system/iconStar'];

		if ($bi->restAmount < -1.0)
		{
			$balanceInfo['text'] = 'Přeplatek: '.Utils::nf(- $bi->restAmount, 2);
      $balanceInfo['icon'] = 'system/iconCheck';
      $balanceInfo['class'] = 'e10-warning1';
		}
		elseif ($bi->restAmount < 1.0)
		{
			$balanceInfo['text'] = 'Uhrazeno';
			if (!$this->forPrint && isset($bi->lastPayment['date']) && !Utils::dateIsBlank($bi->lastPayment['date']))
				$balanceInfo['suffix'] = Utils::datef($bi->lastPayment['date'], '%S');
      $balanceInfo['icon'] = 'system/iconCheck';
      $balanceInfo['class'] = 'e10-bg-t1';
		}
		else
    {
			if ($bi->restAmount == $item['toPay'])
			{
        if ($bi->daysOver > 0)
        {
          $balanceInfo['text'] = 'NEUHRAZENO';
          $balanceInfo['icon'] = 'system/iconWarning';
          $balanceInfo['class'] = 'e10-warning3';
        }
        else
        {
          $balanceInfo['text'] = 'Uhradit do: '.Utils::datef($item['dateDue'], '%S');
          $balanceInfo['icon'] = 'system/iconCheck';
          $balanceInfo['class'] = 'e10-bg-t4';
        }
			}
			else
			{
        $balanceInfo['text'] = 'NEDOPLATEK: '.Utils::nf($bi->restAmount, 2);
        $balanceInfo['icon'] = 'system/iconCheck';
        $balanceInfo['class'] = 'e10-warning1';
			}
    }

    return $balanceInfo;
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

	public function generateAll($entryNdx = 0, $entryToNdx = 0)
	{
		$q = [];
		array_push($q, 'SELECT [entries].*, [persons].fullName AS [personName]');
		array_push($q, ' FROM [e10pro_soci_entries] AS [entries]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [entries].[dstPerson] = [persons].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [entries].[docState] = %i', 4000);
		array_push($q, ' AND [entries].[entryState] = %i', 0);
		array_push($q, ' AND [entries].[dstPerson] != %i', 0);
		if ($entryNdx)
			array_push($q, ' AND [entries].[ndx] = %i', $entryNdx);
		if ($entryToNdx)
			array_push($q, ' AND [entries].[entryTo] = %i', $entryToNdx);
		array_push($q, ' ORDER BY ndx DESC');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $saleType = $this->app()->cfgItem('e10pro.soci.saleTypes.'.$r['saleType'], NULL);
      if (intval($saleType['disableInvoicing'] ?? 0))
        continue;

			$this->setEntry($r['ndx']);
			$this->loadInvoices();
			$this->planInvoices();

			if (!count($this->planInvoicesTable))
				continue;

			if ($this->app()->debug)
				echo "# ".$r['docNumber'].' - '.$r['personName']."\n";

			$this->generatePlan();

			$cnt++;

			if ($this->app()->debug && $cnt > 20)
				break;
		}
	}
}
