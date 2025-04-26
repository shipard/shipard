<?php

namespace e10pro\soci\libs;
use \Shipard\Utils\Utils;


/**
 * class ReportInvoicesDeferredRevenue
 */
class ReportInvoicesDeferredRevenue extends \e10doc\core\libs\reports\GlobalReport
{
	var $list = [];

	var $fiscalYear = 0;
	var $periodBegin = NULL;
	var $periodEnd = NULL;
	var $dateBeginNextYear = NULL;
	var $periodFirstLabel = '';
	var $periodNextLabel = '';

	function init()
	{
    $this->addParam ('fiscalYear');

		parent::init();

		$this->fiscalYear = $this->reportParams ['fiscalYear']['value'];
		$this->periodBegin = $this->reportParams ['fiscalYear']['values'][$this->fiscalYear]['dateBegin'];
		$this->periodEnd = $this->reportParams ['fiscalYear']['values'][$this->fiscalYear]['dateEnd'];

		$this->dateBeginNextYear = Utils::createDateTime($this->periodEnd);
		$this->dateBeginNextYear->modify('+1 day');

		$this->periodFirstLabel = $this->periodEnd->format('y');
		$this->periodNextLabel = $this->dateBeginNextYear->format('y');

		$this->setInfo('icon', 'reportInvoices');
		$this->setInfo('title', 'Výnosy příštích období');
	}

	function createContent ()
	{
		parent::createContent();
		$this->loadList();

		$this->setInfo('param', 'Období', $this->reportParams ['fiscalYear']['activeTitle']);

		$h = [
				'#' => '#', 'docNumber' => 'Faktura', 'person' => 'Osoba',
				'toPay' => '+Částka',
				'dateFrom' => 'Od', 'dateTo' => 'do',
				'daysAll' => ' dnů', 'daysNext' => ' dnů \''.$this->periodNextLabel,
				'amountNext' => '+částka \''.$this->periodNextLabel,
		];

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list, 'main' => TRUE]);
	}

	protected function loadList()
	{
		$q = [];
		array_push($q, 'SELECT heads.*, persons.fullName as personFullName');
		array_push($q, ' FROM [e10doc_core_heads] AS heads');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND heads.docType = %s', 'invno');
		array_push($q, ' AND heads.docState = %i', 4000);
		array_push($q, ' AND heads.fiscalYear = %i', $this->fiscalYear);
		array_push($q, ' AND heads.datePeriodBegin IS NOT NULL');
		array_push($q, ' AND heads.datePeriodEnd > %d', $this->periodEnd);
		array_push($q, ' ORDER BY heads.docNumber');

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$dateFrom = NULL;
			$dateFrom = $r['datePeriodBegin'];
			$dateTo = $r['datePeriodEnd'];
			if (!$dateFrom || !$dateTo)
				continue;

			$item = [
					'docNumber' => ['text' => $r['docNumber'], 'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10doc.core.heads', 'title' => $r['title']],
					'person' => $r['personFullName'],
					'toPay' => $r['toPayHc'],
					'dateFrom' => $dateFrom, 'dateTo' => $dateTo
			];

			$intervalAll = $dateTo->diff($dateFrom);
			$daysAll = $intervalAll->days + 1;
			$item['daysAll'] = $daysAll;

			if ($dateTo > $this->dateBeginNextYear)
			{
				$intervalNext = $this->dateBeginNextYear->diff($dateTo);
				$daysNext = $intervalNext->days + 1;
			}
			else
				$daysNext = 0;
			$item['daysNext'] = $daysNext;

			$amountNext = 0.0;
			if ($daysNext)
				$amountNext = round (($daysNext / $daysAll) * $r['toPayHc'], 2);
			$item['amountNext'] = $amountNext;

			if ($r['toPayHc'] == 0.0)
				$item['_options']['class'] = 'e10-warning2';

			$this->list[] = $item;
		}
	}
}
