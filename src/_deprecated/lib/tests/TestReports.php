<?php

namespace lib\tests;

use e10\utils;

/**
 * Class TestReports
 * @package lib\tests
 */
class TestReports extends \lib\tests\Test
{
	public function test ()
	{
		foreach ($this->testDefinition['reports'] as $reportDef)
		{
			foreach ($reportDef['cycles'] as $cycleId)
			{
				$report = $this->app()->createObject($reportDef['class']);
				$report->setTestCycle($cycleId, $this);
				$report->init();
				$report->createContent();

				$this->appendCycleContent ($report->testTitle());
			}
		}
	}

}

