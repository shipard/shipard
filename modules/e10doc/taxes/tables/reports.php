<?php

namespace e10doc\taxes;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable, \e10\TableViewDetail, \Shipard\Viewer\TableViewPanel;


/**
 * Class TableReports
 * @package e10doc\taxes
 */
class TableReports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.reports', 'e10doc_taxes_reports', 'Daňová přiznání a přehledy', 1102);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['title']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		$reportVersion = $this->reportVersion ($recData, TRUE);

		if (!$reportVersion)
			return;

		if (!isset($reportVersion['parts']))
			return;

		// -- parts
		$q[] = 'SELECT ndx, partId, [order] FROM [e10doc_taxes_reportsParts]';
		array_push ($q, ' WHERE [report] = %i', $recData['ndx']);
		array_push ($q, ' AND [filing] = %i', 0);

		$existedParts = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
			$existedParts[$r['partId']] = ['ndx' => $r['ndx'], 'order' => $r['order']];

		foreach ($reportVersion['parts'] as $partIdx => $partId)
		{
			if (isset($existedParts[$partId]))
			{

				continue;
			}

			$order = ($partIdx + 1) * 1000;
			$newItem = ['report' => $recData['ndx'], 'partId' => $partId, 'filing' => 0, 'order' => $order];
			$this->db()->query ('INSERT INTO [e10doc_taxes_reportsParts] ', $newItem);
		}

		// -- data
		$q = [];
		$q[] = 'SELECT ndx FROM [e10doc_taxes_reportsData]';
		array_push ($q, ' WHERE [report] = %i', $recData['ndx']);
		array_push ($q, ' AND [filing] = %i', 0);

		$existedData = $this->db()->query($q)->fetch();
		if (!$existedData)
		{
			$newItem = ['report' => $recData['ndx'], 'filing' => 0];
			$this->db()->query ('INSERT INTO [e10doc_taxes_reportsData] ', $newItem);
		}
	}

	function propertyEnabled ($recData, $groupId, $propertyId, $property, $loadedProperties)
	{
		if ($groupId === 'e10-CZ-TR-subjekt' && in_array($propertyId, ['e10-CZ-TR-prijmeni', 'e10-CZ-TR-jmeno', 'e10-CZ-TR-titul']))
		{
			if ($loadedProperties['e10-CZ-TR-subjekt']['e10-CZ-TR-typSubjektu'][0]['value'] === 'P')
				return FALSE;
			return TRUE;
		}
		if ($groupId === 'e10-CZ-TR-subjekt' && $propertyId === 'e10-CZ-TR-jmenoPrOsoby')
		{
			if ($loadedProperties['e10-CZ-TR-subjekt']['e10-CZ-TR-typSubjektu'][0]['value'] === 'F')
				return FALSE;
			return TRUE;
		}

		if ($groupId === 'e10-CZ-TR-podOsoba' && in_array($propertyId, ['e10-CZ-TR-prijmeni', 'e10-CZ-TR-jmeno', 'e10-CZ-TR-datumNar', 'e10-CZ-TR-evidCislo']))
		{
			if ($loadedProperties['e10-CZ-TR-podOsoba']['e10-CZ-TR-typPodOsoba'][0]['value'] !== 'F')
				return FALSE;
			return TRUE;
		}
		if ($groupId === 'e10-CZ-TR-podOsoba' && in_array($propertyId, ['e10-CZ-TR-nazevPrOsoby', 'e10-CZ-TR-ICPrOsoby']))
		{
			if ($loadedProperties['e10-CZ-TR-podOsoba']['e10-CZ-TR-typPodOsoba'][0]['value'] !== 'P')
				return FALSE;
			return TRUE;
		}
		if ($groupId === 'e10-CZ-TR-podOsoba' && in_array($propertyId, ['e10-CZ-TR-kodPodOsoba']))
		{
			if ($loadedProperties['e10-CZ-TR-podOsoba']['e10-CZ-TR-typPodOsoba'][0]['value'] == '')
				return FALSE;
			return TRUE;
		}

		return TRUE;
	}

	public function reportVersion ($recData, $fullDef = FALSE)
	{
		if (!isset($recData['reportType']) || !isset($recData['datePeriodBegin']) || utils::dateIsBlank($recData['datePeriodBegin']))
			return FALSE;

		$reportType = $this->app()->cfgItem ('e10doc.taxes.reportTypes.'.$recData['reportType'], FALSE);
		if (!$reportType)
			return FALSE;

		if (!isset($reportType['versions']))
			return FALSE;

		$date = utils::createDateTime($recData['datePeriodBegin'])->format ('Y-m-d');
		foreach ($reportType['versions'] as $versionId => $versionDef)
		{
			if ($versionDef['validFrom'] !== '0000-00-00' && $date < $versionDef['validFrom'])
				continue;
			if ($versionDef['validTo'] !== '0000-00-00' && $date > $versionDef['validTo'])
				continue;

			if ($fullDef)
				return $versionDef;

			return $versionId;
		}

		return FALSE;
	}
}


/**
 * Class ViewReports
 * @package e10doc\taxes
 */
class ViewReports extends TableView
{
	var $reportsTypes;
	var $reportTypesParam;

	public function init ()
	{
		parent::init();
		$this->linesWidth = 33;
		$this->usePanelLeft = TRUE;

		$this->setMainQueries ();

		$this->reportsTypes = $this->app()->cfgItem('e10doc.taxes.reportTypes', []);

		$enum = [];
		$active = 1;
		$bt = [];
		forEach ($this->reportsTypes as $typeId => $type)
		{
			if (isset($type['enabledCfgItem']) && !$this->app()->cfgItem ($type['enabledCfgItem'], 0))
				continue;

			$addParams = ['reportType' => $typeId];
			$nbt = ['id' => $typeId, 'title' => $type['shortName'], 'active' => $active, 'addParams' => $addParams];
			$bt [] = $nbt;
			$active = 0;

			$enum[$typeId] = ['text' => $type['shortName'], 'addParams' => ['reportType' => $typeId]];
		}

		$this->reportTypesParam = new \E10\Params ($this->app);
		$this->reportTypesParam->addParam('switch', 'reportType', ['title' => '', 'switch' => $enum, 'list' => 1]);
		$this->reportTypesParam->detectValues();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$btId = $this->bottomTabId();

		$typeId = $this->reportTypesParam->detectValues()['reportType']['value'];

		$q [] = '(';
		array_push ($q, ' SELECT * FROM [e10doc_taxes_reports]');
		array_push ($q, ' WHERE docStateMain = 0');
		array_push ($q, ' AND [reportType] = %s', $typeId);
		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ( [title] LIKE %s', '%'.$fts.'%', ')');
		$this->queryMain ($q, '', ['[datePeriodBegin]', '[ndx]']);
		array_push ($q, ') UNION (');
		array_push ($q, ' SELECT * FROM [e10doc_taxes_reports]');
		array_push ($q, ' WHERE docStateMain != 0');
		array_push ($q, ' AND [reportType] = %s', $typeId);
		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ( [title] LIKE %s', '%'.$fts.'%', ')');
		$this->queryMain ($q, '', ['[datePeriodBegin] DESC', '[ndx]']);
		array_push ($q, ')');

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['title'];
		$listItem['t2'] = utils::datef($item['datePeriodBegin'], '%d').' - '.utils::datef($item['datePeriodEnd'], '%d');
		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		$qry = [];
		$qry[] = ['style' => 'params', 'params' => $this->reportTypesParam];
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewDetailReport
 * @package e10doc\taxes
 */
class ViewDetailReport extends TableViewDetail
{
	public function createDetailContent()
	{
		$taxReportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->item['reportType'], NULL);

		if (!$taxReportType)
			return;

		$dc = $this->app()->createObject($taxReportType['documentCard']);
		$dc->setDocument($this->table(), $this->item);
		$dc->createContent();
		foreach ($dc->content['body'] as $cp)
			$this->addContent($cp);
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		$toolbar [] = [
				'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10doc.taxes.reports',
				'text' => 'Přegenerovat', 'data-class' => 'e10doc.taxes.TaxReportRebuildWizard', 'icon' => 'icon-refresh'
		];

		return $toolbar;
	} // createToolbar
}


/**
 * Class ViewDetailReportPreview
 * @package e10doc\taxes
 */
class ViewDetailReportPreview extends ViewDetailReport
{
	public function createDetailContent()
	{
		$taxReportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->item['reportType'], NULL);

		if (!$taxReportType)
			return;

		$report = $this->app()->createObject($taxReportType['report']);
		$report->taxReportNdx = $this->item['ndx'];
		$report->filingNdx = 0;
		$report->subReportId = 'preview';
		$report->createDetail();
		$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $report->objectData ['mainCode']]);
	}
}


/**
 * Class ViewDetailReportErrors
 * @package e10doc\taxes
 */
class ViewDetailReportErrors extends ViewDetailReport
{
	public function createDetailContent()
	{
		$taxReportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->item['reportType'], NULL);

		if (!$taxReportType)
			return;

		$report = $this->app()->createObject($taxReportType['report']);
		$report->taxReportNdx = $this->item['ndx'];
		$report->filingNdx = 0;
		$report->subReportId = 'errors';
		$report->createDetail();

		$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $report->objectData ['mainCode']]);
	}
}


/**
 * Class FormReport
 * @package e10doc\taxes
 */
class FormReport extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$reportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->recData['reportType'], []);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addSubColumns('headerInfo');
					$this->addList('properties', '', TableForm::loAddToFormLayout);
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('reportType');
					$this->addColumnInput ('datePeriodBegin');
					$this->addColumnInput ('datePeriodEnd');

					if (isset($reportType['periodType']) && $reportType['periodType'] === 'vat')
						$this->addColumnInput ('taxPeriod');
					elseif (isset($reportType['periodType']) && $reportType['periodType'] === 'fy')
						$this->addColumnInput ('accPeriod');
					elseif (isset($reportType['periodType']) && $reportType['periodType'] === 'fm')
						$this->addColumnInput ('accMonth');

					$this->addColumnInput ('taxReg');
					$this->addColumnInput ('title');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}

