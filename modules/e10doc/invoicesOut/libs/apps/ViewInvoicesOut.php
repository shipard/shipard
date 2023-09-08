<?php

namespace e10doc\invoicesOut\libs\apps;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView;


/**
 * class ViewInvoicesOut
 */
class ViewInvoicesOut extends TableView
{
  var $currencies;
  var $paymentMethods;
  var $today;

	public function init ()
	{

    $this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->today = date('ymd');
		$this->paymentMethods = $this->table->app()->cfgItem ('e10.docs.paymentMethods');

		$this->classes = ['viewerWithCards'];
		$this->enableToolbar = FALSE;

		parent::init();

		$this->objectSubType = TableView::vsDetail;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem['class'] = 'card';

		$listItem ['docNumber'] = $item['docNumber'];
    $listItem ['title'] = $item['title'];
    $listItem ['currency'] = $this->currencies[$item ['currency']]['shortcut'];
    $listItem ['dateAccounting'] = Utils::datef($item['dateAccounting']);
    $listItem ['sumTotal'] = Utils::nf($item['sumTotal'], 2);
    $listItem ['symbol1'] = $item['symbol1'];
    $listItem ['symbol2'] = $item['symbol2'];

		return $listItem;
	}

	public function selectRows ()
	{
		$q = [];

		$q [] = 'SELECT';
		array_push($q, ' heads.[ndx] as ndx, [docNumber], [title], [sumPrice], [sumBase], [sumTotal], [toPay], [cashBoxDir], [dateIssue], [dateAccounting], [person],');
		array_push($q, ' heads.[docType] as docType, heads.[docState] as docState, heads.[docStateMain] as docStateMain, symbol1, symbol2, heads.weightGross as weightGross,');
		array_push($q, ' heads.[taxPayer] as taxPayer, heads.[taxCalc] as taxCalc, heads.currency as currency, heads.homeCurrency as homeCurrency,');

		array_push($q, ' persons.fullName as personFullName, heads.[paymentMethod],');
		array_push($q, ' heads.[rosReg] as rosReg, heads.[rosState] as rosState,');
		array_push($q, ' heads.[vatReg], heads.[taxCountry]');
		array_push($q, ' FROM [e10doc_core_heads] AS heads');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push($q, ' WHERE 1');

    $this->appQuery($q);

    array_push($q, ' AND [heads].[docType] = %s', 'invno');

    array_push ($q, ' ORDER BY [dateAccounting] DESC, [heads].[docNumber]');

    array_push ($q, $this->sqlLimit ());

    $this->runQuery ($q);
	}

  protected function appQuery(&$q)
  {
  }

	public function createToolbar()
	{
		return [];
	}
}
