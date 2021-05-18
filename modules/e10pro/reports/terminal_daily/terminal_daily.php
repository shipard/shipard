<?php

namespace E10Pro\Reports\Terminal_daily;

use E10Doc\Core\e10utils, E10\utils;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


/**
 * reportDaily
 *
 */

class reportDaily extends \e10doc\core\libs\reports\GlobalReport
{
	var $currencies;
	var $unitNdx;
	var $todayDate;
	var $docTypes;
	var $numDays = 14;
	var $startDate;

	var $dataSales = array();
	var $dataPurchases = array();
	var $dataCash = array();
	var $dataDaily = array();

	var $dailyCashSales = 0.0;
	var $dailyCashPurchases = 0.0;
	var $dailyCashIn = 0.0;
	var $dailyCashOut = 0.0;
	var $dailyTotalSale = 0.0;
	var $dailyTotalPurchase = 0.0;

	function init ()
	{
		$this->startDate = utils::today();

		$this->addParamDays ();
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				$this->addParam('cashBox'); break;
			case 'authors':
				$this->addParamAuthors(); break;
		}

		parent::init();

		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');
		$this->todayDate = utils::createDateTime($this->reportParams ['days']['value']);

		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				$this->unitNdx = $this->reportParams ['cashBox']['value']; break;
			case 'authors':
				$this->unitNdx = $this->reportParams ['author']['value']; break;
		}
	}

	protected function addParamDays ()
	{
		$days = array();

		for ($i = 0; $i < $this->numDays; $i++)
		{
			$newDate = new \DateTime ($this->startDate->format('Y-m-d'));
			$newDate->sub (new \DateInterval('P'.$i.'D'));

			$dateValue = $newDate->format ('Y-m-d');
			if ($i === 0)
				$dateTitle = 'Dnes';
			else
				if ($i === 1)
					$dateTitle = 'Včera';
				else
					$dateTitle = $newDate->format ('d.m.Y');
			$days[$dateValue] = $dateTitle;
		}

		$this->addParam('switch', 'days', array ('title' => 'Den', 'switch' => $days));
	}

	protected function addParamAuthors ()
	{
		$authors = array();

		$lastDate = new \DateTime ($this->startDate->format('Y-m-d'));
		$lastDate->sub (new \DateInterval('P'.$this->numDays.'D'));

		$q[] = 'SELECT heads.[author] as author, persons.[fullName] as fullName';
		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.author = persons.ndx');
		array_push ($q, ' WHERE heads.docType IN %in',  array ('invno', 'cash', 'cashreg'));
		array_push ($q, ' AND heads.dateAccounting >= %d', $lastDate);
		array_push ($q, ' ORDER BY fullName');

		$rows = $this->app->db()->query($q);
		$blankAuthor = FALSE;
		foreach ($rows as $r)
		{
			if ($r['author'] != 0)
				$authors[$r['author']] = $r['fullName'];
			else
				$blankAuthor = TRUE;
		}
		if ($blankAuthor)
			$authors[0] = '-- neuvedeno --';

		$this->addParam('switch', 'author', array ('title' => 'Uživatel', 'switch' => $authors));
	}

	function createContent ()
	{
		$this->setInfo('title', 'Denní přehled');
		$this->setInfo('icon', 'icon-check-circle');
		$this->setInfo('param', 'Datum', utils::datef ($this->todayDate, '%d'));
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				$this->setInfo('param', 'Pokladna', $this->reportParams ['cashBox']['activeTitle']);
				$this->setInfo('saveFileName', 'Denní přehled '.$this->reportParams ['cashBox']['activeTitle'].' '.$this->todayDate->format('Y-m-d'));
				break;
			case 'authors':
				$this->setInfo('param', 'Uživatel', $this->reportParams ['author']['activeTitle']);
				$this->setInfo('saveFileName', 'Denní přehled '.$this->reportParams ['author']['activeTitle'].' '.$this->todayDate->format('Y-m-d'));
				break;
		}
		$this->setInfo('note', '1', 'Všechny částky jsou uvedeny včetně DPH.');

		$this->createContent_Docs ();
		$this->createContent_Cash ();
		$this->createContent_DailyStats ();

		if ($this->subReportId == '' || $this->subReportId == 'cashboxes')
		{
			$title = 'Rekapitulace hotovosti';
			$h = array('txt' => 'Text', 'value' => ' Hodnota');
			$this->addContent(array('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $this->dataDaily, 'params' => ['hideHeader' => 1]));
		}

		if (count($this->dataSales))
		{
			$title = 'Prodeje';
			$h = array ('#' => '#', 'docType' => 'DD', 'docNumber' => 'Doklad', 'personFullName' => 'Zákazník', 'title' => 'Pozn.',
									'toPayCash' => '+Hotově', 'toPayCard' => '+Kartou', 'toPayBank' => '+Převodem', 'toPayCOD' => '+Dobírkou');
			$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $this->dataSales, 'main' => TRUE));

			$totalSale[] = ['text' => 'Celkem', 'class' => 'padd5 h2'];
			$totalSale[] = ['text' => utils::nf ($this->dailyTotalSale, 2), 'class' => 'pull-right padd5 h2'];
			$this->addContent (['type' => 'line', 'line' => $totalSale]);
		}

		if (count($this->dataPurchases))
		{
			$title = 'Výkupy';
			$h = array ('#' => '#', 'docType' => 'DD', 'docNumber' => 'Doklad', 'personFullName' => 'Zákazník',
									'toPayCash' => '+Hotově', 'toPayCheque' => '+Šekem', 'toPayPostalOrder' => '+Pošt. p.',
									'toPayBankOrder' => '+Převodem', 'toPayBatch' => '+Sběrný lístek', 'toPayInvoice' => '+Fakturou');
			$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $this->dataPurchases, 'main' => TRUE));

			$totalPurchase[] = ['text' => 'Celkem', 'class' => 'padd5 h2'];
			$totalPurchase[] = ['text' => utils::nf ($this->dailyTotalPurchase, 2), 'class' => 'pull-right padd5 h2'];
			$this->addContent (['type' => 'line', 'line' => $totalPurchase]);
		}

		if (count($this->dataCash))
		{
			$title = 'Pokladna';
			$h = array ('#' => '#', 'docType' => 'DD', 'docNumber' => 'Doklad', 'personFullName' => 'Zákazník', 'title' => 'Pozn.',
									'cashIn' => '+Příjem', 'cashOut' => '+Výdej');
			$this->addContent (array ('type' => 'table', 'title' => $title, 'header' => $h, 'table' => $this->dataCash));
		}
	}

	function createContent_DailyStats ()
	{
		$cashInitState = e10utils::getCashBoxInitState ($this->app, $this->unitNdx, $this->todayDate);

		$this->dataDaily[] = array ('txt' => 'Počáteční stav', 'value' => $cashInitState);

		if ($this->dailyCashIn != 0)
			$this->dataDaily[] = array ('txt' => 'Ostatní příjmy', 'value' => $this->dailyCashIn);
		if ($this->dailyCashSales != 0)
			$this->dataDaily[] = array ('txt' => 'Prodeje za hotové', 'value' => $this->dailyCashSales);
		if ($this->dailyCashPurchases != 0)
			$this->dataDaily[] = array ('txt' => 'Výkupy za hotové', 'value' => $this->dailyCashPurchases);
		if ($this->dailyCashOut != 0)
			$this->dataDaily[] = array ('txt' => 'Ostatní výdaje', 'value' => $this->dailyCashOut);

		$cashEndState = $cashInitState + $this->dailyCashIn + $this->dailyCashSales - $this->dailyCashPurchases - $this->dailyCashOut;
		$this->dataDaily[] = array ('txt' => 'Konečný zůstatek', 'value' => $cashEndState);
	}

	function createContent_Cash ()
	{
		$q[] = 'SELECT heads.[ndx] as ndx, [docNumber], [title], [toPay], [totalCash], [person], persons.fullName as personFullName,';
		array_push ($q, ' heads.[docType] as docType, heads.[docState] as docState, heads.[docStateMain] as docStateMain');
		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.dateAccounting = %d', $this->todayDate);
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				array_push ($q, ' AND heads.cashBox = %i', $this->unitNdx);
				break;
			case 'authors':
				array_push ($q, ' AND heads.author = %i', $this->unitNdx);
				break;
		}
		array_push ($q, ' AND heads.totalCash != 0 AND heads.docState = 4000 AND heads.docType IN %in', array ('cash', 'invni'));
		array_push ($q, ' ORDER BY docNumber');

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = array ('personFullName' => $r['personFullName'], 'title' => $r['title']);

			$docType = $this->docTypes [$r['docType']];
			$newItem ['docType'] = $docType ['shortcut'];
			$newItem ['docNumber'] = array ('text'=> $r ['docNumber'], 'docAction' => 'edit', 'icon' => $docType ['icon'],
																			'table' => 'e10doc.core.heads', 'pk'=> $r ['ndx']);

			if ($r['totalCash'] < 0)
			{
				$newItem ['cashOut'] = $r['totalCash'] * -1;
				$this->dailyCashOut += $r['totalCash'] * -1;
			}
			else
			{
				$newItem ['cashIn'] = $r['totalCash'];
				$this->dailyCashIn += $r['totalCash'];
			}

			$this->dataCash[] = $newItem;
		}
	}


	function createContent_Docs ()
	{
		$q[] = 'SELECT heads.[ndx] as ndx, [docNumber], [title], [toPay], [paymentMethod], [person], persons.fullName as personFullName,';
		array_push ($q, ' heads.[docType] as docType, heads.[docState] as docState, heads.[docStateMain] as docStateMain');
		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.dateAccounting = %d', $this->todayDate);
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				array_push ($q, ' AND heads.cashBox = %i', $this->unitNdx);
				break;
			case 'authors':
				array_push ($q, ' AND heads.author = %i', $this->unitNdx);
				break;
		}
		array_push ($q, ' AND heads.docState = 4000 AND heads.docType IN %in', array ('cashreg', 'invno'));
		array_push ($q, ' ORDER BY docNumber');

		$rows = $this->app->db()->query($q);

		foreach ($rows as $r)
		{
			$newItem = array ('personFullName' => $r['personFullName'], 'title' => $r['title']);

			$docType = $this->docTypes [$r['docType']];
			$newItem ['docType'] = $docType ['shortcut'];
			$newItem ['docNumber'] = array ('text'=> $r ['docNumber'], 'docAction' => 'edit', 'icon' => $docType ['icon'],
																			'table' => 'e10doc.core.heads', 'pk'=> $r ['ndx']);
			switch ($r['paymentMethod'])
			{
				case 0:
					$newItem['toPayBank'] = $r['toPay'];
					break;
				case 1:
					$newItem['toPayCash'] = $r['toPay'];
					$this->dailyCashSales += $r['toPay'];
					break;
				case 2:
					$newItem['toPayCard'] = $r['toPay'];
					break;
				case 3:
					$newItem['toPayCOD'] = $r['toPay'];
					break;
			}
			$this->dailyTotalSale += $r['toPay'];
			$this->dataSales[] = $newItem;
		}
		unset($q);

		$q[] = 'SELECT heads.[ndx] as ndx, [docNumber], [toPay], [paymentMethod], [prepayment], [person], persons.fullName as personFullName,';
		array_push ($q, ' heads.[docType] as docType, heads.[docState] as docState, heads.[docStateMain] as docStateMain');
		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE heads.dateAccounting = %d', $this->todayDate, ' AND heads.cashBox = %i', $this->unitNdx);
		array_push ($q, ' AND heads.docState = 4000 AND heads.docType IN %in', array ('purchase'));
		array_push ($q, ' ORDER BY docNumber');

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$newItem = array ('personFullName' => $r['personFullName']);

			$docType = $this->docTypes [$r['docType']];
			$newItem ['docType'] = $docType ['shortcut'];
			$newItem ['docNumber'] = array ('text'=> $r ['docNumber'], 'docAction' => 'edit', 'icon' => $docType ['icon'],
																			'table' => 'e10doc.core.heads', 'pk'=> $r ['ndx']);
			switch ($r['paymentMethod'])
			{
				case 1:
					$newItem['toPayCash'] = $r['toPay'];
					$this->dailyCashPurchases += $r['toPay'];
					break;
				case 0:
					$newItem['toPayBankOrder'] = $r['toPay'];
					break;
				case 4:
					$newItem['toPayInvoice'] = $r['toPay'];
					break;
				case 6:
					$newItem['toPayBatch'] = $r['toPay'];
					break;
				case 9:
					$newItem['toPayCheque'] = $r['toPay'];
					break;
				case 10:
					$newItem['toPayPostalOrder'] = $r['toPay'];
					break;
			}
			$this->dailyTotalPurchase += $r['toPay'];

			if ($r['prepayment'] != 0)
				$newItem['toPayCash'] = $r['prepayment'];

			$this->dataPurchases[] = $newItem;
		}
	}

	public function subReportsList ()
	{
		$d[] = array ('id' => 'cashboxes', 'icon' => 'detailReportCashRegisters', 'title' => 'Pokladny');
		$d[] = array ('id' => 'authors', 'icon' => 'detailReportUsers', 'title' => 'Uživatelé');
		return $d;
	}
}

