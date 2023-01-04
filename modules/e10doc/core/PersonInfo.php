<?php

namespace e10doc\core;
use \e10\utils;


/**
 * Class PersonInfo
 * @package E10Doc\Core
 */
class PersonInfo extends \lib\persons\PersonInfo
{
	var $table;
	var $tablePersons;
	var $currencies;
	var $docs;

	protected function init()
	{
		$this->table = $this->app->table('e10doc.core.heads');
		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
	}

	protected function addItem ($r)
	{
		$orderId = '5-'.$r['dateAccounting']->format ('Ymd').'-'.$r['headDocNumber'];
		$icon = $this->table->tableIcon ($r);
		$docStates = $this->table->documentStates ($r);
		$docStateClass = $this->table->getDocumentStateInfo ($docStates, $r, 'styleClass');
		$ndx = $r['headNdx'];

		$docType = $this->app->cfgItem ('e10.docs.types.' . $r['docType'], FALSE);
		$sumId = '';
		if ($docType && isset($docType['timeline']))
		{
			if ($r['docType'] === 'cash')
				$sumId = ($r['cashBoxDir'] == 1) ? 'out' : 'in';
			else
				$sumId = $docType['timeline'];
		}

		$newDoc = [
				'orderId' => $orderId, 'date' => $r['dateAccounting'],
				'docStateClass' => $docStateClass, 'table' => $this->table->tableId(), 'ndx' => $ndx,
				'info' => []
		];
		if ($sumId !== '')
		{
			$newDoc['sumAmount'] = $r['sumBase'];
			$newDoc['sumGroup'] = $sumId;
		}
		$newDoc['info'][] = [
				['icon' => $icon, 'text' => $r['headDocNumber'], 'suffix' => utils::datef ($r['dateAccounting']), 'class' => 'title']
		];

		$currencyName = $this->currencies [$r['currency']]['shortcut'];
		if ($currencyName === 'CZK')
			$newDoc['info'][0][] = ['text' => utils::nf($r['sumBase'], 0) . ',-', 'class' => 'pull-right'];
		else
			$newDoc['info'][0][] = ['text' => utils::nf($r['sumBase'], 0) . ',-', 'prefix' => $currencyName, 'class' => 'pull-right'];

		$newDoc['info'][] = ['text' => $r['title'], 'class' => 'e10-small break'];
		$this->docs[$ndx] = $newDoc;
	}

	protected function createTimeLine ($partId, $partDefinition)
	{
		$this->docs = [];
		$cntDocs = 300;

		$q [] = 'SELECT heads.[ndx] as headNdx, [docNumber] as headDocNumber, [title], [sumPrice], [sumBase], [sumBaseHc], [sumTotal], [toPay], [cashBoxDir], [dateIssue],';
		array_push ($q, ' [dateAccounting], [person], activateTimeLast, heads.[docType] as docType, heads.[docState] as docState,');
		array_push ($q, ' heads.[docStateMain] as docStateMain, symbol1, heads.weightGross as weightGross, heads.[taxPayer] as taxPayer,');
		array_push ($q, ' heads.[taxCalc] as taxCalc, heads.currency as currency, heads.homeCurrency as homeCurrency,');
		array_push ($q, ' persons.fullName as personFullName, persons.lastName as lastName, persons.company as company, persons.gender as gender,');
		array_push ($q, ' heads.[paymentMethod]');
		array_push ($q, ' FROM e10doc_core_heads AS heads');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND heads.[person] = %i', $this->personNdx);
		array_push ($q, ' AND heads.[docState] != 9800');

		if ($partId === 'docsBuy')
		{
			array_push($q, ' AND (');
			array_push($q, ' heads.[docType] IN %in', ['invni', 'purchase']);
			array_push($q, ' OR heads.[docType] = %s', 'cash', ' AND heads.cashBoxDir = %i', 2);
			array_push($q, ')');

			$cntDocs = 6;
		}
		elseif ($partId === 'docsSale')
		{
			array_push($q, ' AND (');
			array_push($q, ' heads.[docType] IN %in', ['invno', 'cashreg']);
			array_push($q, ' OR heads.[docType] = %s', 'cash', ' AND heads.cashBoxDir = %i', 1);
			array_push($q, ')');

			$cntDocs = 6;
		}

		if (!$this->table->rolesQuery ($q))
			return;

		array_push ($q, ' ORDER BY heads.[dateAccounting] DESC, heads.[ndx] DESC');
		array_push ($q, ' LIMIT '.$cntDocs);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->addItem ($r);
		}

		if ($partDefinition)
		{
			$this->card->addPart($partId, $partDefinition);
		}
		foreach ($this->docs as $docNdx => &$d)
		{
			$this->card->addItem($partId, $d);
		}
	}

	public function createInfo ($personNdx, $card, $options = [])
	{
		parent::createInfo($personNdx, $card, $options);

		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
		if ($testNewPersons)
		{
			$this->card->addContent ('body', [
				'sumTable' => [
					'objectId' => 'e10doc.core.libs.SumTablePersonAnalysis',
					'queryParams' => ['person_ndx' => $this->personNdx]
				]
			]);

			return;
		}

		$this->init();
		$this->doIt();
	}

	protected function doIt ()
	{
		if ($this->tileMode)
			return;

		$this->createTimeLine('tl', NULL);
	}
}
