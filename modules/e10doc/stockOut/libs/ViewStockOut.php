<?php

namespace e10doc\stockOut\libs;


class ViewStockOut extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'stockout';
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->icon;

		if ($item['initState'])
			$listItem ['t1'] = 'Počáteční stav';
		else
			$listItem ['t1'] = $item['personFullName'];

		//$listItem ['i1'] = \E10\nf ($item['sumBase'], 2);

		$listItem ['t3'] = $item ['title'];

		$docNumber = ['icon' => 'system/iconFile', 'text' => $item ['docNumber'], 'class' => ''];
		if (isset($item['docStateAcc']) && $item['docStateAcc'] == 9)
			$docNumber['class'] = 'e10-error';
		$props [] = $docNumber;

		$props [] = ['icon' => 'system/iconCalendar', 'text' => \E10\df ($item['dateIssue'], '%d'), 'class' => ''];

		$listItem ['t2'] = $props;
		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = 'SELECT heads.[ndx] as ndx, [docNumber], [title], [sumPrice], [sumBase], [sumTotal], [toPay], [cashBoxDir], [dateIssue], [person], [currency], [docType], heads.docStateAcc,
							heads.initState as initState, heads.[docState] as docState, heads.[docStateMain] as docStateMain, persons.fullName as personFullName, taxp.fullName as taxpFullName
              FROM
              e10_persons_persons AS persons RIGHT JOIN (
              e10doc_base_taxperiods AS taxp RIGHT JOIN [e10doc_core_heads] as heads
              ON (heads.taxPeriod = taxp.ndx))
              ON (heads.person = persons.ndx)
              WHERE 1';

		$this->qryCommon ($q);
		$this->qryFulltext ($q);
		$this->qryMain($q);
		$this->runQuery ($q);
	}
}
