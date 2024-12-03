<?php

namespace e10pro\emps\libs;
use \Shipard\Utils\Utils;


class WorkingHoursInfo extends \Shipard\Base\Utility
{
  var $workingHoursNdx = 0;
  var $workingHoursRecData = NULL;

  var $dataWeekly = [];
  var $weeklyContent = ['table' => [], 'header' => []];

  public function setWorkingHours($workingHoursNdx)
  {
    $this->workingHoursNdx = $workingHoursNdx;
    $this->workingHoursRecData = $this->app()->loadItem($workingHoursNdx, 'e10pro.emps.workingHours');
  }

  public function loadData()
  {
    // -- weekly data
    $q = [];
    array_push($q, 'SELECT whr.*');
    array_push($q, ' FROM [e10pro_emps_workingHoursRows] AS whr');
    array_push($q, ' WHERE [workingHours] = %i', $this->workingHoursNdx);
    array_push($q, ' ORDER BY rowOrder');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $dow = $r['dow'];

      if (isset($this->dataWeekly[$dow]))
      {
        $this->dataWeekly[$dow]['cntHours1'] += $r['cntHours1'];
        $this->dataWeekly[$dow]['cntHours2'] += $r['cntHours2'];
        $this->dataWeekly[$dow]['times'][] = ['timeBegin' => $r['timeBegin'], 'timeEnd' => $r['timeEnd']];
      }
      else
      {
        $dayItem = [
          'times' => [['timeBegin' => $r['timeBegin'], 'timeEnd' => $r['timeEnd']]],
          'cntHours1' => $r['cntHours1'], 'cntHours2' => $r['cntHours2'],
        ];
        $this->dataWeekly[$dow] = $dayItem;
      }
    }

    $this->createWeeklyContent();
  }

  protected function createWeeklyContent()
  {
    $header = ['title' => '', 'd1' => '|Pondělí', 'd2' => '|Úterý', 'd3' => '|Středa', 'd4' => '|Čtvrtek', 'd5' => '|Pátek', 'total' => '|Celkem'];
    $table = [
      'wlt1' => ['title' => 'Přímá',    'd1' => 0.0, 'd2' => 0.0, 'd3' => 0.0, 'd4' => 0.0, 'd5' => 0.0, 'total' => 0.0],
      'wlt2' => ['title' => 'Nepřímá',  'd1' => 0.0, 'd2' => 0.0, 'd3' => 0.0, 'd4' => 0.0, 'd5' => 0.0, 'total' => 0.0],
      'wltSum' => ['title' => 'Celkem', 'd1' => 0.0, 'd2' => 0.0, 'd3' => 0.0, 'd4' => 0.0, 'd5' => 0.0, 'total' => 0.0],
      'whs' => ['title' => 'Pracovní doba'],
    ];

    for ($dow = 1; $dow <= 5; $dow++)
    {
      $dowColId = 'd'.$dow;
      $table['wlt1'][$dowColId] = $this->dataWeekly[$dow]['cntHours1'] ?? 0;
      $table['wlt2'][$dowColId] = $this->dataWeekly[$dow]['cntHours2'] ?? 0;
      $table['wlt1']['total'] += $this->dataWeekly[$dow]['cntHours1'] ?? 0;
      $table['wlt2']['total'] += $this->dataWeekly[$dow]['cntHours2'] ?? 0;
      $table['wltSum']['total'] += $this->dataWeekly[$dow]['cntHours1'] ?? 0;
      $table['wltSum']['total'] += $this->dataWeekly[$dow]['cntHours2'] ?? 0;

      $table['wltSum'][$dowColId] += $this->dataWeekly[$dow]['cntHours1'] ?? 0;
      $table['wltSum'][$dowColId] += $this->dataWeekly[$dow]['cntHours2'] ?? 0;

      foreach ($this->dataWeekly[$dow]['times'] as $t)
      {
        $whTimes = ['text' => $t['timeBegin'].' - '.$t['timeEnd'], 'class' => 'block'];
        if (!isset($table['whs'][$dowColId]))
          $table['whs'][$dowColId] = [$whTimes];
        else
         $table['whs'][$dowColId][] = $whTimes;
      }
    }

    $this->weeklyContent['table'] = $table;
    $this->weeklyContent['header'] = $header;
  }
}
