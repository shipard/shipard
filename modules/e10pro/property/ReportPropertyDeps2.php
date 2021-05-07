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
		$this->reportId = 'e10pro.property.deps2';
		$this->reportTemplate = 'e10pro.property.deps2';
		$this->paperOrientation = 'portrait';
	}
}
