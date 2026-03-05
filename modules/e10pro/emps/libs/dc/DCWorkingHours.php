<?php

namespace e10pro\emps\libs\dc;

use \Shipard\Utils\Utils;


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
		array_push($q, ' AND [docState] != %i', 9800);
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

	protected function addPlan()
	{
		if ($this->app->model()->module ('e10pro.zus') === FALSE)
			return;

		$now = new \DateTime();
		$academicYear = ($now->format('n') < 9) ? ($now->format('Y') - 1) : $now->format('Y');

		$validFrom = Utils::createDateTime($this->recData['validFrom']);
		if ($validFrom)
			$academicYear = ($validFrom->format('n') < 9) ? ($validFrom->format('Y') - 1) : $validFrom->format('Y');

		$plan = new \e10pro\zus\libs\PlanTeacher($this->app());
		$plan->getPlan($this->recData['person'], $academicYear);


		$header = [
			'pobockaId' => 'Pobočka',
			'zacatek' => 'Od',
			'konec' => 'Do',
			'vyukaNazev' => 'Výuka',
			'predmetNazev' => 'Předmět',
			//'rocnik' => 'Ročník',
			//'ucebnaNazev' => 'Učebna'
		];
		$table = $plan->plan->data;

		$this->addContent ([
			'pane' => 'e10-pane e10-pane-table',
			'paneTitle' => 'Rozvrh',
			'type' => 'table', 'table' => $table, 'header' => $header
		]);
	}

	public function createContentBody ()
	{
		$this->addDetail();
		$this->addOtherPersonDetails();
		$this->addPlan();
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
