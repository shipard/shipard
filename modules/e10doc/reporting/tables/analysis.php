<?php

namespace e10doc\reporting;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableAnalysis
 */
class TableAnalysis extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.reporting.analysis', 'e10doc_reporting_analysis', 'Analýzy účetního deníku');
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
 * class ViewAnalysis
 */
class ViewAnalysis extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_reporting_analysis] WHERE 1';

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
 * class ViewDetailAnalysis
 */
class ViewDetailAnalysis extends TableViewDetail
{
}


/**
 * Class FormAnalysis
 */
class FormAnalysis extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Skupiny', 'icon' => 'formEvents'];


		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
				$this->closeTab ();
				$this->openTab ();
					$this->addViewerWidget ('e10doc.reporting.analysisGroups', 'form', ['analysis' => $this->recData['ndx']], TRUE);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

