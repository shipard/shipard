<?php

namespace e10pro\zus\libs;
use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use e10pro\zus\zusutils;


/**
 * class AbsenceEngine
 */
class AbsenceEngine extends Utility
{
  var $absenceNdx = 0;
  var $absenceRecData = NULL;
  var $teacherNdx = 0;

  var $timetable = [];
  var $timeTableTable = [];
  var $timeTableHeader;


  public function setAbsence($absenceNdx)
  {
    $this->absenceNdx = $absenceNdx;
    $this->absenceRecData = $this->app()->loadItem($absenceNdx, 'e10pro.emps.absences');
    $this->teacherNdx = $this->absenceRecData['person'];
  }

  public function loadData()
  {
    $this->loadTimetable();
    $this->createContent();

    $this->timeTableHeader = [
    //  'date' => 'Datum', 'pobockaId' => 'Pobočka', 'zacatek' => 'Začátek', 'konec' => 'Konec'
      'pobockaId' => 'Pobočka', 'zacatek' => 'Od', 'konec' => 'Do',
      'vyukaNazev' => 'Výuka', 'predmetNazev' => 'Předmět',
      'ucebnaNazev' => 'Učebna'
    ];
  }

  protected function createContent()
  {

  }

	protected function loadTimetable ()
	{
    if (Utils::dateIsBlank($this->absenceRecData['dateBegin']) || Utils::dateIsBlank($this->absenceRecData['dateEnd']))
      return;

    $dateBegin = new \DateTime($this->absenceRecData['dateBegin']->format('Y-m-d'));
    $dateEnd = new \DateTime($this->absenceRecData['dateEnd']->format('Y-m-d'));
    $date = Utils::createDateTime($dateBegin);
    while(1)
    {
      $timeFrom = '';
      $timeTo = '';

      if ($date->format('Ymd') === $dateBegin->format('Ymd'))
        $timeFrom = $this->absenceRecData['dateBegin']->format ('H:i');
      if ($date->format('Ymd') === $dateEnd->format('Ymd'))
        $timeTo = $this->absenceRecData['dateEnd']->format ('H:i');

      if ($timeFrom === '00:00')
        $timeFrom = '';
      if ($timeTo === '00:00')
        $timeTo = '';

      $this->loadTimetableDay($date, $timeFrom, $timeTo);
      $date->add (new \DateInterval('P1D'));
      if ($date > $dateEnd)
        break;
    }
  }

	protected function loadTimetableDay ($date, $timeFrom, $timeTo)
	{
    $dow = intval($date->format('N')) - 1; // 0 = monday

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
		array_push ($q, ' AND vyuky.skolniRok = %s', zusutils::skolniRok($date));
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');
		array_push ($q, ' AND rozvrh.den = %i', $dow);

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $date);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $date);

		array_push ($q, ' ORDER BY rozvrh.den, rozvrh.zacatek, rozvrh.ndx');

		$lastTimeBegin = '_';
		$lastDay = 0;
		$lastSameDayIndex = 0;
		$dayIndex = 0;
		$rows = $this->db()->query ($q);

    $t = [];
		foreach ($rows as $r)
		{
      if ($timeFrom !== '')
      {
        $recTime = Utils::timeToMinutes($r['konec']);
        $time = Utils::timeToMinutes($timeFrom);
        if ($recTime < $time)
          continue;
      }
      if ($timeTo !== '')
      {
        $recTime = Utils::timeToMinutes($r['konec']);
        $time = Utils::timeToMinutes($timeTo);
        if ($recTime > $time)
          continue;
      }
			$item = [
				'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'vyukaNazev' => $r['vyukaNazev'], 'predmetNazev' => $r['predmetNazev'],
				'date' => Utils::datef($date, '%n %d'),
			];

			$t[] = $item;
		}
    if (!count($t))
      return;

    $dayTitle = Utils::datef($date, '%n %d');
    if ($timeFrom !== '' || $timeTo !== '')
    {
      $dayTitle .= ' - ';
      if ($timeFrom !== '')
        $dayTitle .= ' od '.$timeFrom;
      if ($timeTo !== '')
        $dayTitle .= ' do '.$timeTo;
    }

    $this->timeTableTable[] = [
      'pobockaId' => $dayTitle,
      '_options' => [
        'class' => 'subheader',
        'colSpan' => ['pobockaId' => 5],
      ]
    ];

    $t[count($t) - 1]['_options']['afterSeparator'] = 'separator';


    $this->timeTableTable = array_merge($this->timeTableTable, $t);

    //$cols = [/*'edit' => '', */'pobockaId' => 'Pobočka', 'zacatek' => 'Od', 'konec' => 'Do', 'vyukaNazev' => 'Výuka', 'predmetNazev' => 'Předmět', 'rocnik' => 'Ročník', 'ucebnaNazev' => 'Učebna'];
	}
}