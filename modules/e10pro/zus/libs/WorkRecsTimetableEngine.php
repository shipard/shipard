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

  var $absences = [];
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

    $this->academicYear = strval($this->academicYear($this->date));
  }

  public function loadData()
  {
    $this->loadAbsences();
    $this->loadWorkrecs();
    $this->loadTimetable();

    $this->checkWorkRecs();
  }

  protected function loadAbsences()
  {
    $dateBegin = $this->date->format('Y-m-d').' 00:00:00';
    $dateEnd = $this->date->format('Y-m-d').' 23:59:59';
    $q = [];
    array_push($q, 'SELECT absences.*');
    array_push($q, ' FROM [e10pro_emps_absences] AS absences');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [dateBegin] <= %t', $dateEnd);
    array_push($q, ' AND [dateEnd] >= %t', $dateBegin);
    array_push($q, ' AND [person] = %i', $this->teacherNdx);
    //array_push($q, ' AND [docState] IN %in', [4000, 8000]);
    array_push($q, ' ORDER BY dateBegin, ndx');

    $rows = $this->db()->query($q);
    $cnt = 0;
    foreach ($rows as $r)
    {
      $absence = $r->toArray();
      //$absence['unused'] = 1;
      //$absence['index'] = $cnt;
      $this->absences[] = $absence;

      $cnt++;
    }
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
    $tableHodiny = $this->app->table('e10pro.zus.hodiny');

		$q = [];
    array_push ($q, 'SELECT rozvrh.*, pobocky.shortName AS pobockaId, vyuky.nazev AS vyukaNazev, vyuky.typ AS typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev');
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
				'ndx' => $r['ndx'],
        'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'zacatekMin' => Utils::timeToMinutes($r['zacatek']),
        'vyukaNazev' => [['text' => $r['vyukaNazev'], 'class' => '']],
				'sameRows' => 0,
			];

      $qh = [];
      array_push($qh,
					'SELECT hodiny.* FROM e10pro_zus_hodiny AS hodiny',
					' LEFT JOIN e10pro_zus_vyuky AS vyuky ON vyuky.ndx = hodiny.vyuka ',
					' WHERE hodiny.vyuka = %i', $r['vyuka'],
          ' AND hodiny.[datum] = %d', $this->date,
					' AND vyuky.[skolniRok] = %i', $this->academicYear,
      );
      $hourRows = $this->db()->query($qh)->fetch();
      if ($hourRows)
      {
        if ($hourRows['nahradniTermin'] == 1)
        {
          $item['vyukaNazev'][] = ['text' => 'Náhradní hodina: '.Utils::datef($hourRows['nahradaDatum'], '%k'), 'class' => 'label label-primary'];
          $item['_options']['class'] = 'e10-off';
          $item['replacedHourNdx'] = $hourRows['ndx'];
        }
        if ($hourRows['suplovani'] == 1)
        {
          $replacedByTeacher = $this->app()->loadItem($hourRows['suplujiciUcitel'], 'e10.persons.persons');
          $item['vyukaNazev'][] = ['text' => 'Supluje '.($replacedByTeacher['fullName'] ?? '???'), 'class' => 'label label-info'] ;
          $item['_options']['class'] = 'e10-off';
          $item['replacedTeacherNdx'] = $hourRows['suplujiciUcitel'];
        }
        if ($r['typVyuky'] == 1 && $hourRows['pritomnost'] != 1)
        { // individual
          $hourAttendanceTypes = $tableHodiny->columnInfoEnum ('pritomnost');
          $item['vyukaNazev'][] = ['text' => $hourAttendanceTypes[$hourRows['pritomnost']], 'class' => 'label ' . 'label-warning'];
        }
      }

      $wr = $this->searchWorkRec($r['zacatek'], $r['konec']);
      if (!$wr && !isset($item['replacedHourNdx']) && !isset($item['replacedTeacherNdx']))
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

      $absence = $this->searchAbsence($r['zacatek'], $r['konec']);
      if ($absence)
      {
        $item['vyukaNazev'][] = ['text' => 'Nepřítomnost učitele', 'class' => 'label label-danger'];
        //$item['_options']['class'] = 'e10-off';
      }

			$this->timetable[$r['den']][$dayIndex] = $item;

			$dayIndex++;
			$lastTimeBegin = $r['zacatek'];
			$lastDay = $r['den'];
		}

    // --------------
    $dow = intval($this->date->format('N')) - 1; // 0 = monday
		$q = [];
    array_push($q, 'SELECT hodiny.*, vyuky.nazev AS vyukaNazev, pobocky.shortName AS pobockaId');
    array_push($q, ' FROM e10pro_zus_hodiny AS hodiny');
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON vyuky.ndx = hodiny.vyuka ');
		array_push($q, ' LEFT JOIN e10_base_places AS pobocky ON hodiny.pobocka = pobocky.ndx');
		array_push($q, ' WHERE 1');

    array_push($q, ' AND (');
      array_push($q, ' (hodiny.[nahradniTermin] = %i', 1);
      array_push($q, ' AND hodiny.ucitel = %i', $this->teacherNdx);
      array_push($q, ' AND hodiny.[nahradaDatum] = %d)', $this->date);

      array_push($q, ' OR (hodiny.[suplovani] = %i', 1);
      array_push($q, ' AND hodiny.suplujiciUcitel = %i', $this->teacherNdx);
      array_push($q, ' AND hodiny.[datum] = %d)', $this->date);
    array_push($q, ')');

		array_push($q, ' AND vyuky.[skolniRok] = %i', $this->academicYear);
		array_push($q, ' ORDER BY hodiny.datum, hodiny.zacatek, hodiny.konec, hodiny.ndx');

		$lastTimeBegin = '_';
		$lastDay = 0;
		$lastSameDayIndex = 0;
		//$dayIndex = 0;
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['zacatek'] === $lastTimeBegin && $dow === $lastDay)
				$this->timetable[$dow][$lastSameDayIndex]['sameRows']++;
			else
				$lastSameDayIndex = $dayIndex;

			$item = [
				'ndx' => $r['ndx'],
        'pobocka' => $r['pobocka'],
        'pobockaId' => $r['pobockaId'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'zacatekMin' => Utils::timeToMinutes($r['zacatek']),
        'vyukaNazev' => [['text' => $r['vyukaNazev'], 'class' => '']],
				'sameRows' => 0,
			];

      if ($r['nahradniTermin'] == 1)
      {
        $item['zacatek'] = $r['nahradaZacatek'];
        $item['konec'] = $r['nahradaKonec'];
        $item['zacatekMin'] = Utils::timeToMinutes($r['nahradaZacatek']);
        $item['vyukaNazev'][] = ['text' => 'Náhrada z '.Utils::datef($r['datum'], '%k'), 'class' => 'label label-info'];
      }
      if ($r['suplovani'] == 1)
      {
        $replacedTeacher = $this->app()->loadItem($r['ucitel'], 'e10.persons.persons');
        $item['vyukaNazev'][] = ['text' => 'Suplování za '.($replacedTeacher['fullName'] ?? '???'), 'class' => 'label label-info'];
      }

      $wr = $this->searchWorkRec($item['zacatek'], $item['konec']);
      if (!$wr && !isset($item['replacedHourNdx']))
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
			$this->timetable[$dow][$dayIndex] = $item;

			$dayIndex++;
			$lastTimeBegin = $r['zacatek'];
			$lastDay = $dow;
		}

    if ($dayIndex > 0)
       $this->timetable[$dow] = \e10\sortByOneKey($this->timetable[$dow], 'zacatekMin', TRUE);


    // --
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
          {
            //if (!isset($tt['replacedHourNdx']))
             $wr['invalid'] = 1;
          }
          continue;
        }
        if ($wrEnd < $ttEnd)
        {
          if ($wrEnd < $ttEnd && !isset($wr['invalid']))
          {
            //if (!isset($tt['replacedHourNdx']))
             $wr['invalid'] = 2;
          }
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

  protected function searchAbsence($timeBegin, $timeEnd)
  {
    $dateTimeBeginStr = $this->date->format('Y-m-d').' '.$timeBegin.':00';
    $dateTimeBegin = Utils::createDateTime($dateTimeBeginStr, TRUE);
    $dateTimeEndStr = $this->date->format('Y-m-d').' '.$timeEnd.':00';
    $dateTimeEnd = Utils::createDateTime($dateTimeEndStr, TRUE);

    foreach ($this->absences as &$absence)
    {
      if ($absence['dateBegin'] > $dateTimeBegin)
      {
        continue;
      }
      if ($absence['dateEnd'] < $dateTimeEnd)
      {
        continue;
      }

      return $absence;
    }

    return NULL;
  }

	protected function academicYear ($d)
	{
		$m = intval($d->format('m'));
		$y = intval($d->format('Y'));
		if ($m <= 6)
			return $y - 1;
		return $y;
	}
}
