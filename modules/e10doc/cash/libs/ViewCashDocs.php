<?php

namespace e10doc\cash\libs;


class ViewCashDocs extends \e10doc\core\ViewHeads
{
	var $cashBoxes;

	public function init ()
	{
		$this->docType = 'cash';
		parent::init();

		$this->cashBoxes = $this->table->app()->cfgItem ('e10doc.cashBoxes', array());
		if (isset ($this->table->app()->workplace['cashBox']))
			$activeCashBox = $this->table->app()->workplace['cashBox'];
		else
			$activeCashBox = key($this->cashBoxes);
		forEach ($this->cashBoxes as $cashBoxId => $cashBox)
			$bt [] = array ('id' => $cashBoxId, 'title' => $cashBox['shortName'], 'active' => ($cashBoxId == $activeCashBox),
											'addParams' => array ('cashBox' => $cashBoxId, 'currency' => $cashBox['curr']));
		$bt [] = array ('id' => '0', 'title' => 'Vše', 'active' => ($cashBoxId == $activeCashBox));
		$this->setBottomTabs ($bt);

		if ($this->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
			$this->showWorkOrders = TRUE;
	}

  public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->paymentMethods[$item['paymentMethod']]['icon'];
		$listItem ['t1'] = $item['personFullName'];

		if ($item['cashBoxDir'] == 1)
			$listItem ['i1'][] = array ('icon' => 'system/iconPlusSquare', 'text' => \E10\nf ($item['toPay'], 2));
		else
			$listItem ['i1'][] = array ('icon' => 'system/iconMinusSquare', 'text' => \E10\nf ($item['toPay'], 2));

		if ($item ['taxPayer'])
		{
			if ($item ['taxCalc'])
				$listItem ['i2'] = 'bez DPH: ' . \E10\nf ($item['sumBase'], 2);
		}

		$props [] = ['i' => 'file', 'text' => $item ['docNumber'], 'class' => ''];
		$props [] = ['i' => 'calendar', 'text' => \E10\df ($item['dateAccounting'], '%D'), 'class' => ''];
		$this->renderRow_rosProps ($item, $props);
		$listItem ['t2'] = $props;

		if ($item ['title'] != '')
			$listItem ['t3'] = $item ['title'];
		return $listItem;
	}

	public function selectRows ()
	{
		$cashBox = intval($this->bottomTabId ());

		$q [] = 'SELECT heads.[ndx] as ndx, [docNumber], [title], [sumPrice], [sumBase], [sumTotal], [toPay], [cashBoxDir], [dateIssue], [dateAccounting], [person], [currency],
							heads.[docState] as docState, heads.[taxPayer] as taxPayer, heads.[taxCalc] as taxCalc, docType, paymentMethod,
							heads.[docStateMain] as docStateMain, persons.fullName as personFullName, taxp.fullName as taxpFullName,
							heads.[rosReg] as rosReg, heads.[rosState] as rosState
              FROM
              e10_persons_persons AS persons RIGHT JOIN (
              e10doc_base_taxperiods AS taxp RIGHT JOIN [e10doc_core_heads] as heads
              ON (heads.taxPeriod = taxp.ndx))
              ON (heads.person = persons.ndx)
              WHERE 1';

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// bottomTab
		if ($cashBox != 0)
			array_push ($q, " AND heads.[cashBox] = %i", $cashBox);

		$this->qryFulltext($q);
		$this->qryMain($q);
		$this->runQuery ($q);
	} // selectRows

	function addPanels(&$panels)
	{
		$vid = $this->app()->testGetParam ('ownerViewerId');
		if ($vid === '')
			$vid = $this->vid;

		$panels[] = [
			'id' => 'inbox',
			'title' => 'Pošta', 'type' => 'viewer', 'table' => 'wkf.core.issues',
			'class' => 'wkf.core.viewers.WkfDocsFromInbox',
			'params' => ['docType' => 'cash', 'mainViewerId' => $vid],
		];
	}
}

