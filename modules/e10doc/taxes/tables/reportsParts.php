<?php

namespace e10doc\taxes;

use e10\DbTable, e10\TableForm, e10\utils, e10\json;
use \e10doc\debs\libs\spreadsheets\SpdBalanceSheet;
use \e10doc\debs\libs\spreadsheets\SpdStatement;

/**
 * Class TableReportsParts
 * @package e10doc\taxes
 */
class TableReportsParts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.taxes.reportsParts', 'e10doc_taxes_reportsParts', 'Části daňových přiznání', 1103);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$tableReports = $this->app()->table ('e10doc.taxes.reports');
		$reportRecData = $tableReports->loadItem ($recData['report']);
		$versionId = $tableReports->reportVersion($reportRecData);
		$pd = $this->partDefinition ($reportRecData, $versionId, $recData['partId']);


		$hdr ['info'][] = ['class' => 'info', 'value' => $reportRecData ['title']];
		$hdr ['info'][] = ['class' => 'h3', 'value' => $pd['title']];

		return $hdr;
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		$tableReports = $this->app()->table ('e10doc.taxes.reports');
		$reportRecData = $tableReports->loadItem ($recData['report']);
		$versionId = $tableReports->reportVersion($reportRecData);

		if (!$versionId)
			return;

		$pd = $this->partDefinition ($reportRecData, $versionId, $recData['partId']);
		if (!isset($pd['reportParams']))
			return;

		$reportPartData = ($recData['data'] != '') ? json_decode($recData['data'], TRUE) : [];
		$reportParamsData = ($reportRecData['params'] != '') ? json_decode($reportRecData['params'], TRUE) : [];
		foreach ($pd['reportParams'] as $key)
		{
			if (isset($reportPartData[$key]))
				$reportParamsData [$key] = $reportPartData[$key];
			else
			if (isset($reportParamsData [$key]))
				unset ($reportParamsData [$key]);
		}

		$updateItem = ['params' => json::lint($reportParamsData)];
		$this->db()->query ('UPDATE [e10doc_taxes_reports] SET ', $updateItem, ' WHERE [ndx] = %i', $recData['report']);
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		$tableReports = $this->app()->table ('e10doc.taxes.reports');
		$reportRecData = $tableReports->loadItem ($recData['report']);
		$versionId = $tableReports->reportVersion($reportRecData);

		if (!$versionId)
			return FALSE;

		$pd = $this->partDefinition ($reportRecData, $versionId, $recData['partId']);

		return $pd['fields'];
	}

	public function partDefinition ($reportRecData, $reportVersionId, $partId)
	{
		$reportVersion = $this->app()->cfgItem ('e10doc.taxes.reportTypes.'.$reportRecData['reportType'].'.versions.'.$reportVersionId, FALSE);
		if (!$reportVersion)
			return FALSE;

		$reportParamsData = ($reportRecData['params'] != '') ? json_decode($reportRecData['params'], TRUE) : [];

		if ($partId === 'att_balanceSheet')
		{
			$bsType = utils::cfgItem($reportParamsData, 'uv_rozsah_rozv', 'P');
			$bsDef = $this->app()->cfgItem ('e10.acc.balanceSheets.'.$reportVersion['balanceSheets'][$bsType]['version'].'.variants.'.$reportVersion['balanceSheets'][$bsType]['variant']);

			$spd = new SpdBalanceSheet ($this->app());
			$spd->spreadsheetId = $bsDef['spreadsheetId'];
			$spd->subColumnsSrcPrefix = 'balanceSheetK';
			$spd->createSubColumns();
			$spd->subColumns['columns'][] = ['id' => 'VARIANT', 'type' => 'string', 'len' => 10, 'name' => 'Rozsah', 'src' => $spd->subColumnsSrcPrefix.'.VARIANT'];

			return ['fields' => $spd->subColumns, 'title' => 'Rozvaha', 'resetAll' => 1];
		}
		if ($partId === 'att_statement')
		{
			$stType = utils::cfgItem($reportParamsData, 'uv_rozsah_vzz', 'P');
			$stDef = $this->app()->cfgItem ('e10.acc.statements.'.$reportVersion['statements'][$stType]['version'].'.variants.'.$reportVersion['statements'][$stType]['variant']);

			$spd = new SpdStatement ($this->app());
			$spd->spreadsheetId = $stDef['spreadsheetId'];
			$spd->subColumnsSrcPrefix = 'statementK';
			$spd->createSubColumns();
			$spd->subColumns['columns'][] = ['id' => 'VARIANT', 'type' => 'string', 'len' => 10, 'name' => 'Rozsah', 'src' => $spd->subColumnsSrcPrefix.'.VARIANT'];

			return ['fields' => $spd->subColumns, 'title' => 'Výkaz zisku a ztráty', 'resetAll' => 1];
		}

		$fn = __SHPD_MODULES_DIR__.'e10doc/taxes/config/'.$reportRecData['reportType'].'/'.$reportVersionId.'/'.$partId.'.json';
		$pd = utils::loadCfgFile($fn);
		return $pd;
	}
}


/**
 * Class FormReportPart
 * @package e10doc\taxes
 */
class FormReportPart extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		//$reportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->recData['reportType'], []);

		$this->openForm ();
			$this->addColumnInput ('partId', TableForm::coHidden);
			$this->addSubColumns('data');
		$this->closeForm ();
	}
}

