<?php

namespace e10doc\cashRegister\libs;


class ViewCashRegisterDocs extends \e10doc\core\ViewHeads
{
	public $cashBoxes;
	public $mode = 0;

	public function init ()
	{
		$this->docType = 'cashreg';
		parent::init();

		$this->cashBoxes = $this->table->app()->cfgItem ('e10doc.cashBoxes', array());

		if (!isset ($this->table->app()->workplace['cashBox']) || ($this->mode == 1))
		{
			forEach ($this->cashBoxes as $cashBoxId => $cashBox)
				$bt [] = array ('id' => $cashBoxId, 'title' => $cashBox['shortName'], 'active' => 0,
												'addParams' => array ('cashBox' => $cashBoxId));
			$bt [] = array ('id' => '', 'title' => 'Vše', 'active' => 1);
			$this->setBottomTabs ($bt);
		}
	}

  public function renderRow ($item)
	{
		$listItem = parent::renderRow ($item);
		$listItem ['icon'] = $this->paymentMethods[$item['paymentMethod']]['icon'];

		return $listItem;
	}

	public function selectRows ()
	{
		$cashBox = intval ($this->bottomTabId ());

		$q [] = 'SELECT';
		array_push ($q, ' heads.[ndx] as ndx, [docNumber], [title], [sumPrice], [sumBase], [sumTotal], [toPay], [cashBoxDir], [dateIssue], [dateAccounting], [person], [currency], [paymentMethod],');

		if ($this->accounting)
			array_push($q, ' heads.docStateAcc as docStateAcc,');

		array_push ($q, ' activateTimeFirst, activateTimeLast, heads.symbol1, heads.homeCurrency, heads.taxPayer, heads.taxCalc, heads.[rosReg] as rosReg, heads.[rosState] as rosState,');
		array_push ($q, ' heads.[docType] as docType, heads.[docState] as docState, heads.[docStateMain] as docStateMain, persons.fullName as personFullName');

		array_push ($q, ' FROM [e10doc_core_heads] AS heads');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push ($q, ' WHERE 1');

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// bottomTab
		if ($cashBox != 0)
			array_push ($q, " AND heads.[cashBox] = %i", $cashBox);
		else
		if (isset ($this->table->app()->workplace['cashBox']) && $this->mode === 0)
			array_push ($q, " AND heads.[cashBox] = %i", $this->table->app()->workplace['cashBox']);

		$this->qryMain($q);
		$this->runQuery ($q);
	}
}
