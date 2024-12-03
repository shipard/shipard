<?php

namespace e10pro\emps\libs\dc;


/**
 * class DCWorkingHours
 */
class DCWorkingHours extends \Shipard\Base\DocumentCard
{
	protected function addDetail()
	{
		$whInfo = new \e10pro\emps\libs\WorkingHoursInfo($this->app());
		$whInfo->setWorkingHours($this->recData['ndx']);
		$whInfo->loadData();

		$whContent = $whInfo->weeklyContent;
		$whContent['pane'] = 'e10-pane e10-pane-table';
		$this->addContent('body', $whContent);
	}

	public function createContentBody ()
	{
		$this->addDetail();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
