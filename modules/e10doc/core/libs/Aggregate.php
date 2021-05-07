<?php

namespace e10doc\core\libs;
use \Shipard\Base\Utility;



class Aggregate extends Utility
{
	CONST periodDaily = 1, periodMonthly = 2;

	var $fiscalPeriod;
	var $centre = FALSE;
	var $maxResultParts = 5;

	var $period = self::periodMonthly;
	var $periodColumnName;
	var $units;
	var $currencies;
	var $operations;

	var $data = [];
	var $header = [];
	var $graphLegend = [];

	var $pieData = [];

	var $graphBar = [];
	var $graphLine = [];
	var $graphDonut = [];


	function setCentre ($centre)
	{
		$this->centre = $centre;
	}

	function setFiscalPeriod ($fiscalPeriod)
	{
		$this->fiscalPeriod = $fiscalPeriod;
	}

	function setReportPeriod ($reportPeriod)
	{
		$this->period = $reportPeriod;
	}

	function init ()
	{
		$this->units = $this->app->cfgItem ('e10.witems.units');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');
		$this->operations = $this->app->cfgItem ('e10.docs.operations');

		switch ($this->period)
		{
			case self::periodDaily:
				$this->periodColumnName = 'Datum';
				break;
			case self::periodMonthly:
				$this->periodColumnName = 'Měsíc';
				break;
		}
	}
}
