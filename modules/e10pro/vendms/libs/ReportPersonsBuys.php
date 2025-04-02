<?php

namespace e10pro\vendms\libs;
use \Shipard\Utils\Utils, \e10doc\core\libs\E10Utils;


/**
 * class ReportPersonsBuys
 */
class ReportPersonsBuys extends \e10doc\core\libs\reports\DocReportBase
{
	var $periodBegin = NULL;
  var $periodEnd = NULL;

	var $currencies;
	var $tablePersons;
	var $tableDocHeads;

	function init ()
	{
		parent::init();
		$this->setReportId('e10pro.vendms.personsBuys');
	}

	public function setOutsideParam ($param, $value)
	{
		if ($param === 'data-param-period-begin')
			$this->periodBegin = Utils::createDateTime($value);
		elseif ($param === 'data-param-period-end')
			$this->periodEnd = Utils::createDateTime($value);
	}

	protected function initParams()
	{
		if ($this->app()->testGetParam('data-param-period-begin') !== '')
			$this->setOutsideParam('data-param-period-begin', $this->app()->testGetParam('data-param-period-begin'));
		if ($this->app()->testGetParam('data-param-period-end') !== '')
			$this->setOutsideParam('data-param-period-end', $this->app()->testGetParam('data-param-period-end'));

		$this->data['flags']['periodValue'] = Utils::datef($this->periodBegin, '%d').' - '.Utils::datef($this->periodEnd, '%d');
	}

	public function loadData ()
	{
		$this->sendReportNdx = 181001;

		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->tableDocHeads = $this->app->table('e10doc.core.heads');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		parent::loadData();

		$this->initParams();

		$this->data ['flags']['periodBegin'] = $this->periodBegin;
		$this->data ['flags']['periodEnd'] = Utils::datef($this->periodEnd, '%d');
	}

	public function loadData2 ()
	{
		$this->sendReportNdx = 181001;

		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->tableDocHeads = $this->app->table('e10doc.core.heads');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		// -- person
		$this->loadData_MainPerson('person', $this->recData['ndx']);

		// -- owner
		$this->loadData_DocumentOwner ();

		// -- author
		$authorNdx = $this->app->user()->data ('id');
		$this->loadData_Author($authorNdx);

		$this->initParams();

		$this->data ['flags']['periodBegin'] = $this->periodBegin;
		$this->data ['flags']['periodEnd'] = Utils::datef($this->periodEnd, '%d');

		$this->loadData_Buys ();
		$this->loadData_Credits();
	}

	public function loadData_Buys ()
	{
    $q = [];

		array_push($q, 'SELECT items.id as itemId, items.fullName AS itemName,');
		array_push($q, ' heads.homeCurrency AS homeCurrency, heads.dateAccounting, heads.activateTimeFirst,');
		array_push($q, ' [rows].item, [rows].unit as rowUnit, [rows].quantity AS quantity, ');
		array_push($q, ' [rows].taxBaseHc AS price');
		array_push($q, ' FROM e10doc_core_rows AS [rows]');
		array_push($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push($q, ' WHERE heads.docState = 4000 ');
		array_push($q, ' AND heads.person = %i', $this->recData['ndx']);
		array_push($q, ' AND rows.item != %i', 0);
		array_push($q, ' AND heads.dateAccounting >= %d', $this->periodBegin);
		array_push($q, ' AND heads.dateAccounting <= %d', $this->periodEnd);
		array_push($q, ' AND heads.docType IN %in', ['invno', 'cashreg']);
		array_push($q, ' ORDER BY heads.dateAccounting, heads.ndx');

		$rows = $this->app->db()->query($q);

		$data = [];
		forEach ($rows as $r)
		{
			$newItem = $r->toArray();
			$newItem['date'] = Utils::datef($r['activateTimeFirst'], '%d');
			$newItem['time'] = $r['activateTimeFirst']->format('H:i:s');
			$data[] = $newItem;
		}

		$credit = $this->personsCredit($this->recData['ndx'], $this->periodEnd);

		$headerItems = [
			'#' => '#',
			'date' => 'Datum',
			'time' => 'Čas',
			'itemId' => ' id',
			'itemName' => 'Položka',
			'price' => '+Cena',
		];
		$this->data['buys'] = [
			[
				'type' => 'table', 'title' => [
					['text' => 'Seznam nákupů', 'class' => 'h3'],
					['text' => 'Zbývající kredit: '.Utils::nf($credit, 2).' Kč', 'class' => 'h3 pull-right'],
				],
				'table' => $data,
				'header' => $headerItems,
			]
		];
	}

	public function loadData_Credits ()
	{
		$periodEnd = Utils::createDateTime($this->periodEnd);
		$periodEnd->setTime(23, 59, 59);

    $q = [];

		array_push($q, 'SELECT credits.*,');
		array_push($q, ' bt.bankAccount AS bankAccount');
		array_push($q, ' FROM e10pro_vendms_credits AS [credits]');
		array_push($q, ' LEFT JOIN e10doc_finance_transactions AS [bt] ON [credits].bankTransNdx = [bt].ndx');
		//array_push($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push($q, ' WHERE credits.docState = 4000 ');
		array_push($q, ' AND credits.person = %i', $this->recData['ndx']);
		array_push($q, ' AND credits.created >= %d', $this->periodBegin);
		array_push($q, ' AND credits.created <= %t', $periodEnd);
		array_push($q, ' AND credits.moveType IN %in', [0, 1]);
		array_push($q, ' ORDER BY credits.created, credits.ndx');

		$rows = $this->app->db()->query($q);

		$data = [];
		forEach ($rows as $r)
		{
			$newItem = $r->toArray();

			$newItem['date'] = Utils::datef($r['created'], '%d');

			$data[] = $newItem;
		}

		$headerItems = [
			'date' => 'Datum',
			'bankAccount' => 'Z účtu',
			'amount' => '+Částka',
		];
		$this->data['credits'] = [
			[
				'type' => 'table', 'title' => [
					['text' => 'Dobíjení kreditu', 'class' => 'h3'],
				],
				'table' => $data,
				'header' => $headerItems,
			]
		];
	}

	protected function personsCredit($personNdx, $toDate)
  {
		$date = Utils::createDateTime($toDate);
		$date->setTime(23, 59, 59);

    $c = $this->db()->query('SELECT SUM(amount) AS totalCredit FROM [e10pro_vendms_credits] ',
			'WHERE [person] = %i', $personNdx, ' AND [docState] = %i', 4000, ' AND [created] <= %t', $date)->fetch();

		if ($c)
      return floatval($c['totalCredit']);

    return 0;
  }
}
