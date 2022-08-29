<?php

namespace e10doc\reporting;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableAnalysisGroups
 */
class TableAnalysisGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.reporting.analysisGroups', 'e10doc_reporting_analysisGroups', 'Skupiny Analýz účetního deníku');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewAnalysisGroups
 */
class ViewAnalysisGroups extends TableView
{
	var $analysisNdx = 0;

	public function init ()
	{
		$this->analysisNdx = $this->queryParam('analysis');
		$this->addAddParam('analysis', $this->analysisNdx);

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->toolbarTitle = ['text' => 'Skupiny', 'class' => 'h3'/*, 'icon' => 'system/iconMapMarker'*/];

		$this->setMainQueries ();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_reporting_analysisGroups] ';


		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND analysis = %i', $this->analysisNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		//$info = $this->table->getEnumsInfo ($item);

		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];

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
 * class ViewDetailAnalysisGroup
 */
class ViewDetailAnalysisGroup extends TableViewDetail
{
}


/**
 * class FormAnalysisGroup
 */
class FormAnalysisGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'formEvents'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addViewerWidget ('e10doc.reporting.analysisItems', 'form', ['analysisGroup' => $this->recData['ndx']] ?? 0, TRUE);
				$this->closeTab ();
				$this->openTab ();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
