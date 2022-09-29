<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


use e10\Application, \e10\utils, \e10\str, e10\uiutils;


/**
 * Class ReportPlan
 * @package e10pro\zus
 */
class ReportPlan extends \E10\GlobalReport
{
	var $year = '';
	var $paperFormat = 'a3';

	var $dataTeachers = [];
	var $teachers = [];
	var $teacher = 0;

	var $dataOffices = [];
	var $offices = [];
	var $office = 0;
	var $rooms = [];

	function init()
	{
		if ($this->subReportId === '')
			$this->subReportId = 'weekly';

		// -- toolbar
		$this->addParam('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		if ($this->subReportId === 'teachers')
			$this->addParamTeachers();
		if ($this->subReportId === 'offices' || $this->subReportId === 'rooms')
			$this->addParamOffices();

		if ($this->subReportId === 'weekly')
			$this->addParam('switch', 'paperFormat', ['title' => 'Formát', 'switch' => ['a3' => 'A3', 'a4' => 'A4'], 'radioBtn' => 1, 'defaultValue' => 'a3']);

		parent::init();

		if ($this->subReportId === 'teachers' && $this->teacher === 0)
			$this->teacher = intval($this->reportParams ['teacher']['value']);
		if ($this->subReportId === 'offices' || $this->subReportId === 'rooms')
			$this->office = intval($this->reportParams ['localOffice']['value']);

		$this->year = $this->reportParams ['skolniRok']['value'];

		if ($this->subReportId === 'weekly')
			$this->paperFormat = $this->reportParams ['paperFormat']['value'];

		$this->setInfo('icon', 'reportTimeTable');
		$this->setInfo('title', 'Rozvrh hodin');
	}

	function createContent()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case 'weekly': $this->createContent_Weekly(); break;
			case 'teachers': $this->createContent_Teachers(); break;
			case 'offices': $this->createContent_Offices(); break;
			case 'rooms': $this->createContent_Rooms(); break;
		}
	}

	function createContent_Teachers()
	{
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$h = ['pobockaId' => 'Pobočka', 'zacatek' => 'Od', 'konec' => 'Do', 'vyukaNazev' => 'Výuka', 'predmetNazev' => 'Předmět', 'rocnik' => 'Ročník', 'ucebnaNazev' => 'Učebna'];
		foreach ($this->dataTeachers as $teacherNdx => $teacherPlan)
		{
			$t = [];

			$numDays = 5;
			for ($day = 0; $day < $numDays; $day++)
			{
				$dayRow = [
					'pobockaId' => utils::$dayNames[$day],
					'_options' => [
						'class' => 'subheader',
						'colSpan' => ['pobockaId' => 7]
					]
				];
				if (!isset($teacherPlan[$day]) || !count($teacherPlan[$day]))
					continue;
				$t[] = $dayRow;
				foreach ($teacherPlan[$day] as $tt)
				{
					if ($tt['sameRows'])
					{
						$tt['_options']['rowSpan']['pobockaId'] = $tt['sameRows'] + 1;
						$tt['_options']['rowSpan']['zacatek'] = $tt['sameRows'] + 1;
						$tt['_options']['rowSpan']['konec'] = $tt['sameRows'] + 1;
					}

					$t[] = $tt;
				}
			}

			if (!$this->teacher)
				$sheetTitle = str::substr($this->teachers[$teacherNdx]['lastName'], 0, 5) . str::substr($this->teachers[$teacherNdx]['firstName'], 0, 3);
			else
				$sheetTitle = $this->teachers[$teacherNdx]['fullName'];

			$this->setInfo('title', $this->teachers[$teacherNdx]['fullName']);

			$this->addContent([
				'table' => $t, 'header' => $h, 'title' => ['code' => uiutils::createReportContentHeader($this->app, $this->info)],
				'params' => ['newPage' => 2, 'sheetTitle' => $sheetTitle, 'tableClass' => 'e10-print-12pt']
			]);

			if ($this->teacher)
				$this->setInfo('title', $this->teachers[$this->teacher]['fullName']);
		}
	}

	function createContent_Offices()
	{
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$h = ['teacher' => '_Učitel', 'D0' => 'Pondělí', 'D1' => 'Úterý', 'D2' => 'Středa', 'D3' => 'Čtvrtek', 'D4' => 'Pátek'];
		foreach ($this->dataOffices as $officeNdx => $officePlan)
		{
			$t = [];

			foreach ($this->teachers as $teacherNdxMain => $teacherInfo)
			{
				$numDays = 5;
				for ($day = 0; $day < $numDays; $day++)
				{
					$dayId = 'D' . $day;
					$hoursItem = [];
					$lastHoursItemId = '';

					foreach ($officePlan[$day] as $tt)
					{
						$teacherNdx = $tt['ucitel'];
						if ($teacherNdxMain != $teacherNdx)
							continue;

						$hoursItemId = $tt['ucebnaNazev'];
						if ($lastHoursItemId !== $hoursItemId && $lastHoursItemId !== '')
						{
							$t[$teacherNdx][$dayId][] = ['text' => $hoursItem['zacatek'].' - '.$hoursItem['konec'], 'suffix' => $hoursItem['ucebnaNazev'], 'class' => 'block nowrap'];
							$hoursItem = [];
						}

						if (!isset($hoursItem['zacatek']))
						{
							$hoursItem['zacatek'] = $tt['zacatek'];
							$hoursItem['ucebnaNazev'] = $tt['ucebnaNazev'];
						}

						$hoursItem['konec'] = $tt['konec'];
						$lastHoursItemId = $hoursItemId;

						$t[$teacherNdx]['teacher'] = $this->teachers[$teacherNdx]['fullName'];
					}
					if (count($hoursItem))
						$t[$teacherNdxMain][$dayId][] = ['text' => $hoursItem['zacatek'].' - '.$hoursItem['konec'], 'suffix' => $hoursItem['ucebnaNazev'], 'class' => 'block nowrap'];
				}
			}
			if (!$this->office)
				$sheetTitle = str::substr($this->offices[$officeNdx]['shortName'], 0, 10);
			else
				$sheetTitle = $this->offices[$officeNdx]['fullName'];

			$this->setInfo('title', $this->offices[$officeNdx]['fullName']);
			$this->addContent([
				'table' => $t, 'header' => $h, 'title' => ['code' => uiutils::createReportContentHeader ($this->app, $this->info)],
				'params' => ['newPage' => 2, 'sheetTitle' => $sheetTitle, 'tableClass' => 'e10-print-12pt']
			]);
		}

		$this->paperOrientation = 'landscape';
	}

	function createContent_Offices_Long()
	{
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$h = ['teacher' => '_Učitel', 'D0' => 'Pondělí', 'D1' => 'Úterý', 'D2' => 'Středa', 'D3' => 'Čtvrtek', 'D4' => 'Pátek'];
		foreach ($this->dataOffices as $officeNdx => $officePlan)
		{
			$t = [];

			$lastTextId = '';
			foreach ($this->teachers as $teacherNdxMain => $teacherInfo)
			{
				$numDays = 5;
				for ($day = 0; $day < $numDays; $day++)
				{
					$dayId = 'D' . $day;
					foreach ($officePlan[$day] as $tt)
					{
						$teacherNdx = $tt['ucitel'];
						if ($teacherNdxMain != $teacherNdx)
							continue;

						$textId = $dayId.'-'.$teacherNdx.'-'.$tt['zacatek'].'-'.$tt['konec'].'-'.$tt['ucebnaNazev'];

						if ($textId !== $lastTextId) // sloučená hodnina dvou a více žáků?
							$t[$teacherNdx][$dayId][] = ['text' => $tt['zacatek'] . ' - ' . $tt['konec'], 'suffix' => $tt['ucebnaNazev'], 'class' => 'block nowrap'];

						$t[$teacherNdx]['teacher'] = $this->teachers[$teacherNdx]['fullName'];

						$lastTextId = $textId;
					}
				}
			}
			if (!$this->office)
				$sheetTitle = str::substr($this->offices[$officeNdx]['shortName'], 0, 10);
			else
				$sheetTitle = $this->offices[$officeNdx]['fullName'];
			$this->addContent([
				'table' => $t, 'header' => $h, 'title' => $this->offices[$officeNdx]['fullName'],
				'params' => ['newPage' => 2, 'sheetTitle' => $sheetTitle, 'tableClass' => 'e10-print-small']
			]);
		}

		$this->paperOrientation = 'landscape';
	}

	function createContent_Rooms()
	{
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$h = ['teacher' => '_Učitel', 'D0' => 'Pondělí', 'D1' => 'Úterý', 'D2' => 'Středa', 'D3' => 'Čtvrtek', 'D4' => 'Pátek'];
		foreach ($this->dataOffices as $officeNdx => $officePlan)
		{
			foreach (\e10\sortByOneKey($this->rooms[$officeNdx], 'ucebnaNazev', TRUE) as $roomNdxMain => $roomInfo)
			{
				$t = [];

				$lastTextId = '';
				foreach ($this->teachers as $teacherNdxMain => $teacherInfo)
				{
					$numDays = 5;
					for ($day = 0; $day < $numDays; $day++)
					{
						$dayId = 'D' . $day;
						foreach ($officePlan[$day] as $tt)
						{
							if ($roomNdxMain != $tt['ucebna'])
								continue;
							$teacherNdx = $tt['ucitel'];
							if ($teacherNdxMain != $teacherNdx)
								continue;

							$textId = $dayId . '-' . $teacherNdx . '-' . $tt['zacatek'] . '-' . $tt['konec'] . '-' . $tt['vyukaNazev'];

							if ($textId !== $lastTextId) // sloučená hodnina dvou a více žáků?
								$t[$teacherNdx][$dayId][] = ['text' => $tt['zacatek'] . ' - ' . $tt['konec'], 'suffix' => $tt['vyukaNazev'], 'class' => 'block nowrap'];

							$t[$teacherNdx]['teacher'] = $this->teachers[$teacherNdx]['fullName'];

							$lastTextId = $textId;
						}
					}
				}
				if (!count($t))
					continue;
				if (!$this->office)
					$sheetTitle = str::substr($this->offices[$officeNdx]['shortName'], 0, 10);
				else
					$sheetTitle = $this->offices[$officeNdx]['fullName'];

				$this->setInfo('title', $this->offices[$officeNdx]['fullName'].(($roomInfo['ucebnaNazev'] != '') ? ' - '.$roomInfo['ucebnaNazev']:''));
				$this->addContent([
					'table' => $t, 'header' => $h, 'title' => ['code' => uiutils::createReportContentHeader ($this->app, $this->info)],
					'params' => ['newPage' => 2, 'sheetTitle' => $sheetTitle, 'tableClass' => 'e10-print-12pt']
				]);
			}
		}

		$this->paperOrientation = 'landscape';
	}

	function createContent_Weekly()
	{
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$t = [];
		$h = ['teacher' => '_Učitel', 'D0' => 'Pondělí', 'D1' => 'Úterý', 'D2' => 'Středa', 'D3' => 'Čtvrtek', 'D4' => 'Pátek'];
		foreach ($this->dataTeachers as $teacherNdx => $teacherPlan)
		{
			$tp = ['teacher' => $this->teachers[$teacherNdx]['fullName'], 'D0' => [], 'D1' => [], 'D2' => [], 'D3' => [], 'D4' => []];
			$numDays = 5;
			for ($day = 0; $day < $numDays; $day++)
			{
				$dayId = 'D'.$day;
				$hoursItem = [];
				$lastHoursItemId = '';
				foreach ($teacherPlan[$day] as $tt)
				{
					$hoursItemId = $teacherNdx.'-'.$tt['pobockaId'];
					if ($lastHoursItemId !== $hoursItemId && $lastHoursItemId !== '')
					{
						$tp[$dayId][] = ['text' => $hoursItem['zacatek'].' - '.$hoursItem['konec'], 'suffix' => $hoursItem['pobockaId'], 'class' => 'block nowrap'];
						$hoursItem = [];
					}

					if (!isset($hoursItem['zacatek']))
					{
						$hoursItem['zacatek'] = $tt['zacatek'];
						$hoursItem['pobockaId'] = $tt['pobockaId'];
					}

					$hoursItem['konec'] = $tt['konec'];
					$lastHoursItemId = $hoursItemId;
				}
				if (count($hoursItem))
					$tp[$dayId][] = ['text' => $hoursItem['zacatek'].' - '.$hoursItem['konec'], 'suffix' => $hoursItem['pobockaId'], 'class' => 'block nowrap'];
			}
			$t[] = $tp;
		}
		$this->addContent([
			'table' => $t, 'header' => $h, 'title' => ['code' => uiutils::createReportContentHeader ($this->app, $this->info)],
			'params' => ['newPage' => 2, 'tableClass' => 'e10-print-small']
		]);

		if ($this->paperFormat === 'a3')
		{
			$this->paperOrientation = 'portrait';
			$this->paperFormat = 'A3';
		}
		else
		{
			$this->paperOrientation = 'landscape';
			$this->paperFormat = 'A4';
		}
	}

	function createContent_Weekly_Long()
	{
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$t = [];
		$h = ['teacher' => '_Učitel', 'D0' => 'Pondělí', 'D1' => 'Úterý', 'D2' => 'Středa', 'D3' => 'Čtvrtek', 'D4' => 'Pátek'];
		foreach ($this->dataTeachers as $teacherNdx => $teacherPlan)
		{
			$tp = ['teacher' => $this->teachers[$teacherNdx]['fullName'], 'D0' => [], 'D1' => [], 'D2' => [], 'D3' => [], 'D4' => []];
			$numDays = 5;
			for ($day = 0; $day < $numDays; $day++)
			{
				$dayId = 'D'.$day;
				$lastTextId = '';
				foreach ($teacherPlan[$day] as $tt)
				{
					$textId = $tt['zacatek'].' - '.$tt['konec'] . ' - '.$tt['pobockaId'];

					if ($textId !== $lastTextId) // sloučená hodnina dvou a více žáků?
						$tp[$dayId][] = ['text' => $tt['zacatek'].' - '.$tt['konec'], 'suffix' => $tt['pobockaId'], 'class' => 'block nowrap'];

					$lastTextId = $textId;
				}
			}
			$t[] = $tp;
		}
		$this->addContent([
			'table' => $t, 'header' => $h,
			'params' => ['newPage' => 2, 'tableClass' => 'e10-print-small']
		]);
		$this->paperOrientation = 'landscape';
		//$this->paperFormat = 'A3';
	}

	function loadData()
	{
		switch ($this->subReportId)
		{
			case 'weekly': $this->loadTimetable_Teachers(); break;
			case 'teachers': $this->loadTimetable_Teachers(); break;
			case 'offices': $this->loadTimetable_Offices(); break;
			case 'rooms': $this->loadTimetable_Offices(); break;
		}
	}

	public function loadTimetable_Teachers ()
	{
		$today = utils::today();
		$tpks = [];
		$teachersNdxs = [];
		if ($this->teacher)
			$teachersNdxs[] = $this->teacher;
		else
		{
			$enum = zusutils::ucitele($this->app, FALSE);
			$teachersNdxs = array_keys($enum);
		}

		foreach ($teachersNdxs as $teacherNdx)
		{
			$q = [];
			$q[] = 'SELECT rozvrh.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
			array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
			array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
			array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
			array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
			array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');
			array_push ($q, ' LEFT JOIN e10_persons_persons AS ucitele ON rozvrh.ucitel = ucitele.ndx');

			array_push ($q, ' WHERE 1');

			array_push ($q, ' AND (rozvrh.ucitel = %i', $teacherNdx,
														' OR vyuky.ucitel2 = %i', $teacherNdx,
											')');

			array_push ($q, ' AND vyuky.skolniRok = %s', $this->year);
			array_push ($q, ' AND rozvrh.stavHlavni <= 2');

			array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
			array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

			array_push ($q, ' ORDER BY rozvrh.den, rozvrh.zacatek, rozvrh.ndx');

			$lastTimeBegin = '_';
			$lastSameDayIndex = 0;
			$dayIndex = 0;
			$lastDay = 0;

			$rows = $this->db()->query ($q);
			foreach ($rows as $r)
			{
				if ($r['zacatek'] === $lastTimeBegin && $r['den'] === $lastDay)
					$this->dataTeachers[$teacherNdx][$r['den']][$lastSameDayIndex]['sameRows']++;
				else
					$lastSameDayIndex = $dayIndex;

				$item = [
					'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'], 'ucebnaNazev' => $r['ucebnaNazev'],
					'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'vyukaNazev' => $r['vyukaNazev'], 'predmetNazev' => $r['predmetNazev'],
					'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky'], 'zkratka'),
					'sameRows' => 0,
				];
				$this->dataTeachers[$teacherNdx][$r['den']][$dayIndex] = $item;

				if ($teacherNdx && !in_array($teacherNdx, $tpks))
					$tpks[] = $teacherNdx;

				$dayIndex++;
				$lastTimeBegin = $r['zacatek'];
				$lastDay = $r['den'];
			}
		}

		if (count($tpks))
			$this->loadTeachers($tpks);
	}

	function loadTeachers ($pks)
	{
		$q[] = 'SELECT * FROM [e10_persons_persons]';
		array_push($q, ' WHERE [ndx] IN %in', $pks);
		array_push($q, ' ORDER BY lastName, firstName, ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->teachers[$r['ndx']] = ['fullName' => $r['fullName'], 'firstName' => $r['firstName'], 'lastName' => $r['lastName']];
		}
	}

	function addParamTeachers ()
	{
		$enum = zusutils::ucitele($this->app, TRUE);
		if ($this->app->hasRole('uctl') && in_array($this->app()->userNdx(), $enum))
			$this->addParam ('switch', 'teacher', ['title' => 'Učitel', 'switch' => $enum, 'defaultValue' => $this->app->userNdx()]);
		else
			$this->addParam ('switch', 'teacher', ['title' => 'Učitel', 'switch' => $enum]);
	}

	public function loadTimetable_Offices ()
	{
		$today = utils::today();
		$q[] = 'SELECT rozvrh.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS ucitele ON rozvrh.ucitel = ucitele.ndx');

		array_push ($q, ' WHERE 1');

		if ($this->office)
			array_push ($q, ' AND rozvrh.pobocka = %i', $this->office);

		array_push ($q, ' AND vyuky.skolniRok = %s', $this->year);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		array_push ($q, ' ORDER BY rozvrh.den, rozvrh.zacatek, rozvrh.ndx');

		$opks = [];
		$tpks = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$officeNdx = $r['pobocka'];
			$teacherNdx = $r['ucitel'];
			$item = [
				'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'],
				'ucebna' => $r['ucebna'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'vyukaNazev' => $r['vyukaNazev'], 'predmetNazev' => $r['predmetNazev'],
				'ucitel' => $r['ucitel'],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky'], 'zkratka'),
			];
			$this->dataOffices[$officeNdx][$r['den']][] = $item;

			if (!isset($this->rooms[$officeNdx][$r['ucebna']]))
				$this->rooms[$officeNdx][$r['ucebna']] = ['ucebnaNazev' => $r['ucebnaNazev']];

			if ($officeNdx && !in_array($officeNdx, $opks))
				$opks[] = $officeNdx;
			if ($teacherNdx && !in_array($teacherNdx, $tpks))
				$tpks[] = $teacherNdx;
		}

		if (count($opks))
			$this->loadOffices($opks);
		if (count($tpks))
			$this->loadTeachers($tpks);
	}

	function loadOffices ($pks)
	{
		$q[] = 'SELECT ndx, fullName FROM [e10_base_places]';
		array_push($q, ' WHERE docStateMain < 4 AND placeType = %s', 'lcloffc');
		array_push($q, ' AND [ndx] IN %in', $pks);
		array_push($q, ' ORDER BY [fullName]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->offices[$r['ndx']] = ['fullName' => $r['fullName'], 'shortName' => $r['shortName']];
		}
	}

	function addParamOffices()
	{
		$enum = zusutils::pobocky($this->app, TRUE);
		$this->addParam ('switch', 'localOffice', ['title' => 'Pobočka', 'switch' => $enum]);
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'weekly', 'icon' => 'detailTimeTable', 'title' => 'Vše'];
		$d[] = ['id' => 'teachers', 'icon' => 'detailTeachers', 'title' => 'Učitelé'];
		$d[] = ['id' => 'offices', 'icon' => 'detailSchool', 'title' => 'Pobočky'];
		$d[] = ['id' => 'rooms', 'icon' => 'detailSchoolRoom', 'title' => 'Učebny'];

		return $d;
	}

	public function createReportContentHeader ($contentPart)
	{
		return '';
	}
}
