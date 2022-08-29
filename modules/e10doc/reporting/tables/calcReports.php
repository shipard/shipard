<?php

namespace e10doc\reporting;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableCalcReports
 */
class TableCalcReports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.reporting.calcReports', 'e10doc_reporting_calcReports', 'Vyúčtování');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'srcHeaderData')
		{
			$calcReportType = $this->app()->cfgItem('e10doc.reporting.calcReports.' . $recData['calcReportType'], NULL);

			if (!$calcReportType || !isset($calcReportType['srcHeaderData']['fields']))
				return FALSE;

			return $calcReportType['srcHeaderData']['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * class ViewCalcReports
 */
class ViewCalcReports extends TableView
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

		$q [] = 'SELECT * FROM [e10doc_reporting_calcReports] WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [title] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[title]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		//$info = $this->table->getEnumsInfo ($item);

		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = 'něco tu bude';

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * class ViewDetailCalcReport
 */
class ViewDetailCalcReport extends TableViewDetail
{
	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		$toolbar [] = [
				'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10doc.taxes.reports',
				'text' => 'Přegenerovat', 'data-class' => 'e10doc.reporting.libs.CalcReportRebuildWizard', 'icon' => 'cmnbkpRegenerateOpenedPeriod'
		];

		return $toolbar;
	}
}


/**
 * class FormCalcReport
 */
class FormCalcReport extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Hlavička', 'icon' => 'formEvents'];
		$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'formEvents'];
		$tabs ['tabs'][] = ['text' => 'Výsledky', 'icon' => 'formEvents'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('title');
					$this->addColumnInput ('calcReportType');
					$this->addColumnInput ('calcReportCfg');
					$this->addColumnInput ('dateBegin');
					$this->addColumnInput ('dateEnd');
					$this->addColumnInput ('fiscalYear');
				$this->closeTab ();

				$this->openTab ();
					$this->addSubColumns('srcHeaderData');
				$this->closeTab ();

				$this->openTab (/*self::ltNone*/);
					$this->addViewerWidget ('e10doc.reporting.calcReportsRowsSD', 'default', ['calcReportNdx' => $this->recData['ndx']], TRUE);
				$this->closeTab ();

				$this->openTab ();
					$this->addViewerWidget ('e10doc.reporting.calcReportsResults', 'default', ['calcReportNdx' => $this->recData['ndx']], TRUE);
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}
