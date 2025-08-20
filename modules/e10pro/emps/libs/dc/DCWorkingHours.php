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
		$whContent['pane'] = 'e10-pane e10-pane-table e10-ds '.$whInfo->docStateClass;
		$whContent['paneTitle'] = $whInfo->title;
		$this->addContent('body', $whContent);
	}

	protected function addOtherPersonDetails()
	{
		$q = [];
		array_push($q, 'SELECT * FROM [e10pro_emps_workingHours]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [person] = %i', $this->recData['person']);
		array_push($q, ' AND [workingHoursKind] = %i', $this->recData['workingHoursKind']);
		array_push($q, ' AND [ndx] != %i', $this->recData['ndx']);
		array_push($q, ' ORDER BY [validFrom] DESC');
		$rows = $this->app()->db()->query($q);
		foreach ($rows as $r)
		{
			$whInfo = new \e10pro\emps\libs\WorkingHoursInfo($this->app());
			$whInfo->setWorkingHours($r['ndx']);
			$whInfo->loadData();

			$whContent = $whInfo->weeklyContent;
			$whContent['pane'] = 'e10-pane e10-pane-table e10-ds '.$whInfo->docStateClass;
			$whContent['paneTitle'] = $whInfo->title;
			$whContent['paneTitle'][] = [
				'text' => '', 'class' => 'pull-right', 'docAction' => 'edit', 'icon' => 'system/actionOpen',
				'table' => 'e10pro.emps.workingHours', 'pk' => $r['ndx'],
				'actionClass' => 'btn btn-default btn-xs', ];
			$this->addContent('body', $whContent);
		}
	}


	public function createContentBody ()
	{
		$this->addDetail();
		$this->addOtherPersonDetails();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
