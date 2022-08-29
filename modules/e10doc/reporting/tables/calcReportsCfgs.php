<?php

namespace e10doc\reporting;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableCalcReportsCfgs
 */
class TableCalcReportsCfgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.reporting.calcReportsCfgs', 'e10doc_reporting_calcReportsCfgs', 'Konfigurace Vyúčtování');
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
		if ($columnId === 'settings')
		{
			$calcReportType = $this->app()->cfgItem('e10doc.reporting.calcReports.' . $recData['calcReportType'], NULL);

			if (!$calcReportType || !isset($calcReportType['settings']['fields']))
				return FALSE;

			return $calcReportType['settings']['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * class ViewCalcReportsCfgs
 */
class ViewCalcReportsCfgs extends TableView
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

		$q [] = 'SELECT * FROM [e10doc_reporting_calcReportsCfgs] WHERE 1';

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
 * class ViewDetailCalcReportCfg
 */
class ViewDetailCalcReportCfg extends TableViewDetail
{
}


/**
 * class FormCalcReportCfg
 */
class FormCalcReportCfg extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('title');
					$this->addColumnInput ('calcReportType');
          $this->addColumnInput ('period');
					$this->addSubColumns ('settings');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
