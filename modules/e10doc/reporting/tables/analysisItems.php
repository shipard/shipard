<?php

namespace e10doc\reporting;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableAnalysisItems
 */
class TableAnalysisItems extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.reporting.analysisItems', 'e10doc_reporting_analysisItems', 'Položky Skupin Analýz účetního deníku');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['note']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['debsAccountId']];

		return $hdr;
	}
}


/**
 * class ViewAnalysisItems
 */
class ViewAnalysisItems extends TableView
{
	var $analysisGroupNdx = 0;

	public function init ()
	{
		$this->analysisGroupNdx = $this->queryParam('analysisGroup');
		$this->addAddParam('analysisGroup', $this->analysisGroupNdx);

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->toolbarTitle = ['text' => 'Účty', 'class' => 'h3'/*, 'icon' => 'system/iconMapMarker'*/];

		$this->setMainQueries ();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_reporting_analysisItems] ';


		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND analysisGroup = %i', $this->analysisGroupNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [debsAccountId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [note] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[debsAccountId]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		//$info = $this->table->getEnumsInfo ($item);

		$listItem ['t1'] = $item['debsAccountId'];
		$listItem ['t2'] = $item['note'];

		$props = [];
//		$props[] = ['text' => $info['spreadsheetTable'], 'class' => 'label label-default'];
//		$props[] = ['text' => $info['spreadsheetRow'], 'class' => 'label label-default'];
//		$props[] = ['text' => $info['spreadsheetCol'], 'class' => 'label label-default'];
//		$listItem ['t3'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * class ViewDetailAnalysisItem
 */
class ViewDetailAnalysisItem extends TableViewDetail
{
}


/**
 * class FormAnalysisItem
 */
class FormAnalysisItem extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);


		$this->openForm ();
			$this->addColumnInput ('debsAccountId');
			$this->addColumnInput ('note');
		$this->closeForm ();
	}
}

