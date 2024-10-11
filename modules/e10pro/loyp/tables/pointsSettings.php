<?php

namespace e10pro\loyp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TablePointsSettings
 */
class TablePointsSettings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.loyp.pointsSettings', 'e10pro_loyp_pointsSettings', 'Nastavení věrnostních bodů');
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
 * class ViewPointsSettings
 */
class ViewPointsSettings extends TableView
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

    if ($item['categoryName'])
      $listItem ['t1'] = $item['categoryName'];
    else
      $listItem ['t1'] = '--- všechny položky ---';

    $pts = $item['cntPoints'].' bodů za '.Utils::nf($item['perAmount']).' Kč';
    $listItem ['t2'] = $pts;

    $ft = utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);
		if ($ft !== '')
			$listItem['i2'] = ['text' => $ft, 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];// = '';
    array_push ($q, 'SELECT [points].*,');
    array_push ($q, ' [cats].fullName AS categoryName');
    array_push ($q, ' FROM [e10pro_loyp_pointsSettings] AS [points]');
    array_push ($q, ' LEFT JOIN [e10_witems_itemcategories] AS [cats] ON [points].witemCategory = [cats].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		//if ($fts != '')
		//	array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[points].', ['ndx']);
		$this->runQuery ($q);
	}
}


/**
 * class FormPointsSettings
 */
class FormPointsSettings extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('witemCategory');
			$this->addColumnInput ('cntPoints');
			$this->addColumnInput ('perAmount');

      $this->addColumnInput ('validFrom');
      $this->addColumnInput ('validTo');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailPointsSettings
 */
class ViewDetailPointsSettings extends TableViewDetail
{
}
