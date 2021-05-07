<?php

namespace e10doc\cmnbkp\libs;


class ViewCmnBkpDocs extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'cmnbkp';
		$this->disabledActivitiesGroups = ['ocp', 'clp'];
		parent::init();
	}

  public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->icon;
		$listItem ['t1'] = $item['personFullName'];

		if ($item['credit'] === $item['debit'])
			$listItem ['i1'] = ['text' => \E10\nf ($item['credit'], 2)];
		else
		{
			$listItem ['i1'] = ['text' => 'MD '.\E10\nf ($item['debit'], 2).' ≠ DAL '.\E10\nf ($item['credit'], 2)];
			$listItem ['i2'] = 'rozdíl: '.\E10\nf ($item['debit']-$item['credit'], 2);
		}
		if ($item ['currency'] != $item ['homeCurrency'])
			$listItem ['i1']['prefix'] = $this->currencies[$item ['currency']]['shortcut'];

		$props = [];
		$docNumber = ['icon' => 'icon-file', 'text' => $item ['docNumber'], 'class' => ''];
		if (isset($item['docStateAcc']) && $item['docStateAcc'] == 9)
			$docNumber['class'] = 'e10-error';
		$props [] = $docNumber;

		$props [] = ['i' => 'calendar', 'text' => \E10\df ($item['dateAccounting'], '%D'), 'class' => ''];
		$listItem ['t2'] = $props;

		if ($item ['title'] != '')
			$listItem ['t3'] = $item ['title'];
		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = 'SELECT';
		array_push ($q, ' heads.[ndx] as ndx, [docNumber], [title], [sumPrice], [sumBase], [sumTotal], [toPay], [cashBoxDir], [dateIssue], [dateAccounting], [person], [currency], [homeCurrency],');
		array_push ($q, ' heads.[debit] as debit, heads.[credit] as credit, heads.fiscalYear, heads.linkId,');
		array_push ($q, ' heads.[docState] as docState, heads.[docStateMain] as docStateMain, docType, heads.docStateAcc as docStateAcc,');
		array_push ($q, ' persons.fullName as personFullName');
		array_push ($q, ' FROM [e10doc_core_heads] AS heads');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		$this->qryCommon ($q);
		$this->qryFulltext ($q);
		$this->qryMain($q);
		$this->runQuery ($q);
	}
}
