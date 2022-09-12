<?php

namespace e10doc\reporting;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableCalcReportsResults
 */
class TableCalcReportsResults extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.reporting.calcReportsResults', 'e10doc_reporting_calcReportsResults', 'Výsledky Vyúčtování');
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

		if ($columnId === 'resData')
		{
			$ownerRecData = $this->db()->query('SELECT ndx, calcReportType FROM [e10doc_reporting_calcReports] WHERE [ndx] = %i', $recData['report'])->fetch();
			if (!$ownerRecData)
				return FALSE;

			$calcReportType = $this->app()->cfgItem('e10doc.reporting.calcReports.' . $ownerRecData['calcReportType'], NULL);

			if (!$calcReportType || !isset($calcReportType['srcRowData']['fields']))
				return FALSE;

			return $calcReportType['resRowData']['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		//$calcReport = $this->app()->loadItem($recData['report'], 'e10doc.reporting.calcReports');
		$info = [
			'title' => $recData['title'], 'docID' => '#'.$recData['ndx'],
		];

		if (isset($recData['workOrder']) && $recData['workOrder'])
		{
			$woRecData = $this->app()->loadItem($recData['workOrder'], 'e10mnf.core.workOrders');
			if ($woRecData && isset($woRecData['customer']) && $woRecData['customer'])
				$info ['persons']['to'][] = $woRecData['customer'];
		}

		$info ['persons']['from'][] = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));

		return $info;
	}
}


/**
 * class ViewCalcReportsResults
 */
class ViewCalcReportsResults extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->type = 'form';
		$this->objectSubType = TableView::vsMain;
		$this->enableDetailSearch = TRUE;
		$this->fullWidthToolbar = TRUE;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_reporting_calcReportsResults] WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, 'ORDER BY ndx');
		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = 'tady taky něco bude';

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * class ViewDetailCalcReportResult
 */
class ViewDetailCalcReportResult extends TableViewDetail
{
  public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.reporting.libs.dc.CalcReportsResults');
	}
}


/**
 * class FormCalcReportResult
 */
class FormCalcReportResult extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		//$tabs ['tabs'][] = ['text' => 'Skupiny', 'icon' => 'formEvents'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					//$this->addColumnInput ('dateBegin');
					//$this->addColumnInput ('dateEnd');

					$this->addColumnInput ('report');
					$this->addColumnInput ('workOrder');

					$this->addSubColumns('resRowData');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

