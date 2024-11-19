<?php

namespace e10pro\loyp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TablePriceListPoints
 */
class TablePriceListPoints extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.loyp.priceListPoints', 'e10pro_loyp_priceListPoints', 'Ceník ve věrnostních bodech');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewPriceListPoints
 */
class ViewPriceListPoints extends TableView
{
	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];

    $listItem ['t1'] = $item['witemName'];

    $listItem ['t2'] = [];
    $listItem ['t2'][] = ['text' => Utils::nf($item['pricePoints']).' bodů', 'class' => ''];
    $listItem ['t2'][] = ['text' => Utils::nf($item['priceMoney']).' Kč', 'class' => ''];

    $ft = utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);
		if ($ft !== '')
			$listItem['i2'] = ['text' => $ft, 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [priceList].*,');
    array_push ($q, ' [witems].fullName AS witemName');
    array_push ($q, ' FROM [e10pro_loyp_priceListPoints] AS [priceList]');
    array_push ($q, ' LEFT JOIN [e10_witems_items] AS [witems] ON [priceList].item = [witems].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (witems.[fullName] LIKE %s OR witems.[shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[priceList].', ['witems.fullName', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * class FormPriceListPoints
 */
class FormPriceListPoints extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('item');
			$this->addColumnInput ('pricePoints');
			$this->addColumnInput ('priceMoney');
      $this->addSeparator(self::coH4);
      $this->addColumnInput ('validFrom');
      $this->addColumnInput ('validTo');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailPriceListPoints
 */
class ViewDetailPriceListPoints extends TableViewDetail
{
}
