<?php

namespace E10Pro\Reports\Terminal_monthly;

use e10doc\core\libs\E10Utils, E10\utils;


/**
 * Class reportMonthly
 * @package E10Pro\Reports\Terminal_monthly
 */
class reportMonthly extends \e10doc\core\libs\reports\GlobalReport
{
	var $currencies;
	var $unitNdx;
	var $todayDate;
	var $docTypes;
	var $numDays = 14;
	var $startDate;

	var $dataSales = array();
	var $dataPurchases = array();

	function init ()
	{
		$this->startDate = utils::today();

		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years', 'enableAll'], 'defaultValue' => E10Utils::todayFiscalMonth($this->app)]);
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				$this->addParam('cashBox', 'cashBox', ['flags' => ['enableAll']]); break;
			case 'authors':
				$this->addParamAuthors(); break;
		}

		parent::init();

		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');
		$this->todayDate = utils::today();

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

		$q[] = 'SELECT heads.[author] as author, persons.[fullName] as fullName';
		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.author = persons.ndx');
		array_push ($q, ' WHERE heads.docType IN %in',  array ('invno', 'cash', 'cashreg'));
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
		$this->setInfo('title', 'Měsíční přehled');
		$this->setInfo('icon', 'icon-check-circle');
		$this->setInfo('param', 'Období', $this->reportParams ['fiscalPeriod']['activeTitle']);
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				$this->setInfo('param', 'Pokladna', $this->reportParams ['cashBox']['activeTitle']);
				$this->setInfo('saveFileName', 'Měsíční přehled '.$this->reportParams ['cashBox']['activeTitle'].' '.$this->reportParams ['fiscalPeriod']['activeTitle']);
				break;
			case 'authors':
				$this->setInfo('param', 'Uživatel', $this->reportParams ['author']['activeTitle']);
				$this->setInfo('saveFileName', 'Měsíční přehled '.$this->reportParams ['author']['activeTitle'].' '.$this->reportParams ['fiscalPeriod']['activeTitle']);
				break;
		}
		$this->setInfo('note', '1', 'Všechny částky jsou uvedeny včetně DPH.');

		$this->createContent_Docs ();

		if (count($this->dataSales))
		{
			$title = 'Prodeje';
			$h = ['date' => 'Datum', 'toPayTOTAL' => '+CELKEM',
						'toPayCash' => '+Hotově', 'toPayCard' => '+Kartou', 'toPayBank' => '+Převodem', 'toPayCOD' => '+Dobírkou'];
			$this->addContent (['type' => 'table', 'title' => $title, 'header' => $h, 'table' => $this->dataSales, 'main' => TRUE]);
		}

		if (count($this->dataPurchases))
		{
			$title = 'Výkupy';
			$h = ['date' => 'Datum', 'toPayTOTAL' => '+CELKEM',
						'toPayCash' => '+Hotově', 'toPayCheque' => '+Šekem', 'toPayPostalOrder' => '+Pošt. p.',
						'toPayBankOrder' => '+Převodem', 'toPayBatch' => '+Sběrný lístek', 'toPayInvoice' => '+Fakturou'];
			$this->addContent (['type' => 'table', 'title' => $title, 'header' => $h, 'table' => $this->dataPurchases, 'main' => TRUE]);
		}
	}

	function createContent_Docs ()
	{
		$q[] = 'SELECT [dateAccounting], SUM([toPay]) as toPay, [paymentMethod] ';

		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' WHERE 1');
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				if ($this->unitNdx != 0)
					array_push ($q, ' AND heads.cashBox = %i', $this->unitNdx);
				break;
			case 'authors':
				array_push ($q, ' AND heads.author = %i', $this->unitNdx);
				break;
		}
		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);
		array_push ($q, ' AND heads.docState = 4000 AND heads.docType IN %in', ['cashreg', 'invno']);
		array_push ($q, ' GROUP BY heads.dateAccounting, heads.paymentMethod');
		array_push ($q, ' ORDER BY heads.dateAccounting');

		$rows = $this->app->db()->query($q);

		foreach ($rows as $r)
		{
			$dayId = $r['dateAccounting']->format ('Ymd');
			if (!isset ($this->dataSales[$dayId]))
				$this->dataSales[$dayId] = ['date' => $r['dateAccounting'],
					'toPayBank' => 0.0, 'toPayCash' => 0.0, 'toPayCard' => 0.0, 'toPayCOD' => 0.0, 'toPayTOTAL' => 0.0];

			switch ($r['paymentMethod'])
			{
				case 0:
					$this->dataSales[$dayId]['toPayBank'] += $r['toPay'];
					break;
				case 1:
					$this->dataSales[$dayId]['toPayCash'] += $r['toPay'];
					break;
				case 2:
					$this->dataSales[$dayId]['toPayCard'] += $r['toPay'];
					break;
				case 3:
					$this->dataSales[$dayId]['toPayCOD'] += $r['toPay'];
					break;
			}
			$this->dataSales[$dayId]['toPayTOTAL'] += $r['toPay'];
		}
		unset($q);


		$q[] = 'SELECT [dateAccounting], SUM([toPay]) as toPay, [paymentMethod], SUM([prepayment]) as prepayment';
		array_push ($q, ' FROM [e10doc_core_heads] as heads');
		array_push ($q, ' WHERE 1');
		switch ($this->subReportId)
		{
			case '':
			case 'cashboxes':
				if ($this->unitNdx != 0)
					array_push ($q, ' AND heads.cashBox = %i', $this->unitNdx);
				break;
			case 'authors':
				array_push ($q, ' AND heads.author = %i', $this->unitNdx);
				break;
		}
		E10Utils::fiscalPeriodQuery ($q, $this->reportParams ['fiscalPeriod']['value']);
		array_push ($q, ' AND heads.docState = 4000 AND heads.docType = %s', 'purchase');
		array_push ($q, ' GROUP BY heads.dateAccounting, heads.paymentMethod');
		array_push ($q, ' ORDER BY heads.dateAccounting');

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$dayId = $r['dateAccounting']->format ('Ymd');
			if (!isset ($this->dataPurchases[$dayId]))
				$this->dataPurchases[$dayId] = ['date' => $r['dateAccounting'],
					'toPayCash' => 0.0, 'toPayInvoice' => 0.0, 'toPayBatch' => 0.0, 'toPayTOTAL' => 0.0];

			switch ($r['paymentMethod'])
			{
				case 1:
					$this->dataPurchases[$dayId]['toPayCash'] += $r['toPay'] + $r['prepayment'];
					break;
				case 0:
					$this->dataPurchases[$dayId]['toPayBankOrder'] += $r['toPay'];
					break;
				case 4:
					$this->dataPurchases[$dayId]['toPayInvoice'] += $r['toPay'];
					break;
				case 6:
					$this->dataPurchases[$dayId]['toPayBatch'] += $r['toPay'];
					break;
				case 9:
					$this->dataPurchases[$dayId]['toPayCheque'] += $r['toPay'];
					break;
				case 10:
					$this->dataPurchases[$dayId]['toPayPostalOrder'] += $r['toPay'];
					break;
			}

			$this->dataPurchases[$dayId]['toPayTOTAL'] += $r['toPay'] + $r['prepayment'];
		}
	}

	public function subReportsList ()
	{
		$d[] = array ('id' => 'cashboxes', 'icon' => 'detailReportCashRegisters', 'title' => 'Pokladny');
		$d[] = array ('id' => 'authors', 'icon' => 'detailReportUsers', 'title' => 'Uživatelé');
		return $d;
	}
}

