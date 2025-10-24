<?php

namespace e10doc\purchase\libs;

use e10\json;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewPurchaseDocs
 */
class ViewPurchaseDocs extends \E10Doc\Core\ViewHeads
{
	var $warehouses;
	var $paymentMethods;

	public function init ()
	{
		$this->docType = 'purchase';
		parent::init();

		$this->warehouses = $this->table->app()->cfgItem ('e10doc.warehouses', array());
		$this->paymentMethods = $this->table->app()->cfgItem ('e10.docs.paymentMethods');

		forEach ($this->warehouses as $whId => $wh)
			$bt [] = array ('id' => $whId, 'title' => $wh['shortName'], 'active' => 0, 'addParams' => array ('warehouse' => $whId));
		$bt [] = array ('id' => '', 'title' => 'Vše', 'active' => 0);
		$bt [0]['active'] = 1;
		$this->setBottomTabs ($bt);
	}

	public function renderRow ($item)
	{
		$listItem = parent::renderRow ($item);

		//$listItem['t1'] .= '`'.json_encode($item['otherAddress1']).'`';

		$icon = 'purchaseTicketTransportPerson';
		if ($item['weighingMachine'] !== 0)
		{
			if ($item['weightGross'] < 999)
				$icon = 'purchaseTicketTransportCar';
			else
				$icon = 'purchaseTicketTransportTruck';
		}
		$listItem ['icon'] = $icon;

		$listItem ['i1']['icon'] = $this->paymentMethods[$item['paymentMethod']]['icon'];
		return $listItem;
	}

	public function selectRows ()
	{
		$wh = $this->bottomTabId ();

		$q [] = 'SELECT heads.[ndx] as ndx, heads.quantity as quantity, [docNumber], [title], heads.[docType] as [docType], [heads].docStateAcc,'.
						' [sumPrice], [sumBase], [sumTotal], [weightGross], [activateTimeFirst], [activateTimeLast], [weighingMachine],[paymentMethod],'.
						' [toPay], [cashBoxDir], [dateIssue], [dateAccounting], [person], [currency], [homeCurrency], [symbol1], heads.[otherAddress1Mode], heads.otherAddress1,'.
						' heads.initState as initState, heads.[docState] as docState, heads.[docStateMain] as docStateMain, persons.fullName as personFullName'.
            ' FROM [e10doc_core_heads] as heads'.
						' LEFT JOIN e10_persons_persons AS persons ON (heads.person = persons.ndx)'.
						' WHERE 1';

		$this->qryCommon ($q);
		$this->qryFulltext ($q);

		// bottomTab
		if ($wh != '')
			array_push ($q, " AND heads.[warehouse] = %i", $this->warehouses[$wh]['ndx']);

		$this->qryMain($q);
		$this->runQuery ($q);
	}

	protected function extendPanelContentQry (TableViewPanel $panel, array &$qry)
	{
		parent::extendPanelContentQry ($panel, $qry);

		$chbxPurchases = [
			'fromORP' => ['title' => 'Z ORP', 'id' => 'fromORP'],
			'withBadORP' => ['title' => 'S vadným / prázdným ORP', 'id' => 'withBadORP'],
			'withoutPersonHandover' => ['title' => 'Bez osoby Předal', 'id' => 'withoutPersonHandover'],
		];

		$paramsPurchases = new \Shipard\UI\Core\Params ($this->app());
		$paramsPurchases->addParam ('checkboxes', 'query.purchases', ['items' => $chbxPurchases]);
		$qry[] = ['id' => 'purchases', 'style' => 'params', 'title' => 'Výkupy', 'params' => $paramsPurchases];
	}

	public function qryPanel (array &$q)
	{
		parent::qryPanel($q);

		$qv = $this->queryValues();

		$fromORP = isset ($qv['purchases']['fromORP']);
		$withBadORP = isset ($qv['purchases']['withBadORP']);

		if ($fromORP)
		{
			array_push ($q, ' AND [heads].[otherAddress1Mode] = %i', 1);
			if (!$withBadORP)
				array_push ($q, ' AND [heads].[personNomencCity] != %i', 0);
		}
		if ($withBadORP)
		{
			if (!$fromORP)
				array_push ($q, ' AND [heads].[otherAddress1Mode] = %i', 1);
			array_push ($q, ' AND [heads].[personNomencCity] = %i', 0);
		}

		$withoutPersonHandover = isset ($qv['purchases']['withoutPersonHandover']);
		if ($withoutPersonHandover)
		{
			array_push ($q, ' AND heads.personHandover = %i', 0);
		}
	}
}
