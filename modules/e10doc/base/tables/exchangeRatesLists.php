<?php

namespace e10doc\base;

use \e10\utils, \e10\DbTable, \e10\TableView, \e10\TableForm, \e10\TableViewDetail, \e10\world;


/**
 * Class TableExchangeRatesLists
 * @package e10doc\base
 */
class TableExchangeRatesLists extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.exchangeRatesLists', 'e10doc_base_exchangeRatesLists', 'Kurzovní lístky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$listType = $this->app()->cfgItem('e10doc.base.exchangeRatesLists.'.$recData['listType'], FALSE);
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
				$props[] = ['text' => utils::datef($recData['validFrom'], '%d'), 'icon' => 'system/iconCalendar', 'class' => ''];
			else
				$props[] = ['text' => utils::datef($recData['validFrom']).' - '.utils::datef($recData['validTo']), 'icon' => 'system/iconCalendar', 'class' => ''];
		}

		$hdr ['info'][] = ['class' => 'info', 'value' => $props];

		$hdr ['newMode'] = 1;

		return $hdr;
	}
}


/**
 * Class ViewExchangeRatesLists
 * @package e10doc\base
 */
class ViewExchangeRatesLists extends TableView
{
	var $exrLists;

	public function init()
	{
		parent::init();

		$this->exrLists = $this->app()->cfgItem('e10doc.base.exchangeRatesLists', []);
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
				$props[] = ['text' => utils::datef($item['validFrom'], '%d'), 'icon' => 'system/iconCalendar', 'class' => ''];
			else
				$props[] = ['text' => utils::datef($item['validFrom']).' - '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => ''];
		}

		$listItem ['t2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT exr.* ';
		array_push ($q, ' FROM [e10doc_base_exchangeRatesLists] AS exr');
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
 * @package e10doc\base
 */
class FormExchangeRatesList extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Lístek', 'icon' => 'icon-file-o'];
		$tabs ['tabs'][] = ['text' => 'Kurzy', 'icon' => 'icon-money'];

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
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailExchangeRatesList
 * @package e10doc\base
 */
class ViewDetailExchangeRatesList extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.base.documentCards.ExchangeRatesList');
	}
}
