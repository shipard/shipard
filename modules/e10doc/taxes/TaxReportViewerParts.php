<?php

namespace e10doc\taxes;


use \e10\utils, \e10\uiutils, \e10\TableView;


/**
 * Class TaxReportViewerParts
 * @package e10doc\taxes
 */
class TaxReportViewerParts extends TableView
{
	var $ownerReportNdx = 0;
	var $ownerReportRecData = 0;
	var $ownerReportVersionId;
	var $ownerReportVersion;
	/** @var  \e10doc\taxes\TableReports */
	var $tableReports;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->disableFullTextSearchInput = TRUE;

		$this->setPaneMode();

		if ($this->queryParam ('reportNdx'))
			$this->ownerReportNdx = intval ($this->queryParam ('reportNdx'));

		parent::init();

		$mq [] = ['id' => 'active', 'title' => 'Aktuální stav'];
		$this->setMainQueries ($mq);

		$this->tableReports = $this->app()->table ('e10doc.taxes.reports');
		$this->ownerReportRecData = $this->tableReports->loadItem ($this->ownerReportNdx);
		$this->ownerReportVersionId = $this->tableReports->reportVersion($this->ownerReportRecData);
		$this->ownerReportVersion = $this->tableReports->reportVersion($this->ownerReportRecData, TRUE);

		if ($this->ownerReportVersion && isset($this->ownerReportVersion['partsGroups']))
		{
			$bt = [];
			$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 1];
			forEach ($this->ownerReportVersion['partsGroups'] as $pgId => $pg)
				$bt [] = ['id' => $pgId, 'title' => $pg['title'], 'active' => 0];
			$bt [] = ['id' => 'RECAP', 'title' => 'Podání', 'active' => 0];
			$this->setBottomTabs($bt);
		}
	}

	public function selectRows ()
	{
		$pgId = $this->bottomTabId ();
		if ($pgId === 'RECAP')
		{
			$this->queryRows = [];
			$this->ok = 1;

			return;
		}

		$q = [];

		// -- messages
		array_push ($q, 'SELECT * FROM [e10doc_taxes_reportsParts] AS parts ');
		array_push ($q, ' WHERE 1');

		if ($this->ownerReportNdx)
			array_push ($q, ' AND parts.[report] = %i', $this->ownerReportNdx);

		// -- parts groups
		if ($pgId !== '' && $pgId !== '0')
		{
			$pg = $this->ownerReportVersion['partsGroups'][$pgId];
			array_push ($q, ' AND parts.[partId] in %in', $pg['parts']);
		}

		array_push ($q, ' ORDER BY [order] ' . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}

	function renderPane (&$item)
	{
		$pd = $this->table->partDefinition ($this->ownerReportRecData, $this->ownerReportVersionId, $item['partId']);
		$data = json_decode($item['data'], TRUE);

		$dataCode = uiutils::renderSubColumns ($this->app(), $data, $pd['fields']);

		$paneClass = 'e10-pane e10-pane-dataSet';

		$item['pk'] = $item['ndx'];
		$title = [];

		$title[] = [
			'actionClass' => 'btn btn-primary', 'class' => 'pull-right', 'icon' => 'icon-edit', 'type' => 'button',
			'text' => 'Upravit',
			'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'e10doc.taxes.reportsParts',
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
		];
		$title[] = ['class' => 'h3 ', 'text' => $pd['title']];

		$item ['pane'] = ['class' => $paneClass];
		$item ['pane']['title'][] = ['value' => $title];


		$item ['pane']['body'][] = ['value' => [['code' => $dataCode]], 'class' => 'padd5'];
	}

	function createStaticContent()
	{
		$pgId = $this->bottomTabId ();
		if ($pgId !== 'RECAP')
			return;

		$c = "<div class='e10-pane e10-pane-table' style='margin: 1ex;'>";


		$fiscalYearNdx = $this->ownerReportRecData['accPeriod'];
		$params = 'taxReport='.$this->ownerReportNdx.'&filing=0&subReportId=preview&fiscalYear='.$fiscalYearNdx;

		$printButton = [
			'text' => 'Tisk', 'icon' => 'icon-print',
			'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print',
			'data' => ['report-class' => 'e10doc.taxes.TaxCI.TaxCIReport'],
			'data-params' => $params
		];
		$c .= $this->app()->ui()->composeTextLine($printButton);

		$printButton = [
			'text' => 'Uložit jako XML soubor pro EPO', 'icon' => 'icon-download',
			'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print',
			'data' => ['report-class' => 'e10doc.taxes.TaxCI.TaxCIReport'],
			'data-params' => $params,
			'data-format' => 'xml'
		];
		$c .= $this->app()->ui()->composeTextLine($printButton);

		$c .= '</div>';

		$this->objectData ['staticContent'] = $c;
	}
}
