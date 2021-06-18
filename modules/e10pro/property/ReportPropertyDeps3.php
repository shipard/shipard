<?php

namespace e10pro\property;


/**
 * Class ReportPropertyDeps3
 * @package e10pro\property
 */
class ReportPropertyDeps3 extends \e10pro\property\ReportPropertyDeps
{
	function init ()
	{
		parent::init();

		$this->reportId = 'reports.default.e10pro.property.deps3';
		$this->reportTemplate = 'reports.default.e10pro.property.deps3';
		$this->paperOrientation = 'portrait';
	}
}
