<?php

namespace e10doc\bank\libs;


class ViewBankDocs extends \e10doc\core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'bank';
		parent::init();

		$this->bankAccounts = $this->table->app()->cfgItem ('e10doc.bankAccounts', array());
		$activeBankAccount = key($this->bankAccounts);
		forEach ($this->bankAccounts as $bankAccountNdx => $r)
		{
			$bt [] = array ('id' => $bankAccountNdx, 'title' => $r['shortName'], 'active' => ($bankAccountNdx == $activeBankAccount),
											'addParams' => array ('person' => $r['bank'], 'myBankAccount' => $bankAccountNdx, 'currency' => $r['curr']));
		}
		$this->setBottomTabs ($bt);
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$myBankAccount = intval($this->bottomTabId ());

		$q [] = 'SELECT';
		array_push ($q, ' heads.ndx, [docNumber], [title], [initBalance], [balance], [debit], [credit], [docOrderNumber],');
		array_push ($q, ' [dateIssue], [dateAccounting], docType, heads.docState, heads.docStateMain, heads.docStateAcc as docStateAcc');
		array_push ($q, ' FROM [e10doc_core_heads] AS heads');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// -- myBankAccount
		if ($myBankAccount)
      array_push ($q, ' AND heads.[myBankAccount] = %s', $myBankAccount);

    // -- aktuální
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, ' AND heads.[docStateMain] < 4');

		// koš
		if ($mainQuery == 'trash')
      array_push ($q, ' AND heads.[docStateMain] = 4');

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [datePeriodBegin] DESC' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [datePeriodBegin] DESC' . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$moneySep = ' = ';
		if ($item['initBalance'] < $item['balance'])
			$moneySep = ' ↑ ';
		else
		if ($item['initBalance'] > $item['balance'])
			$moneySep = ' ↓ ';

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->icon;
		$listItem ['t1'] = strval($item['docOrderNumber']);
		$listItem ['i1'] = \E10\nf ($item['initBalance'], 2) . $moneySep . \E10\nf ($item['balance'], 2);

		$dc = array();
		if ($item['debit'] != 0.0)
			$dc [] = array ('icon' => 'icon-minus-square', 'text' => \E10\nf ($item['debit'], 2));
		if ($item['credit'] != 0.0)
			$dc [] = array ('icon' => 'icon-plus-square', 'text' => \E10\nf ($item['credit'], 2));
		$listItem ['i2'] = $dc;

		$listItem ['t3'] = $item ['title'];

		$props [] = ['icon' => 'icon-calendar', 'text' => \E10\df ($item['dateAccounting'], '%D'), 'class' => ''];

		$docNumber = ['icon' => 'icon-file', 'text' => $item ['docNumber'], 'class' => ''];
		if (isset($item['docStateAcc']) && $item['docStateAcc'] == 9)
			$docNumber['class'] = 'e10-error';
		$props [] = $docNumber;

		$listItem ['t2'] = $props;
		return $listItem;
	}
}

