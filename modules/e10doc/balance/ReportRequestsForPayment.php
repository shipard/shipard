<?php

namespace E10Doc\Balance;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \e10\utils, \e10doc\core\e10utils;


/**
 * Tisková sestava s návrhem upomínek
 *
 * @package E10Doc\Balance
 */
class ReportRequestsForPayment extends \e10doc\core\libs\reports\GlobalReport
{
	public $fiscalYear = 0;
	var $currencies;
	var $tablePersons;
	var $tableDocHeads;
	var $persons = [];

	function init ()
	{
		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->tableDocHeads = $this->app->table('e10doc.core.heads');

		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->addParam ('fiscalYear');

		parent::init();

		$this->fiscalYear = $this->reportParams ['fiscalYear']['value'];
	}

	function createContent ()
	{
		$today = utils::today();
		$dueDate = e10utils::balanceOverDueDate ($this->app);

		$q[] = 'SELECT heads.docNumber, heads.dateDue, heads.dateDue as docDateDue, heads.ndx as docNdx, heads.docType as docType,';
		array_push ($q, ' persons.fullName as personFullName, persons.ndx as personNdx, persons.id as personId, ',
										' persons.company as company, persons.gender as gender, persons.lastName as lastName, ');
		array_push ($q, ' journal.currency as currency, journal.request as totalRequest, journal.symbol1, journal.symbol2, journal.[date] as dateDue,');
		array_push ($q, ' (SELECT SUM(payment) FROM `e10doc_balance_journal` AS s WHERE s.pairId = journal.pairId AND s.side = 1 AND s.fiscalYear = %i) AS payments, ', $this->fiscalYear);
		array_push ($q, ' (SELECT SUM(payment) FROM `e10doc_balance_journal` AS s WHERE s.pairId = journal.pairId AND s.side = 1) AS totalPayment');

		array_push ($q, ' FROM [e10doc_balance_journal] AS journal');
		array_push ($q, ' LEFT JOIN [e10doc_core_heads] as heads ON journal.docHead = heads.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] as persons ON journal.person = persons.ndx');
		array_push ($q, ' WHERE journal.side = 0', ' AND journal.[date] < %d', $dueDate, ' AND journal.fiscalYear = %i', $this->fiscalYear);
		array_push ($q, ' AND EXISTS (',
			' SELECT SUM(q.request) as sumRequest, SUM(q.payment) as sumPayment FROM `e10doc_balance_journal` as q',
			' WHERE q.[type] = 1000 AND q.pairId = journal.pairId AND q.fiscalYear = %i', $this->fiscalYear,
			' GROUP BY q.[pairId] HAVING sumPayment < sumRequest',
			')');
		array_push ($q, ' ORDER BY persons.fullName, journal.[date]');

		$rows = $this->app->db()->query($q);

		$totals = [];
		$lastPerson = 0;
		$thisPersonCnt = 0;
		$totalsPerson = [];
		$data = [];
		foreach ($rows as $r)
		{
			if ($r['personNdx'] !== $lastPerson)
			{
				if ($lastPerson && $thisPersonCnt > 1)
					$this->appendTotal ($data, $totalsPerson, 'subtotal');



				$hdr = ['docNumber' => [['text' => $r['personFullName'], 'icon' => $this->tablePersons->tableIcon ($r)]],
								'_options' => ['class' => 'subheader', 'beforeSeparator' => 'separator', 'colSpan' => ['docNumber' => 8]]];

				// -- person id
				$hdr['docNumber'][] = ['text' => '#'.$r['personId'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk' => $r['personNdx'], 'class' => 'e10-small e10-linePart'];

				// -- print button
				$btn = ['type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'icon-print', 'text' => 'Upomínka',
								'data-report' => 'e10doc.balance.RequestForPayment',
								'data-table' => 'e10.persons.persons', 'data-pk' => $r['personNdx'], 'actionClass' => 'btn-xs', 'class' => 'pull-right'];
				$btn['subButtons'] = [];
				$btn['subButtons'][] = [
					'type' => 'action', 'action' => 'addwizard', 'icon' => 'icon-envelope-o', 'title' => 'Odeslat emailem', 'btnClass' => 'btn-default btn-xs',
					'data-table' => 'e10.persons.persons', 'data-pk' => $r['personNdx'], 'data-class' => 'e10.SendFormReportWizard',
					'data-addparams' => 'reportClass=' . 'e10doc.balance.RequestForPayment' . '&documentTable=' . 'e10.persons.persons'
				];
				$hdr['docNumber'][] = $btn;

				$data[] = $hdr;
				$totalsPerson = [];
				$thisPersonCnt = 0;
			}

			$overDueDays = utils::dateDiff ($r['dateDue'], $today);
			$item = [
				'docNumber' => [['text' => $r['docNumber'], 'icon' => $this->tableDocHeads->tableIcon ($r), 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['docNdx']]],
				'request' => $r['totalRequest'] - $r['payments'] + $r['totalPayment'], 'curr' => $this->currencies[$r['currency']]['shortcut'],
				'dateDue' => $r['dateDue'], 's1' => $r['symbol1'], 's2' => $r['symbol2'],
				'_options' => ['class' => e10utils::balanceOverDueClass ($this->app, $overDueDays)]
			];

			if ($r['docType'] === 'cmnbkp')
			{
				$originalDoc = $this->app->db()->query ('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', 'invno',
					'AND symbol1 = %s', $r['symbol1'], ' AND symbol2 = %s', $r['symbol2'], ' AND person = %i', $r['personNdx'])->fetch();
				if ($originalDoc)
				{
					$item ['docNumber'][] = ['text' => '', 'icon' => 'icon-angle-left', 'class' => 'e10-linePart'];
					$item ['docNumber'][] = ['text' => $originalDoc['docNumber'], 'icon' => $this->tableDocHeads->tableIcon ($originalDoc), 'docAction' => 'edit',
																		'table' => 'e10doc.core.heads', 'pk' => $originalDoc['ndx']];
				}
			}

			if ($r['totalPayment'])
			{
				$item['payment'] = $r['totalPayment'];
				$item['restAmount'] = round($r['totalRequest'] - $r['payments'], 2);
			}
			else
				$item['restAmount'] = $r['totalRequest'];

			$cid = $r['currency'];
			if (isset($totalsPerson[$cid]))
				$totalsPerson[$cid] += $item['restAmount'];
			else
				$totalsPerson[$cid] = $item['restAmount'];

			if (isset($totals[$cid]))
				$totals[$cid] += $item['restAmount'];
			else
				$totals[$cid] = $item['restAmount'];

			$data [] = $item;
			$lastPerson = $r['personNdx'];
			$thisPersonCnt++;

			if (!in_array($r['personNdx'], $this->persons))
				$this->persons[] = $r['personNdx'];
		}
		if ($thisPersonCnt > 1)
			$this->appendTotal ($data, $totalsPerson, 'subtotal');

		$this->appendTotal ($data, $totals, 'sumtotal');


		$h = [
			'docNumber' => 'Doklad', 's1' => 'VS', 's2' => 'SS', 'dateDue' => ' Splatnost',
			'request' => ' Předpis', 'payment' => ' Uhrazeno', 'restAmount' => ' K úhradě',
			'curr' => 'Měna'
		];

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);

		$this->setInfo('title', 'Návrh upomínek');
		$this->setInfo('icon', 'icon-exclamation-circle');
		$this->setInfo('param', 'Rok', $this->reportParams ['fiscalYear']['activeTitle']);
		$this->setInfo('saveFileName', 'Návrh upomínek');
	}

	public function appendTotal (&$data, $totals, $class)
	{
		foreach ($totals as $curr => $rest)
		{
			$sum = ['restAmount' => $rest, 'curr' => $this->currencies[$curr]['shortcut'],
							'_options' => ['class' => $class, 'colSpan' => ['docNumber' => 6]]];

			if ($class === 'sumtotal')
			{
				$sum['_options']['beforeSeparator'] = 'separator';
				$sum['docNumber'] = 'CELKEM k upomenutí';
			}

			$data[] = $sum;
		}
	}

	public function createToolbar ()
	{
		$buttons = parent::createToolbar();
		$buttons[] = [
				'text' => 'Rozeslat hromadně emailem', 'icon' => 'icon-envelope',
				'type' => 'action', 'action' => 'addwizard', 'data-class' => 'e10doc.balance.RequestForPaymentWizard',
				'data-table' => 'e10.persons.persons', 'data-pk' => '0',
				'class' => 'btn-primary'
		];
		return $buttons;
	}

}
