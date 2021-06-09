<?php

namespace e10doc\base;
use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\world;


/**
 * Class TableExchangeRatesValues
 * @package e10doc\base
 */
class TableExchangeRatesValues extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.exchangeRatesValues', 'e10doc_base_exchangeRatesValues', 'Kurzy měn');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['cntUnits']) || !$recData['cntUnits'])
			$recData['cntUnits'] = 1;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (!isset($recData['cntUnits']) || !$recData['cntUnits'])
			$recData['cntUnits'] = 1;
		if (!isset($recData['exchangeRate']) || !$recData['exchangeRate'])
			$recData['exchangeRate'] = 1.0;

		$recData['exchangeRateOneUnit'] = round($recData['exchangeRate'] / $recData['cntUnits'], 7);
	}
}


/**
 * Class FormExchangeRatesValue
 * @package e10doc\base
 */
class FormExchangeRatesValue extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('currency', TableForm::coColW12);
				$this->addColumnInput ('exchangeRate', TableForm::coColW4);
				$this->addColumnInput ('cntUnits', TableForm::coColW4);
				$this->addColumnInput ('exchangeRateOneUnit', TableForm::coColW4|TableForm::coReadOnly);
			$this->closeRow();
		$this->closeForm ();
	}
}


/**
 * Class ViewExchangeRatesValuesCombo
 * @package e10doc\base
 */
class ViewExchangeRatesValuesCombo extends TableView
{
	var $exrLists;
	var $srcCurrencyNdx = 0;
	var $dstCurrencyNdx = 0;
	var $docDate = NULL;

	public function init ()
	{
		parent::init();

		$this->exrLists = $this->app()->cfgItem('e10doc.base.exchangeRatesLists', []);

		if ($this->queryParam ('dstCurrency'))
			$this->dstCurrencyNdx = world::currencyNdx($this->app(), $this->queryParam ('dstCurrency'));
		if ($this->queryParam ('srcCurrency'))
			$this->srcCurrencyNdx = world::currencyNdx($this->app(), $this->queryParam ('srcCurrency'));

		if ($this->queryParam ('dateAccounting'))
			$this->docDate = utils::createDateTime($this->queryParam ('dateAccounting'));
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['data-cc']['exchangeRate'] = $item['exchangeRateOneUnit'];

		$list = $this->exrLists[$item['listType']];
		$listItem ['i1'] = utils::nfu ($item['exchangeRate']);
		$listItem ['t2'] = ['text' => $list['title'], 'class' => ''];

		$props = [];
		if ($item['validFrom'] && $item['validFrom'])
		{
			if ($item['validFrom'] == $item['validTo'])
				$props[] = ['text' => utils::datef($item['validFrom'], '%d'), 'icon' => 'system/iconCalendar', 'class' => ''];
			else
				$props[] = ['text' => utils::datef($item['validFrom'], '%k').' - '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => ''];
		}
		$listItem ['t1'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = 'SELECT [values].*, [lists].listType AS listType, [lists].validFrom, [lists].validTo';
		array_push ($q, ' FROM [e10doc_base_exchangeRatesValues] AS [values]');
		array_push ($q, ' LEFT JOIN [e10doc_base_exchangeRatesLists] AS [lists] ON [values].[list] = [lists].ndx ');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND [values].[currency] = %i', $this->dstCurrencyNdx);
		array_push ($q, ' AND [lists].[currency] = %i', $this->srcCurrencyNdx);

		if ($this->docDate)
			array_push ($q, ' AND [lists].[validFrom] <= %d', $this->docDate);

		array_push ($q, ' ORDER BY lists.validFrom DESC');
		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}
}
