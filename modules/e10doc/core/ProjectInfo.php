<?php

namespace e10doc\core;
use \e10\utils, e10\DocumentCard;


/**
 * Class ProjectInfo
 * @package e10doc\core
 */
class ProjectInfo extends \lib\wkf\ProjectInfo
{
	var $table;
	var $tablePersons;
	var $currencies;

	var $docs = [];

	protected function addItem ($r)
	{
		$orderId = '5-'.$r['dateAccounting']->format ('Ymd').'-'.$r['headDocNumber'];

		$icon = $this->table->tableIcon ($r);
		$docStates = $this->table->documentStates ($r);
		$docStateClass = $this->table->getDocumentStateInfo ($docStates, $r, 'styleClass');

		$ndx = $r['headNdx'];
		if (!isset($this->docs[$ndx]))
		{
			$newDoc = [
					'orderId' => $orderId, 'date' => $r['dateAccounting'],
					'docStateClass' => $docStateClass, 'table' => $this->table->tableId(), 'ndx' => $ndx,
					'money' => $r['rowTaxBase'], 'currency' => $r['currency'],
					'info' => []
			];


			$docType = $this->app->cfgItem ('e10.docs.types.' . $r['docType'], FALSE);
			$sumId = '';
			if ($docType && isset($docType['timeline']))
			{
				if ($r['docType'] === 'cash')
					$sumId = ($r['cashBoxDir'] == 1) ? 'out' : 'in';
				else
					$sumId = $docType['timeline'];
			}

			$newDoc['info'][] = [
					['icon' => $icon, 'text' => $r['headDocNumber'], 'suffix' => utils::datef ($r['dateAccounting']), 'class' => 'title'],
					['icon' => $this->tablePersons->tableIcon ($r), 'text' => $r['personFullName'], 'class' => 'e10-off']
			];
			if ($sumId !== '')
			{
				$newDoc['sumAmount'] = $r['rowTaxBase'];
				$newDoc['sumGroup'] = $sumId;
			}
			$newDoc['info'][] = ['text' => $r['title'], 'class' => 'e10-small break'];
			$this->docs[$ndx] = $newDoc;
		}
		else
		{
			$this->docs[$ndx]['money'] += $r['rowTaxBase'];
			if (isset ($this->docs[$ndx]['sumAmount']))
				$this->docs[$ndx]['sumAmount'] += $r['rowTaxBase'];
		}
	}

	protected function createTimeLine ()
	{
		$q [] = 'SELECT heads.[ndx] as headNdx, heads.[docNumber] as headDocNumber, heads.[title], heads.[cashBoxDir], [rows].taxBase as rowTaxBase,';
		array_push ($q, ' heads.[dateAccounting], heads.[docType] as docType, heads.[docState] as docState,');
		array_push ($q, ' heads.[docStateMain] as docStateMain, heads.symbol1,');
		array_push ($q, ' heads.currency as currency,');
		array_push ($q, ' persons.[fullName] as personFullName, persons.company, persons.personType, persons.gender, persons.lastName');
		array_push ($q, ' FROM e10doc_core_rows AS [rows]');
		array_push ($q, ' LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND [rows].[project] = %i', $this->projectNdx);
		array_push ($q, ' AND heads.[docState] != 9800');

		if (!$this->table->rolesQuery ($q))
			return;

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->addItem ($r);
		}

		foreach ($this->docs as $docNdx => &$d)
		{
			$d['i1'] = utils::nf($d['money']);
			$currencyName = $this->currencies [$r['currency']]['shortcut'];
			if ($currencyName === 'CZK')
				$d['info'][0][] = ['text' => utils::nf($d['money'], 0) . ',-', 'class' => 'pull-right'];
			else
				$d['info'][0][] = ['text' => utils::nf($d['money'], 0) . ',-', 'prefix' => $currencyName, 'class' => 'pull-right'];

			$this->card->addItem('tl', $d);
		}
	}

	public function createInfo ($personNdx, $card, $options = [])
	{
		parent::createInfo($personNdx, $card, $options);
		$this->card->addSystemPart (DocumentCard::spTimeLine);
		$this->card->addSystemPart (DocumentCard::spDocuments);

		$this->table = $this->app->table('e10doc.core.heads');
		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		$this->createTimeLine();
	}
}
