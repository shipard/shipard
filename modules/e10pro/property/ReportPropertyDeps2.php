<?php

namespace e10pro\property;


/**
 * Class ReportPropertyDeps2
 * @package e10pro\property
 */
class ReportPropertyDeps2 extends \e10pro\property\ReportPropertyDeps
{
	function init ()
	{
		parent::init();

		$this->reportId = 'reports.default.e10pro.property.deps2';
		$this->reportTemplate = 'reports.default.e10pro.property.deps2';
		$this->paperOrientation = 'portrait';
	}
}
