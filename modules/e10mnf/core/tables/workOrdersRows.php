<?php

namespace e10mnf\core;

use \e10\DbTable, \e10\TableForm;


/**
 * Class TableWorkOrdersRows
 * @package e10mnf\core
 */
class TableWorkOrdersRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.core.workOrdersRows', 'e10mnf_core_workOrdersRows', 'Řádky zakázek');
	}

	public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
		return 'default';
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset($recData ['priceSource']))
			$recData ['priceSource'] = 0;
		if (!isset($recData ['priceItem']))
			$recData ['priceItem'] = 0;
		if (!isset($recData ['quantity']))
			$recData ['quantity'] = 1;

		$exchangeRate = $ownerData ['exchangeRate'];
		if (!$exchangeRate)
			$exchangeRate = 1;

		// -- price
		if ($recData ['priceSource'] === 0)
			$recData ['priceAll'] = round ($recData ['priceItem'] * $recData ['quantity'], 2);
		else
		{
			if ($recData ['quantity'] != 0.0)
				$recData ['priceItem'] = round($recData ['priceAll'] / $recData ['quantity'], 4);
			else
				$recData ['priceItem'] = 0.0;
		}

		$recData ['priceItemHc'] = round (($recData ['priceItem'] * $exchangeRate), 2);
		$recData ['priceAllHc'] = round (($recData ['priceAll'] * $exchangeRate), 2);

		parent::checkBeforeSave ($recData, $ownerData);
	}
}


/**
 * Class FormRow
 * @package e10mnf\core
 */
class FormRow extends TableForm
{
	var $dko = NULL;

	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$this->dko =  $this->app()->cfgItem ('e10mnf.workOrders.kinds.'.$ownerRecData['docKind'], NULL);
		if (!$this->dko)
			$this->dko =  $this->app()->cfgItem ('e10mnf.workOrders.kinds.0', NULL);

		$this->openForm (TableForm::ltGrid);

		$this->openRow ();
			$this->addColumnInput ('item', TableForm::coColW12);
		$this->closeRow ();

		$this->openRow ();
			$this->addColumnInput ('text', TableForm::coColW12);
		$this->closeRow ();

		$this->openRow ();
			$this->addColumnInput ('quantity', TableForm::coColW4);
			$this->addColumnInput ('unit', TableForm::coColW4);
			$this->addColumnInput ('priceItem', TableForm::coColW4);
		$this->closeRow ();

		$neededCols = [];
		if ($this->dko['useRowDateDeadlineRequested'])
			$neededCols[] = 'dateDeadlineRequested';
		if ($this->dko['useRowDateDeadlineConfirmed'])
			$neededCols[] = 'dateDeadlineConfirmed';
		if ($this->dko['useRowRefId1'])
			$neededCols[] = 'refId1';
		if ($this->dko['useRowRefId2'])
			$neededCols[] = 'refId2';
		if ($this->dko['useRowRefId3'])
			$neededCols[] = 'refId3';
		if ($this->dko['useRowRefId4'])
			$neededCols[] = 'refId4';

		$cols = 2;
		$width = TableForm::coColW6;
		$rowOpen = 0;
		$colCnt = 0;
		foreach ($neededCols as $ncid)
		{
			if (!$rowOpen)
			{
				$rowOpen = 1;
				$this->openRow ();
			}
			$this->addColumnInput ($ncid, $width);
			$colCnt++;
			if ($colCnt === $cols)
			{
				$this->closeRow ();
				$rowOpen = 0;
			}
		}
		if ($rowOpen)
			$this->closeRow ();

		if ($this->dko['useRowValidFromTo'])
		{
			$this->openRow ();
				$this->addColumnInput ('validFrom', TableForm::coColW6);
				$this->addColumnInput ('validTo', TableForm::coColW6);
			$this->closeRow ();
		}

		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
	{
		$dko = $this->dko;
		switch ($colDef ['sql'])
		{
			case'refId1': return $dko['labelRowRefId1'];
			case'refId2': return $dko['labelRowRefId2'];
			case'refId3': return $dko['labelRowRefId3'];
			case'refId4': return $dko['labelRowRefId4'];
		}

		return parent::columnLabel ($colDef, $options);
	}
}