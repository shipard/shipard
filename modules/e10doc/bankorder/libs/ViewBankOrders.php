<?php

namespace e10doc\bankOrder\libs;

use \E10\utils, \E10\DataModel, \E10Doc\Core\ViewDetailHead;



class ViewBankOrders extends \E10Doc\Core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'bankorder';
		parent::init();

		$this->bankAccounts = $this->table->app()->cfgItem ('e10doc.bankAccounts', array());
		$activeBankAccount = key($this->bankAccounts);
		forEach ($this->bankAccounts as $bankAccountNdx => $r)
			$bt [] = array ('id' => $bankAccountNdx, 'title' => $r['shortName'], 'active' => ($bankAccountNdx == $activeBankAccount),
											'addParams' => array ('person' => $r['bank'], 'myBankAccount' => $bankAccountNdx, 'currency' => $r['curr']));
		$this->setBottomTabs ($bt);
	}

	public function createMainQueries ()
	{
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'archive', 'title' => 'Archív'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$myBankAccount = intval($this->bottomTabId ());

		$q [] = 'SELECT heads.ndx, [docNumber], [title], [initBalance], [balance], [debit], [credit], [docOrderNumber], [dateDue],
							[dateIssue], [dateAccounting], docType, heads.docState, heads.docStateMain FROM [e10doc_core_heads] as heads
							LEFT JOIN e10_persons_persons as persons ON heads.person = persons.ndx
							WHERE 1';

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// -- myBankAccount
		if ($myBankAccount)
      array_push ($q, " AND heads.[myBankAccount] = %s", $myBankAccount);

		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND heads.[docStateMain] < 4");

		if ($mainQuery == 'archive')
			array_push ($q, " AND heads.[docStateMain] = 5");

		if ($mainQuery == 'trash')
      array_push ($q, " AND heads.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [datePeriodBegin] DESC' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [docNumber] DESC' . $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->icon;
		$listItem ['t1'] = utils::datef($item['dateDue'], '%d');
		$listItem ['i1'] = ['icon' => 'system/iconMinusSquare', 'text' => utils::nf ($item['debit'], 2)];

		if ($item['credit'] != 0.0)
			$listItem ['i2'] = ['icon' => 'system/iconPlusSquare', 'text' => utils::nf ($item['credit'], 2)];

		$listItem ['t3'] = $item ['title'];

		$props [] = ['icon' => 'system/iconFile', 'text' => $item ['docNumber']];

		$listItem ['t2'] = $props;
		return $listItem;
	}
}
