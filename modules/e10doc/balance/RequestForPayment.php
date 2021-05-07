<?php


namespace E10Doc\Balance;
use \e10\FormReport, \e10doc\core\e10utils, \e10\utils;


/**
 * Class RequestForPayment
 * @package E10Doc\Balance
 */
class RequestForPayment extends FormReport
{
	public $fiscalYear;
	var $currencies;
	var $tablePersons;
	var $tableDocHeads;

	var $messageMoney = 0.0;
	var $messageCurrency = '';

	function init ()
	{
		$this->reportId = 'e10doc.balance.requestForPayment';
		$this->reportTemplate = 'e10doc.balance.requestForPayment';
	}

	public function checkDocumentInfo (&$documentInfo)
	{
		$documentInfo['messageDocKind'] = 'outbox-other-demandForPay';
		$documentInfo['money'] = $this->messageMoney;
		$documentInfo['currency'] = $this->messageCurrency;
	}

	public function loadData ()
	{
		$this->fiscalYear = e10utils::todayFiscalYear($this->app);
		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->tableDocHeads = $this->app->table('e10doc.core.heads');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		parent::loadData();

		$allProperties = \E10\Application::cfgItem ('e10.base.properties', array());


		// person
		$tablePersons = $this->app->table ('e10.persons.persons');
		$this->data ['person'] = $this->table->loadItem ($this->recData ['ndx'], 'e10_persons_persons');
		$this->data ['person']['lists'] = $tablePersons->loadLists ($this->data ['person']);
		$this->data ['person']['address'] = $this->data ['person']['lists']['address'][0];
		// persons country
		$country = $this->app->cfgItem ('e10.base.countries.'.$this->data ['person']['lists']['address'][0]['country']);
		$this->data ['person']['address']['countryName'] = $country['name'];
		$this->data ['person']['address']['countryNameEng'] = $country['engName'];
		$this->data ['person']['address']['countryNameSC2'] = $country['sc2'];
		$this->lang = $country['lang'];

		forEach ($this->data ['person']['lists']['properties'] as $iii)
		{
			if ($iii['group'] != 'ids') continue;
			$name = '';
			if ($iii['property'] == 'taxid') $name = 'DIČ';
			else if ($iii['property'] == 'oid') $name = 'IČ';
			else if ($iii['property'] == 'idcn') $name = 'OP';
			else if ($iii['property'] == 'birthdate') $name = 'DN';
			else if ($iii['property'] == 'pid') $name = 'RČ';

			$this->data ['person_identifiers'][] = array ('name'=> $name, 'value' => $iii['value']);
		}


		// owner
		$ownerNdx = intval($this->app->cfgItem ('options.core.ownerPerson', 0));
		if ($ownerNdx)
		{
			$this->data ['owner'] = $this->table->loadItem ($ownerNdx, 'e10_persons_persons');
			$this->data ['owner']['lists'] = $tablePersons->loadLists ($this->data ['owner']);
			$ownerCountry = '';
			if (isset($this->data ['owner']['lists']['address'][0]))
			{
				$this->data ['owner']['address'] = $this->data ['owner']['lists']['address'][0];
				$ownerCountry = $this->app->cfgItem('e10.base.countries.' . $this->data ['owner']['lists']['address'][0]['country']);
				$this->data ['owner']['address']['countryName'] = $ownerCountry['name'];
				$this->data ['owner']['address']['countryNameEng'] = $ownerCountry['engName'];
				$this->data ['owner']['address']['countryNameSC2'] = $ownerCountry['sc2'];
			}
			forEach ($this->data ['owner']['lists']['properties'] as $iii)
			{
				if ($iii['group'] == 'ids')
				{
					$name = '';
					if ($iii['property'] == 'taxid')
					{
						$name = 'DIČ';
						$this->data ['owner']['vatId'] = $iii['value'];
						$this->data ['owner']['vatIdCore'] = substr ($iii['value'], 2);
					}
					else
						if ($iii['property'] == 'oid')
							$name = 'IČ';

					if ($name != '')
						$this->data ['owner_identifiers'][] = array ('name'=> $name, 'value' => $iii['value']);
				}
				if ($iii['group'] == 'contacts')
				{
					$name = $allProperties[$iii['property']]['name'];
					$this->data ['owner_contacts'][] = array ('name'=> $name, 'value' => $iii['value']);
				}
			}

			$ownerAtt = \E10\Base\getAttachments ($this->table->app(), 'e10.persons.persons', $ownerNdx, TRUE);
			foreach ($ownerAtt as $oa)
			{
				$this->data ['owner']['logo'][$oa['name']] = $oa;
			}
		}

		// author
		$authorNdx = $this->app->user()->data ('id');
		$this->data ['author'] = $this->table->loadItem ($authorNdx, 'e10_persons_persons');
		$this->data ['author']['lists'] = $tablePersons->loadLists ($authorNdx);

		$authorAtt = \E10\Base\getAttachments ($this->table->app(), 'e10.persons.persons', $authorNdx, TRUE);
		$this->data ['author']['signature'] = \E10\searchArray ($authorAtt, 'name', 'podpis');

		if (isset($this->data ['author']['lists']['address'][0]))
			$this->data ['author']['address'] = $this->data ['author']['lists']['address'][0];


		$this->loadData_Documents ();
	}

	public function loadData_Documents ()
	{
		$today = utils::today();
		$this->data ['today'] = utils::datef($today, '%d');
		$dueDate = e10utils::balanceOverDueDate ($this->app);

		$q[] = 'SELECT heads.docNumber, heads.dateDue, heads.dateDue as docDateDue, heads.ndx as docNdx, heads.docType as docType, heads.title as docTitle,';
		array_push ($q, ' journal.currency as currency, journal.request as totalRequest, journal.symbol1, journal.symbol2, journal.[date] as dateDue,');
		array_push ($q, ' (SELECT SUM(payment) FROM `e10doc_balance_journal` AS s WHERE s.pairId = journal.pairId AND s.side = 1 AND s.fiscalYear = %i) AS payments, ', $this->fiscalYear);
		array_push ($q, ' (SELECT SUM(payment) FROM `e10doc_balance_journal` AS s WHERE s.pairId = journal.pairId AND s.side = 1) AS totalPayment');

		array_push ($q, ' FROM [e10doc_balance_journal] AS journal');
		array_push ($q, ' LEFT JOIN [e10doc_core_heads] as heads ON journal.docHead = heads.ndx');
//		array_push ($q, ' LEFT JOIN [e10_persons_persons] as persons ON journal.person = persons.ndx');
		array_push ($q, ' WHERE journal.side = 0', ' AND journal.[date] < %d', $dueDate);
		array_push ($q, ' AND journal.fiscalYear = %i', $this->fiscalYear, ' AND journal.person = %i', $this->recData ['ndx']);
		array_push ($q, ' AND EXISTS (',
			' SELECT SUM(q.request) as sumRequest, SUM(q.payment) as sumPayment FROM `e10doc_balance_journal` as q',
			' WHERE q.[type] = 1000 AND q.pairId = journal.pairId AND q.fiscalYear = %i', $this->fiscalYear,
			' GROUP BY q.[pairId] HAVING sumPayment < sumRequest',
			')');
		array_push ($q, ' ORDER BY journal.[date]');

		$rows = $this->app->db()->query($q);

		$totals = [];
		$data = [];
		foreach ($rows as $r)
		{
			$overDueDays = utils::dateDiff ($r['dateDue'], $today);
			$item = [
				'docNumber' => $r['docNumber'],
				'request' => $r['totalRequest'] - $r['payments'] + $r['totalPayment'], 'curr' => $this->currencies[$r['currency']]['shortcut'],
				'dateDue' => $r['dateDue'], 's1' => $r['symbol1'], 's2' => $r['symbol2'], 'docTitle' => $r['docTitle'], 'payment' => 0,
				'_options' => ['class' => e10utils::balanceOverDueClass ($this->app, $overDueDays)]
			];

			if ($r['totalPayment'])
			{
				$item['payment'] = $r['totalPayment'];
				$item['restAmount'] = round($r['totalRequest'] - $r['payments'], 2);
			}
			else
				$item['restAmount'] = $r['totalRequest'];

			$cid = $r['currency'];
			if (isset($totals[$cid]))
				$totals[$cid] += $item['restAmount'];
			else
				$totals[$cid] = $item['restAmount'];


			$item['print'] = ['request' => utils::nf($item['request'], 2),
				'payment' => utils::nf($item['payment'], 2), 'restAmount' => utils::nf($item['restAmount'], 2)];

			$data [] = $item;

		}

		$this->data ['rows'] = $data;
		$this->data ['totals'] = [];

		foreach ($totals as $curr => $rest)
		{
			$sum = [
				'restAmount' => $rest, 'currency' => $curr, 'curr' => $this->currencies[$curr]['shortcut'],
				'print' => ['restAmount' => utils::nf($rest, 2)]
				];

			$this->data ['totals'][] = $sum;
		}

		if (count($this->data ['totals']))
		{
			$this->messageMoney = $this->data ['totals'][0]['restAmount'];
			$this->messageCurrency = $this->data ['totals'][0]['currency'];
		}
	}
}

