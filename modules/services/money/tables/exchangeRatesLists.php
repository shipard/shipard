<?php

namespace services\money;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail, \e10\world;


/**
 * Class TableExchangeRatesLists
 * @package services\money
 */
class TableExchangeRatesLists extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.money.exchangeRatesLists', 'services_money_exchangeRatesLists', 'Kurzovní lístky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$listType = $this->app()->cfgItem('services.money.exchangeRatesLists.'.$recData['listType'], FALSE);
		if ($listType)
			$hdr ['info'][] = ['class' => 'title', 'value' => $listType['name']];

		$props = [];
		$currency = world::currency($this->app(), $recData['currency']);
		$country = world::country($this->app(), $recData['country']);

		$props[] = ['text' => $country['f'], 'class' => ''];
		$props[] = ['text' => strtoupper($currency['i']), 'class' => ''];

		$periodType = $this->columnInfoEnum ('periodType', 'cfgText');
		$props[] = ['text' => $periodType[$recData['periodType']], 'class' => 'label label-default'];
		$rateType = $this->columnInfoEnum ('rateType', 'cfgText');
		$props[] = ['text' => $rateType[$recData['rateType']], 'class' => 'label label-default'];


		$hdr ['info'][] = ['class' => 'info', 'value' => $props];

		$props = [];
		if ($recData['validFrom'] && $recData['validFrom'])
		{
			if ($recData['validFrom'] == $recData['validTo'])
				$props[] = ['text' => utils::datef($recData['validFrom'], '%d'), 'icon' => 'icon-calendar', 'class' => ''];
			else
				$props[] = ['text' => utils::datef($recData['validFrom']).' - '.utils::datef($recData['validTo']), 'icon' => 'icon-calendar', 'class' => ''];
		}

		$hdr ['info'][] = ['class' => 'info', 'value' => $props];

		$hdr ['newMode'] = 1;

		return $hdr;
	}
}


/**
 * Class ViewExchangeRatesLists
 * @package services\money
 */
class ViewExchangeRatesLists extends TableView
{
	var $exrLists;

	public function init()
	{
		parent::init();

		$this->exrLists = $this->app()->cfgItem('services.money.exchangeRatesLists', []);
		$this->setMainQueries();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$list = $this->exrLists[$item['listType']];
		$listItem ['t1'] = ['text' => $list['title'], 'class' => ''];

		$listItem ['i1'] = ['text' => '#'.utils::nf($item['listNumber']), 'class' => 'id'];

		$props = [];
		if ($item['validFrom'] && $item['validFrom'])
		{
			if ($item['validFrom'] == $item['validTo'])
				$props[] = ['text' => utils::datef($item['validFrom'], '%d'), 'icon' => 'icon-calendar', 'class' => ''];
			else
				$props[] = ['text' => utils::datef($item['validFrom']).' - '.utils::datef($item['validTo']), 'icon' => 'icon-calendar', 'class' => ''];
		}

		$listItem ['t2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT exr.* ';
		array_push ($q, ' FROM [services_money_exchangeRatesLists] AS exr');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'exr.', ['[validFrom] DESC', '[ndx] DESC']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormExchangeRatesList
 * @package services\money
 */
class FormExchangeRatesList extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Lístek', 'icon' => 'icon-file-o'];
		$tabs ['tabs'][] = ['text' => 'Kurzy', 'icon' => 'icon-money'];
		$tabs ['tabs'][] = ['text' => 'Data', 'icon' => 'icon-file'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('country');
					$this->addColumnInput ('currency');
					$this->addColumnInput ('periodType');
					$this->addColumnInput ('rateType');
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
					$this->addColumnInput ('listNumber');
					$this->addColumnInput ('listType');
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addList('rates');
				$this->closeTab();
				$this->openTab (self::ltNone);
					$this->addInputMemo('listData', NULL, TableForm::coFullSizeY|TableForm::coReadOnly);
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailExchangeRatesList
 * @package services\money
 */
class ViewDetailExchangeRatesList extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.money.documentCards.ExchangeRatesList');
	}
}
