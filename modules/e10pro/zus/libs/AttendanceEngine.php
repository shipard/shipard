<?php

namespace e10pro\zus\libs;


use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class AttendanceEngine
 */
class AttendanceEngine extends Utility
{
  var $personNdx = 0;
  var $periodBegin = NULL;
  var $periodEnd = NULL;

  var $workRecs = [];
  var $data = [];

  var ?\e10pro\emps\libs\WorkingHoursInfo $workingHoursInfo = NULL;
  var $workingHoursInfoNdx = 0;

  var $contentHeader = NULL;
  var $contentTable = NULL;

  var $totalSums = [
    'workRecsTimeLen' => 0,
    'workingHours' => 0.0,
  ];

  public function setParams($personNdx, $workingHoursInfoNdx, $periodBegin, $periodEnd)
  {
    $this->personNdx = $personNdx;
    $this->workingHoursInfoNdx = $workingHoursInfoNdx;
    $this->periodBegin = Utils::createDateTime($periodBegin);
    $this->periodEnd = Utils::createDateTime($periodEnd);
  }

  public function loadData()
  {
    $this->loadWorkingHoursInfo();

    $this->prepareDays();
    $this->loadCalendar();
    $this->loadWorkRecs();
  }

  protected function prepareDays()
  {
    $days = [];
    $dayBegin = new \DateTime($this->periodBegin->format('Y-m-d'));
    $dayEnd = new \DateTime($this->periodEnd->format('Y-m-d'));

    while ($dayBegin <= $dayEnd)
    {
      $this->initDay($dayBegin);
      $dayBegin->modify('+1 day');
    }

    return $days;
  }

  protected function initDay($date)
  {
    $dayId = $date->format('Y-m-d');

    if (isset($this->data[$dayId]))
      return;

    $this->data[$dayId] = [
      'used' => 0,
      'dayId' => $dayId,
      'dow' => intval($date->format('N')) - 1, // 0 = monday
      'monthDay' => $date->format('d'),
      'workRecs' => [], 'workRecsLabels' => [], 'workRecsTimeLen' => 0,
      //'workingHours' => 0.0,
    ];

    $this->data[$dayId]['dayTitle'] = [
      'text' => $this->data[$dayId]['monthDay'],
      'prefix' => Utils::$dayShortcuts[$this->data[$dayId]['dow']],
    ];
  }

  protected function loadWorkingHoursInfo()
  {
		$this->workingHoursInfo = new \e10pro\emps\libs\WorkingHoursInfo($this->app());
    //$this->workingHoursInfoNdx = $this->workingHoursInfo->searchWorkingHours($this->periodBegin, $this->periodEnd, $this->personNdx);
    //if ($this->workingHoursInfoNdx === 0)
    //  return;

		$this->workingHoursInfo->setWorkingHours($this->workingHoursInfoNdx);
		$this->workingHoursInfo->loadData();


  }

  protected function loadWorkRecs()
  {
    $q = [];
    array_push($q, 'SELECT workrecs.*');
    array_push($q, ' FROM [e10mnf_core_workRecs] AS workrecs');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [beginDate] >= %d', $this->periodBegin);
    array_push($q, ' AND [beginDate] <= %d', $this->periodEnd);
    array_push($q, ' AND [person] = %i', $this->personNdx);
    array_push($q, ' AND [docState] IN %in', [4000, 8000]);
    array_push($q, ' ORDER BY beginDate, beginTime, ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->initDay($r['beginDate']);
      $dayId = $r['beginDate']->format('Y-m-d');
      $this->data[$dayId]['used'] = 1;

      $wr = $r->toArray();
      $this->data[$dayId]['workRecs'][] = $wr;

      $class = 'label label-default';
      $allMinutes = Utils::minutesToTime($r['timeLen'] / 60);
			$wrLabel = [
				'text' => $r['beginTime'].' - '.$r['endTime'],
				'suffix' => $allMinutes,
				'icon' => 'system/iconClock', 'class' => $class,
			];
      $this->data[$dayId]['workRecsLabels'][] = $wrLabel;

      $this->data[$dayId]['workRecsTimeLen'] += $r['timeLen'];

      $this->totalSums['workRecsTimeLen'] += $r['timeLen'];
    }
  }

	protected function loadCalendar()
	{
		$q = [];
		array_push($q, 'SELECT events.*');
		array_push($q, ' FROM [wkf_events_events] AS events');
		array_push($q, ' LEFT JOIN [wkf_events_cals] AS cals ON events.calendar = cals.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND cals.noWork = %i', 1);
		array_push($q, ' AND (events.dateBegin <= %d', $this->periodEnd, ')');
		array_push($q, ' AND (events.dateEnd >= %d', $this->periodBegin, ')');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			//$this->noWorkDay = 1;

      $dayBegin = new \DateTime($r['dateBegin']->format('Y-m-d'));
      if ($dayBegin < $this->periodBegin)
        $dayBegin = new \DateTime($this->periodBegin->format('Y-m-d'));

      $dayEnd = new \DateTime($r['dateEnd']->format('Y-m-d'));
      if ($dayEnd > $this->periodEnd)
        $dayEnd = new \DateTime($this->periodEnd->format('Y-m-d'));

      while ($dayBegin <= $dayEnd)
      {
        $dayId = $dayBegin->format('Y-m-d');
        $this->data[$dayId]['noWork'] = 1;

        $eventLabel = [
          'text' => $r['title'],
          'icon' => 'system/iconCalendar',
          'class' => 'label label-warning',
        ];
        $this->data[$dayId]['workRecsLabels'][] = $eventLabel;

        $dayBegin->modify('+1 day');
      }
		}
	}

  public function createContent()
  {
    $this->contentHeader = [
      'dayTitle' => ' Den',
      'workRecsLabels' => 'Docházka',
      'wrHours' => ' Odp. hod.',
      'workingHours' => ' Prac. doba',
    ];

    foreach ($this->data as $dayId => &$dayData)
    {
      $rowEnabled = 1;
      $item = $dayData;

      if ($dayData['workRecsTimeLen'])
        $item['wrHours'] = round($dayData['workRecsTimeLen'] / 60 / 60, 2);
        //$item['wrHours'] = Utils::minutesToTime($dayData['workRecsTimeLen'] / 60);

      if (isset($this->workingHoursInfo->dataWeekly[$dayData['dow'] + 1]['cntHours1']) && $this->workingHoursInfo->dataWeekly[$dayData['dow'] + 1]['cntHours1'] > 0.0)
      {
        if (!intval($this->data[$dayId]['noWork'] ?? 0))
        {
          $item['workingHours'] = $this->workingHoursInfo->dataWeekly[$dayData['dow'] + 1]['cntHours1'];
          $this->totalSums['workingHours'] += $item['workingHours'];
        }
        $dayData['used'] = 1;
      }

      if ($dayData['used'])
        $this->contentTable[] = $item;
    }

    $sumRow = [
      'workRecsLabels' => 'Celkem',
      'wrHours' => round($this->totalSums['workRecsTimeLen'] / 60 / 60, 2),
      'workingHours' => $this->totalSums['workingHours'],
      '_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator',],
    ];
    $this->contentTable[] = $sumRow;

  }
}
