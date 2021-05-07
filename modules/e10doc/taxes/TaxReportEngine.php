<?php

namespace e10doc\taxes;

use \e10\utils, \e10\Utility;


/**
 * Class TaxReportEngine
 * @package e10doc\taxes
 */
class TaxReportEngine extends Utility
{
	var $taxReportId = '';
	var $taxReportType = NULL;

	/** @var  \e10\DbTable */
	var $tableReports;

	var $reportRecData = NULL;


	public function init ()
	{
		$this->tableReports = $this->app()->table('e10doc.taxes.reports');

		if ($this->taxReportId !== '')
			$this->taxReportType = $this->app()->cfgItem('e10doc.taxes.reportTypes.'.$this->taxReportId, NULL);
	}

	public function documentAdd ($recData){}
	public function documentRemove ($recData){}
	public function doDocument($recData) {}
	public function doRebuild($recData) {}

	public function createReport ($forDate, $taxReg)
	{
		$newReport = [
				'reportType' => $this->taxReportId, 'taxReg' => $taxReg,
				'owner' => intval($this->app()->cfgItem ('options.core.ownerPerson', 0)),
				'docState' => 1000, 'docStateMain' => 0
		];
		$this->checkNewReport($forDate, $newReport);

		$newNdx = $this->tableReports->dbInsertRec($newReport);
		$newTaxReport = $this->tableReports->loadItem($newNdx);

		// -- copy properties from prev tax report
		$prevTaxReport = $this->db()->query('SELECT * FROM [e10doc_taxes_reports] ',
				' WHERE [reportType] = %s', $this->taxReportId, ' AND taxReg = %i', $taxReg,
				' AND ndx < %i', $newNdx, ' ORDER BY ndx DESC LIMIT 1')->fetch();
		if ($prevTaxReport)
		{
			$q = [];
			$q[] = 'INSERT INTO [e10_base_properties]';
			array_push($q, ' ([property], [group], [subtype], ',
					'[tableid], [recid], [valueString], [valueNum], [valueMemo], ',
					'[valueDate], [note], [created])');
			array_push($q, ' SELECT src.[property], src.[group], src.[subtype], ',
					'%s, ', 'e10doc.taxes.reports', '%i, ', $newNdx, 'src.[valueString], src.[valueNum], src.[valueMemo], ',
					'src.[valueDate], src.[note], NOW()');
			array_push($q, ' FROM [e10_base_properties] AS src WHERE');
			array_push($q, ' src.[tableid] = %s', 'e10doc.taxes.reports', ' AND src.[recid] = %i', $prevTaxReport['ndx']);

			$this->db()->query($q);
		}

		return $newTaxReport;
	}

	public function checkNewReport ($forDate, &$recData)
	{
	}

	public function searchReport ($forDate, $taxReg)
	{
		$q[] = 'SELECT * FROM [e10doc_taxes_reports]';
		array_push($q, ' WHERE [reportType] = %s', $this->taxReportId, ' AND taxReg = %i', $taxReg);
		array_push($q, ' AND [datePeriodBegin] <= %d', $forDate);
		array_push($q, ' AND [datePeriodEnd] >= %d', $forDate);
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' ORDER BY ndx');

		$report = $this->db()->query($q)->fetch();
		if ($report)
			return $report->toArray();

		return $this->createReport($forDate, $taxReg);
	}

	public function validForDate ($forDate)
	{
		$forDateStr = $forDate->format('Y-m-d');

		if (isset($this->taxReportType['validFrom']) &&
				$this->taxReportType['validFrom'] !== '0000-00-00' &&
				$this->taxReportType['validFrom'] > $forDateStr)
			return FALSE;

		if (isset($this->taxReportType['validTo']) &&
				$this->taxReportType['validTo'] !== '0000-00-00' &&
				$this->taxReportType['validTo'] < $forDateStr)
			return FALSE;

		return TRUE;
	}
}
