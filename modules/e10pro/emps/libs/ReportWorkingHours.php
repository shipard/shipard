<?php

namespace e10pro\emps\libs;


/**
 * ReportWorkingHours
 */
class ReportWorkingHours extends \e10doc\core\libs\reports\DocReportBase
{
	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.emps.workingHours';
		$this->reportTemplate = 'reports.modern.e10pro.emps.workingHours';

		parent::init();
	}

	public function loadData ()
	{
		$this->sendReportNdx = 5201;

		parent::loadData();
		$this->loadData_DocumentOwner ();

		parent::loadData();

		$this->data['whPerson'] = $this->app()->loadItem($this->recData['person'], 'e10.persons.persons');

		$whInfo = new \e10pro\emps\libs\WorkingHoursInfo($this->app());
		$whInfo->setWorkingHours($this->recData['ndx']);
		$whInfo->loadData();

		$whContent = $whInfo->weeklyContent;
		$whContent['params'] = ['tableClass' => 'default fullWidht workingHours'];
		$this->data['whRows'] = [$whContent];
	}
}
