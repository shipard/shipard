<?php

namespace e10doc\reporting;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableCalcReportsRowsSD
 */
class TableCalcReportsRowsSD extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.reporting.calcReportsRowsSD', 'e10doc_reporting_calcReportsRowsSD', 'Řádkové podklady pro Vyúčtování');
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
		if ($columnId === 'srcRowData')
		{
			$ownerRecData = $this->db()->query('SELECT ndx, calcReportType FROM [e10doc_reporting_calcReports] WHERE [ndx] = %i', $recData['report'])->fetch();
			if (!$ownerRecData)
				return FALSE;

			$calcReportType = $this->app()->cfgItem('e10doc.reporting.calcReports.' . $ownerRecData['calcReportType'], NULL);

			if (!$calcReportType || !isset($calcReportType['srcRowData']['fields']))
				return FALSE;

			return $calcReportType['srcRowData']['fields'];

			return FALSE;
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}
}


/**
 * class ViewCalcReportsRowsSD
 */
class ViewCalcReportsRowsSD extends TableView
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

		$q [] = 'SELECT * FROM [e10doc_reporting_calcReportsRowsSD] WHERE 1';

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
		$listItem ['t2'] = 'něco tu bude';

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * class ViewDetailCalcReportRowsSD
 */
class ViewDetailCalcReportRowsSD extends TableViewDetail
{
}


/**
 * class FormCalcReportRowSD
 */
class FormCalcReportRowSD extends TableForm
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

					$this->addSubColumns('srcRowData');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

