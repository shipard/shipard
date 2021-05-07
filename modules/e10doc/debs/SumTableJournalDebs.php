<?php


namespace e10doc\debs;

use \lib\core\ui\SumTable, \e10\utils;

/**
 * Class SumTableJournalDebs
 * @package e10doc\debs
 */
class SumTableJournalDebs extends SumTable
{
	var $dataAll = [];
	var $dataSums = [];
	var $dataSumsByAccKinds = [];
	var $accNames;

	var $docTypes;

	var $accountKinds = NULL;

	public function init()
	{
		parent::init();

		$this->objectClassId = 'e10doc.debs.SumTableJournalDebs';

		$this->header = [
			'id' => '>ID',
			'note' => '>Text',
			'sumAmount' => ' Částka',
		];

		$this->colClasses['id'] = 'nowrap width14em';
	}

	function loadData()
	{
		$this->loadAccounts();
		if ($this->level === 3)
		{
			$this->loadData_Documents();
			return;
		}
		$this->loadData_Rows();
	}

	function loadData_Rows()
	{
		$this->loadJournalAccounts();
		foreach ($this->dataSums as $sumId => $sum)
		{
			$item = ['id' => strval($sumId), 'sumAmount' => $sum['sumAmount']];
			$item['note'] = $this->accNames[$sumId];
			$item['_options'] = ['expandable' => [
				'column' => 'id', 'level' => $this->level,
				'exp-this-id' => $sumId,
				'exp-parent-id' => isset($this->queryParams['account_id_mask']) ? $this->queryParams['account_id_mask'] : '',
				'query-params' => ['account_id_mask' => $sumId]
			]
			];
			$this->data[] = $item;
		}
	}

	function loadData_Documents()
	{
		$this->loadJournalDocuments();
		foreach ($this->dataSums as $sumId => $sum)
		{
			$item = ['id' => $sum['docNumber'], 'sumAmount' => $sum['sumAmount']];
			$item['note'] = $sum['note'];
			$item['_options'] = ['expandable' => [
					'level' => $this->level,
					'exp-this-id' => $sumId,
					'exp-parent-id' => isset($this->queryParams['account_id_mask']) ? $this->queryParams['account_id_mask'] : '',
				]
			];
			$this->data[] = $item;
		}
	}

	function loadJournalAccounts()
	{
		$useAccKinds = ($this->accountKinds && count($this->accountKinds));
		$q[] = 'SELECT journal.accountId, SUM(journal.money) as sumAmount, SUM(journal.moneyDr) as sumDr, SUM(journal.moneyCr) as sumCr';

		if ($useAccKinds)
			array_push ($q, ' , accounts.accountKind AS accKind');

		array_push ($q, ' FROM e10doc_debs_journal AS journal ');

		if ($useAccKinds)
			array_push ($q, 'LEFT JOIN e10doc_debs_accounts as accounts ON (journal.accountId = accounts.id AND accounts.docStateMain < 3)');

		array_push ($q, ' WHERE 1');

		if ($useAccKinds)
			array_push ($q, ' AND accounts.accountKind IN %in', $this->accountKinds);

		$this->applyQueryParams($q);

		array_push ($q, ' GROUP BY accountId');

		$rows = $this->app->db()->query($q);
		forEach ($rows as $acc)
		{
			$this->dataAll[] = $acc->toArray();
			$sumId = $acc['accountId'];
			switch ($this->level)
			{
				case 0: $sumId = substr($sumId, 0, 1); break;
				case 1: $sumId = substr($sumId, 0, 2); break;
			}

			if (!isset($this->dataSums[$sumId]))
				$this->dataSums[$sumId] = ['sumAmount' => 0.0, 'sumDr' => 0.0, 'sumCr' => 0.0];

			$this->dataSums[$sumId]['sumAmount'] += $acc['sumAmount'];
			$this->dataSums[$sumId]['sumCr'] += $acc['sumCr'];
			$this->dataSums[$sumId]['sumDr'] += $acc['sumDr'];

			if ($useAccKinds)
			{
				$ak = $acc['accKind'];

				if (!isset($this->dataSumsByAccKinds[$acc['accKind']]))
					$this->dataSumsByAccKinds[$ak] = ['sumAmount' => 0.0, 'sumDr' => 0.0, 'sumCr' => 0.0];
				$this->dataSumsByAccKinds[$ak]['sumAmount'] += $acc['sumAmount'];
				$this->dataSumsByAccKinds[$ak]['sumCr'] += $acc['sumCr'];
				$this->dataSumsByAccKinds[$ak]['sumDr'] += $acc['sumDr'];
			}
		}
	}

	function loadJournalDocuments()
	{
		$this->docTypes = $this->app->cfgItem ('e10.docs.types');

		$q[] = 'SELECT journal.docNumber, journal.document, journal.dateAccounting AS journalDateAccounting,';
		array_push ($q, ' persons.fullName AS personName, heads.docNumber AS headDocNumber, heads.title AS docTitle, heads.docType AS headDocType,');
		array_push ($q, ' journal.money AS sumAmount, journal.moneyDr AS sumDr, journal.moneyCr AS sumCr');
		array_push ($q, ' FROM e10doc_debs_journal AS journal');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON journal.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON journal.document = heads.ndx ');
		array_push ($q, ' WHERE 1');

		$this->applyQueryParams($q);

		array_push ($q, ' ORDER BY journal.dateAccounting, docNumber');

		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$this->dataAll[] = $r->toArray();
			$sumId = $r['docNumber'];

			if (!isset($this->dataSums[$sumId]))
			{
				$this->dataSums[$sumId] = ['sumAmount' => 0.0, 'sumDr' => 0.0, 'sumCr' => 0.0];
				$this->dataSums[$sumId]['note'] = $r['docTitle'];
				$this->dataSums[$sumId]['docNumber'] = [
					'text'=> $r['docNumber'], 'icon' => $this->docIcon($r), 'title' => utils::datef($r['journalDateAccounting'], '%D').': '.$r['personName'],
					'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['document']
				];
				//'docNumber' => array ('text'=> $r['docNumber'], 'icon' => $this->docIcon($r), 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $r['docHead']),
			}

			$this->dataSums[$sumId]['sumAmount'] += $r['sumAmount'];
			$this->dataSums[$sumId]['sumCr'] += $r['sumCr'];
			$this->dataSums[$sumId]['sumDr'] += $r['sumDr'];
		}
	}

	function applyQueryParams (&$q)
	{
		if (isset($this->queryParams['work_order']))
			array_push ($q, ' AND journal.[workOrder] = %i', $this->queryParams['work_order']);

		if (isset($this->queryParams['project']))
			array_push ($q, ' AND journal.[project] = %i', $this->queryParams['project']);

		if (isset($this->queryParams['account_id_mask']))
			array_push ($q, ' AND journal.[accountId] LIKE %s', $this->queryParams['account_id_mask'].'%');

	}

	function loadAccounts()
	{
		$qac = "SELECT id, shortName FROM e10doc_debs_accounts WHERE docStateMain < 3";
		$accounts = $this->app->db()->query($qac);
		$this->accNames = $accounts->fetchPairs ('id', 'shortName');
	}

	function docIcon($r)
	{
		return $this->docTypes[$r['headDocType']]['icon'];
	}
}
