<?php

namespace e10pro\zus\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class WorkRecsTimetableEngine
 */
class WorkRecsTimetableEngine extends Utility
{
  var $srcViewerPK = '';
  var $date = NULL;
  var $teacherNdx;
  var $dow = 0;
  var $academicYear;

  var $workRecs = [];
  var $timetable = [];
  var $timetableTable = [];
  var $workRecsTable = [];

  var $invalid = 0;

  var $wrColors = ['#1B98E0', '#74d3aeff', '#dd9787ff', '#678d58ff', '#a6c48aff', '#f6e7cbff'];

  public function setParams($teacherNdx, $date, $srcViewerPK = '')
  {
    $this->srcViewerPK = $srcViewerPK;
    $this->teacherNdx = $teacherNdx;
    $this->date = Utils::createDateTime($date);
    $this->dow = intval($this->date->format('N')); // 1 = monday

    $this->academicYear = '2024';
  }

  public function loadData()
  {
    $this->loadWorkrecs();
    $this->loadTimetable();

    $this->checkWorkRecs();
  }

  protected function loadWorkrecs()
  {
    $q = [];
    array_push($q, 'SELECT workrecs.*');
    array_push($q, ' FROM [e10mnf_core_workRecs] AS workrecs');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [beginDate] = %d', $this->date);
    array_push($q, ' AND [person] = %i', $this->teacherNdx);
    array_push($q, ' AND [docState] IN %in', [4000, 8000]);
    array_push($q, ' ORDER BY beginTime, ndx');

    $rows = $this->db()->query($q);
    $cnt = 0;
    foreach ($rows as $r)
    {
      $wr = $r->toArray();
      $wr['unused'] = 1;
      $wr['index'] = $cnt;
      $this->workRecs[] = $wr;

      $cnt++;
    }
  }

	protected function loadTimetable ()
	{
		$today = utils::today();

		$q[] = 'SELECT rozvrh.*, pobocky.shortName AS pobockaId, vyuky.nazev AS vyukaNazev, vyuky.typ AS typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND (rozvrh.ucitel = %i', $this->teacherNdx,
													' OR vyuky.ucitel2 = %i', $this->teacherNdx,
										')');
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->academicYear);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');
		array_push ($q, ' AND rozvrh.den = %i', $this->dow - 1);

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $this->date);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $this->date);

		array_push ($q, ' ORDER BY rozvrh.den, rozvrh.zacatek, rozvrh.ndx');

		$lastTimeBegin = '_';
		$lastDay = 0;
		$lastSameDayIndex = 0;
		$dayIndex = 0;
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['zacatek'] === $lastTimeBegin && $r['den'] === $lastDay)
				$this->timetable[$r['den']][$lastSameDayIndex]['sameRows']++;
			else
				$lastSameDayIndex = $dayIndex;

			$item = [
				'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'vyukaNazev' => $r['vyukaNazev'], 'predmetNazev' => $r['predmetNazev'],
				'sameRows' => 0,
			];

      $wr = $this->searchWorkRec($r['zacatek'], $r['konec']);
      if (!$wr)
      {
        $item['invalid'] = 1;
        $this->invalid = 1;
        $item['_options']['cellCss']['pobockaId'] = 'border-left: 8px solid red'.';';
      }
      else
      {
        if (isset($this->wrColors[$wr['index']]))
          $item['_options']['cellCss']['pobockaId'] = 'border-left: 8px solid '.$this->wrColors[$wr['index']].';';

      }
			$this->timetable[$r['den']][$dayIndex] = $item;

			$dayIndex++;
			$lastTimeBegin = $r['zacatek'];
			$lastDay = $r['den'];
		}

		$numDays = 5;
		for ($day = 0; $day < $numDays; $day++)
		{
			$btns = [];
			$btns [] = ['text' => Utils::$dayNames[$day]];

			$dayRow = [
				'pobockaId' => $btns,
				'_options' => [
					'class' => 'subheader',
					'colSpan' => ['pobockaId' => 3],
          'cellCss' => ['pobockaId' => 'border-left: 8px solid #D0D0D0'.';']
				]
			];
			if (!isset($this->timetable[$day]) || !count($this->timetable[$day]))
				continue;
			$this->timetableTable[] = $dayRow;
			if (isset($this->timetable[$day]))
			{
				foreach ($this->timetable[$day] as $tt)
				{
					$tt['_options']['cellClasses'] = ['edit' => 'e10-icon'];

          if (isset($tt['invalid']))
          {
            $tt['_options']['cellClasses']['zacatek'] = 'e10-warning1';
            $tt['_options']['cellClasses']['konec'] = 'e10-warning1';
          }

					if ($tt['sameRows'])
					{
						$tt['_options']['rowSpan']['pobockaId'] = $tt['sameRows'] + 1;
						$tt['_options']['rowSpan']['zacatek'] = $tt['sameRows'] + 1;
						$tt['_options']['rowSpan']['konec'] = $tt['sameRows'] + 1;
					}

					$this->timetableTable[] = $tt;
				}
			}
		}
	}

  protected function searchWorkRec($timeBegin, $timeEnd)
  {
    $timeBeginMinutes = Utils::timeToMinutes($timeBegin);
    $timeEndMinutes = Utils::timeToMinutes($timeEnd);
    foreach ($this->workRecs as &$wr)
    {
      if (Utils::timeToMinutes($wr['beginTime']) > $timeBeginMinutes)
      {
        continue;
      }
      if (Utils::timeToMinutes($wr['endTime']) < $timeEndMinutes)
      {
        continue;
      }

      $wr['invalid'] = 0;
      return $wr;
    }

    return NULL;
  }

  protected function checkWorkRecs()
  {
    foreach ($this->workRecs as &$wr)
    {
      $item = [
        'rec' => [
          'text' => '#'.$wr['ndx'], 'docAction' => 'edit', 'pk' => $wr['ndx'], 'table' => 'e10mnf.core.workRecs',
          'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => 'default', 'data-srcobjectfakepk' => $this->srcViewerPK,
        ],
        'timeBegin' => $wr['beginTime'],
        'timeEnd' => $wr['endTime'],
      ];
      if (isset($this->wrColors[$wr['index']]))
        $item['_options']['cellCss']['rec'] = 'border-left: 8px solid '.$this->wrColors[$wr['index']].';';
      $this->workRecsTable[] = $item;
      $this->searchTimetable($wr);
    }
  }

  protected function searchTimetable(&$wr)
  {
    $wrBegin = Utils::timeToMinutes($wr['beginTime']);
    $wrEnd = Utils::timeToMinutes($wr['endTime']);

    foreach ($this->timetable as $dow => $dtt)
    {
      foreach ($dtt as $tt)
      {
        $ttBegin = Utils::timeToMinutes($tt['zacatek']);
        $ttEnd = Utils::timeToMinutes($tt['konec']);
        if ($wrBegin > $ttBegin)
        {
          if ($wrBegin < $ttEnd && !isset($wr['invalid']))
            $wr['invalid'] = 1;
          continue;
        }
        if ($wrEnd < $ttEnd)
        {
          if ($wrEnd < $ttEnd && !isset($wr['invalid']))
            $wr['invalid'] = 2;

          continue;
        }

        $wr['unused'] = 0;
        if (!isset($wr['invalid']))
          $wr['invalid'] = 0;
        return 1;
      }
    }

    return 0;
  }
}
