<?php

namespace e10doc\taxes;

use \e10\utils, \e10\Utility;


/**
 * Class TaxReportProperties
 * @package e10doc\taxes
 */
class TaxReportProperties extends Utility
{
	var $tableTaxReports;
	var $taxReportNdx = 0;
	var $taxReportRecData = NULL;

	var $tableFilings;
	var $filingRecData = NULL;
	var $filingNdx = 0;

	var $properties = [];

	public function load ($taxReportNdx, $filingNdx = 0)
	{
		$this->properties = [];

		$this->taxReportNdx = $taxReportNdx;
		$this->filingNdx = $filingNdx;

		$this->tableTaxReports = $this->app->table('e10doc.taxes.reports');
		$this->taxReportRecData = $this->tableTaxReports->loadItem ($this->taxReportNdx);

		$this->tableFilings = $this->app->table('e10doc.taxes.filings');
		$this->filingRecData = $this->tableFilings->loadItem ($this->filingNdx);
	}

	public function name()
	{
		return '';
	}

	public function details(&$details)
	{
	}
}
