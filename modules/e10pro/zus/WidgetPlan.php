<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \E10\utils, \E10\Utility, \E10\uiutils, \E10Pro\Zus\zusutils;


/**
 * Class Plan
 * @package e10pro\zus
 */
class Plan extends Utility
{
	var $widgetId;
	var $teachers = [];
	var $year = '';

	public function setYear ($year)
	{
		$this->year = $year;
	}

	public function init ()
	{
		$this->loadTeachers();
	}

	public function loadTeachers ()
	{
		$this->teachers = zusutils::ucitele($this->app, FALSE);
	}
}


/**
 * Class PlanOverview
 * @package e10pro\zus
 */
class PlanOverview extends Plan
{
	var $timetable = [];
	var $popovers = [];

	public function init ()
	{
		parent::init();
	}

	public function load ()
	{
		$this->loadTimetable();
	}

	public function loadTimetable ()
	{
		$today = utils::today();

		$q[] = 'SELECT rozvrh.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->year);
		array_push ($q, ' AND vyuky.stavHlavni < %i', 4);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'vyukaNazev' => $r['vyukaNazev'], 'predmetNazev' => $r['predmetNazev'],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky'])
		];

			$this->timetable[$r['ucitel']][$r['den']][] = $item;
		}
	}

	public function renderPlan()
	{
		$numDays = 5;

		$c = "<div class='padd5'>";
		$c .= "<div style='overflow-y: scroll;'>";
		$c .= "<table class='e10-timetable main'>";
		$c .= '<thead><tr>';
		$c .= "<th>".utils::es('Učitel').'</th>';

		for ($day = 1; $day <= $numDays; $day++)
		{
			$class = 'day';
			$c .= "<th class='$class'>".utils::$dayShortcuts[$day - 1].'</th>';
		}
		$c .= '</tr></thead>';


		foreach ($this->teachers as $teacherNdx => $teacherName)
		{
			$c .= "<tr data-teacher='$teacherNdx'>";
			$c .= "<td>".utils::es ($teacherName).'</td>';

			for ($day = 0; $day < $numDays; $day++)
			{
				$class = 'day';
				$c .= "<td class='$class'>";

				$c .= $this->renderPlan_TeachersDay ($teacherNdx, $day);
/*
				$btns = [];
				$btns[] = [
					'text' => '', 'icon' => 'icon-plus-circle', 'action' => 'new', 'data-table' => 'e10pro.zus.vyukyrozvrh',
					'type' => 'button', 'actionClass' => 'btn btn-xs', 'class' => 'pull-right',
					'data-addparams' => '__ucitel='.$teacherNdx.'&__den='.$day,
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
				];
				$c .= $this->app()->ui()->composeTextLine($btns);
*/
				$c .= '</td>';
			}

			$c .= '</tr>';
		}
		$c .= '</table>';
		$c .= '</div>';
		$c .= '</div>';

		$c .= "<script>\n";
		$c .= "var ttPopovers = ".json_encode($this->popovers).";\n";
		$c .= "$('#e10dashboardWidget table.e10-timetable>tbody>tr>td.day>span.tt').popover({content:function(){return ttPopovers[$(this).attr('data-id')];}, html: true, trigger: 'focus', delay: {'show': 0, 'hide': 100}, container: 'body', placement: 'auto', viewport:'#e10dashboardWidget'});";
		$c .= "</script>\n";

		return $c;
	}

	function renderPlan_TeachersDay ($teacherNdx, $day)
	{
		if (!isset($this->timetable[$teacherNdx][$day]))
			return '';

		$tdid = $teacherNdx.'-'.$day;
		$popoverData = [];
		$c = '';

		$rows = [];
		foreach ($this->timetable[$teacherNdx][$day] as $tt)
		{
			$rows[$tt['pobocka']][] = $tt;
		}

		foreach ($rows as $pobockaNdx => $ttItems)
			$rows[$pobockaNdx] = \E10\sortByOneKey($ttItems, 'zacatek');

		$lineIdx = 0;
		foreach ($rows as $pobockaNdx => $ttItems)
		{
			$idx = 0;
			$line = ['class' => 'tag tt', 'data' => ['id' => $tdid], 'focusable' => 1];
			foreach ($ttItems as $tt)
			{
				if ($idx === 0)
					$line ['text'] = $tt['zacatek'].' - ';

				$poItem = [
					'begin' => $tt['zacatek'], 'end' => $tt['konec'], 'title' => $tt['vyukaNazev'], 'predmetNazev' => $tt['predmetNazev'],
					'rocnik' => $tt['rocnik'], 'ucebna' => $tt['ucebnaNazev'], 'pobocka' => $tt['pobockaId'],
					/*'edit' => [
						'text' => '', 'icon' => 'icon-edit', 'docAction' => 'edit', 'table' => 'e10pro.zus.vyukyrozvrh',
						'type' => 'button', 'actionClass' => 'btn btn-xs', 'class' => 'pull-right',
						'pk' => $tt['ndx'],
						'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
					]*/
				];
				$popoverData[] = $poItem;

				$idx++;
			}
			$line ['text'] .= $tt['konec'];
			$line ['suffix'] = $tt['pobockaId'];

			$c .= $this->app()->ui()->renderTextLine($line);

			$lineIdx++;
			if ($lineIdx !== count($rows))
				$c .= '<br/>';
		}

		$title = [
			['text' => $this->teachers[$teacherNdx], 'icon' => 'x-teacher', 'class' => 'h2'],
			['text' => utils::$dayNames[$day], 'class' => 'h2 pull-right']
		];
		$popoverHtml = $this->app()->ui()->composeTextLine($title);
		$popoverCols = ['pobocka' => 'Pobočka', 'begin' => 'Od', 'end' => 'Do', 'title' => 'Výuka', 'predmetNazev' => 'Předmět', 'rocnik' => 'Ročník', 'ucebna' => 'Učebna'/*, 'edit' => ''*/];
		$popoverHtml .= $this->app->ui()->renderTableFromArray ($popoverData, $popoverCols);
		$this->popovers[$tdid] = $popoverHtml;

		return $c;
	}
}


/**
 * Class PlanTeacher
 * @package e10pro\zus
 */
class PlanTeacher extends Plan
{
	var $timetable = [];
	var $data = [];
	var $teacher = 0;

	public function init ()
	{
		parent::init();
	}

	public function setTeacher ($teacher)
	{
		$this->teacher = $teacher;
	}

	public function load ()
	{
		$this->loadTimetable();
	}

	public function loadTimetable ()
	{
		$today = utils::today();

		$q[] = 'SELECT rozvrh.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND (rozvrh.ucitel = %i', $this->teacher,
													' OR vyuky.ucitel2 = %i', $this->teacher,
										')');
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->year);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

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
				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky']),
				'sameRows' => 0
				/*'edit' => [
					'text' => '', 'icon' => 'icon-edit', 'docAction' => 'edit', 'table' => 'e10pro.zus.vyukyrozvrh',
					'type' => 'button', 'actionClass' => 'btn btn-xs',
					'pk' => $r['ndx'],
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
				]*/
			];
			$this->timetable[$r['den']][$dayIndex] = $item;

			$dayIndex++;
			$lastTimeBegin = $r['zacatek'];
			$lastDay = $r['den'];
		}

		$numDays = 5;
		for ($day = 0; $day < $numDays; $day++)
		{
			$btns = [];
			/*
			$btns[] = [
				'text' => '', 'icon' => 'icon-plus-circle', 'action' => 'new', 'data-table' => 'e10pro.zus.vyukyrozvrh',
				'type' => 'button', 'actionClass' => 'btn btn-xs', 'class' => '',
				'data-addparams' => '__ucitel='.$this->teacher.'&__den='.$day,
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
			];*/

			$btns [] = ['text' => utils::$dayNames[$day]];

			$dayRow = [
				/*'edit'*/'pobockaId' => $btns,
				'_options' => [
					'class' => 'subheader',
					//'beforeSeparator' => 'separator',
					'colSpan' => [/*'edit'*/'pobockaId' => 7]
				]
			];
			if (!isset($this->timetable[$day]) || !count($this->timetable[$day]))
				continue;
			$this->data[] = $dayRow;
			if (isset($this->timetable[$day]))
			{
				foreach ($this->timetable[$day] as $tt)
				{
					$tt['_options'] = [
					'cellClasses' => ['edit' => 'e10-icon'],
					];

					if ($tt['sameRows'])
					{
						$tt['_options']['rowSpan']['pobockaId'] = $tt['sameRows'] + 1;
						$tt['_options']['rowSpan']['zacatek'] = $tt['sameRows'] + 1;
						$tt['_options']['rowSpan']['konec'] = $tt['sameRows'] + 1;
					}

					$this->data[] = $tt;
				}
			}
		}
	}

	public function renderPlan()
	{
		$c = "<div class='padd5'>";
		$c .= "<div style='overflow-y: scroll; margin: .5ex;'>";
		$cols = [/*'edit' => '', */'pobockaId' => 'Pobočka', 'zacatek' => 'Od', 'konec' => 'Do', 'vyukaNazev' => 'Výuka', 'predmetNazev' => 'Předmět', 'rocnik' => 'Ročník', 'ucebnaNazev' => 'Učebna2'];
		$c .= $this->app->ui()->renderTableFromArray ($this->data, $cols);
		$c .= '</div>';
		$c .= '</div>';

		return $c;
	}
}

class PlanLocalOffice extends Plan
{
	var $timetable = [];
	var $popovers = [];
	var $localOffice = 0;
	var $room = 0;

	public function init ()
	{
		parent::init();
	}

	public function setLocalOffice ($lo, $room)
	{
		$this->localOffice = $lo;
		$this->room = $room;
	}

	public function load ()
	{
		$this->loadTimetable();
	}

	public function loadTeachers ()
	{
		$today = utils::today();

		$q = [];

		if ($this->room == 0)
		{
			/*
			$q[] = '(SELECT DISTINCT predmety.ucitel, ucitele.fullName FROM e10pro_zus_studiumpre AS predmety';
			array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON predmety.studium = studia.ndx');
			array_push($q, ' LEFT JOIN e10_persons_persons AS ucitele ON predmety.ucitel = ucitele.ndx');
			array_push($q, ' WHERE studia.skolniRok = %i', $this->year);
			array_push($q, ' AND studia.stavHlavni < %i', 4);
			array_push($q, ' AND studia.misto = %i)', $this->localOffice);

			array_push($q, ' UNION DISTINCT');
			*/
		}

		array_push ($q, '(');
		array_push ($q, ' SELECT DISTINCT rozvrh.ucitel, ucitele.fullName FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS ucitele ON rozvrh.ucitel = ucitele.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' WHERE vyuky.skolniRok = %s', $this->year);
		array_push ($q, ' AND vyuky.stavHlavni < %i', 4);
		array_push ($q, ' AND rozvrh.pobocka = %i', $this->localOffice);
		if ($this->room != 0)
			array_push ($q, ' AND rozvrh.ucebna = %i', $this->room);

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		array_push ($q, ')');

		array_push ($q, ' ORDER BY 2');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
			if ($r['ucitel'])
				$this->teachers[$r['ucitel']] = $r['fullName'];
	}

	public function loadTimetable ()
	{
		$today = utils::today();

		$q[] = 'SELECT rozvrh.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->year);
		array_push ($q, ' AND rozvrh.pobocka = %i', $this->localOffice);
		if ($this->room != 0)
			array_push ($q, ' AND rozvrh.ucebna = %i', $this->room);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'],
				'ucebna' => $r['ucebna'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'vyukaNazev' => $r['vyukaNazev'], 'predmetNazev' => $r['predmetNazev'],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky'])
			];

			$this->timetable[$r['ucitel']][$r['den']][] = $item;
		}
	}

	public function renderPlan()
	{
		$numDays = 5;

		$c = "<div class='padd5'>";
		$c .= "<div style='overflow-y: scroll;'>";
		$c .= "<table class='e10-timetable main'>";
		$c .= '<thead><tr>';
		$c .= "<th>".utils::es('Učitel').'</th>';

		for ($day = 1; $day <= $numDays; $day++)
		{
			$class = 'day';
			$c .= "<th class='$class'>".utils::$dayShortcuts[$day - 1].'</th>';
		}
		$c .= '</tr></thead>';


		foreach ($this->teachers as $teacherNdx => $teacherName)
		{
			$c .= "<tr data-teacher='$teacherNdx'>";
			$c .= "<td>".utils::es ($teacherName).'</td>';

			for ($day = 0; $day < $numDays; $day++)
			{
				$class = 'day';
				$c .= "<td class='$class'>";

				$c .= $this->renderPlan_TeachersDay ($teacherNdx, $day);

				/*
				$btns = [];
				$btns[] = [
					'text' => '', 'icon' => 'icon-plus-circle', 'action' => 'new', 'data-table' => 'e10pro.zus.vyukyrozvrh',
					'type' => 'button', 'actionClass' => 'btn btn-xs', 'class' => 'pull-right',
					'data-addparams' => '__ucitel='.$teacherNdx.'&__den='.$day.'&__pobocka='.$this->localOffice,
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
				];
				$c .= utils::composeTextLine($btns);
				*/

				$c .= '</td>';
			}

			$c .= '</tr>';
		}
		$c .= '</table>';
		$c .= '</div>';
		$c .= '</div>';

		$c .= "<script>\n";
		$c .= "var ttPopovers = ".json_encode($this->popovers).";\n";
		$c .= "$('#e10dashboardWidget table.e10-timetable>tbody>tr>td.day>span.tt').popover({content:function(){return ttPopovers[$(this).attr('data-id')];}, html: true, trigger: 'focus', delay: {'show': 0, 'hide': 100}, container: 'body', placement: 'auto', viewport:'#e10dashboardWidget'});";
		$c .= "</script>\n";

		return $c;
	}

	function renderPlan_TeachersDay ($teacherNdx, $day)
	{
		if (!isset($this->timetable[$teacherNdx][$day]))
			return '';

		$tdid = $teacherNdx.'-'.$day;
		$popoverData = [];
		$c = '';

		$rows = [];
		foreach ($this->timetable[$teacherNdx][$day] as $tt)
		{
			$rows[$tt['ucebna']][] = $tt;
		}

		foreach ($rows as $ucebnaNdx => $ttItems)
			$rows[$ucebnaNdx] = \E10\sortByOneKey($ttItems, 'zacatek');

		$lineIdx = 0;
		foreach ($rows as $ucebnaNdx => $ttItems)
		{
			$idx = 0;
			$line = ['class' => 'tag tt', 'data' => ['id' => $tdid], 'focusable' => 1];
			foreach ($ttItems as $tt)
			{
				if ($idx === 0)
					$line ['text'] = $tt['zacatek'].' - ';

				$poItem = [
					'begin' => $tt['zacatek'], 'end' => $tt['konec'], 'title' => $tt['vyukaNazev'], 'predmetNazev' => $tt['predmetNazev'],
					'rocnik' => $tt['rocnik'], 'ucebna' => $tt['ucebnaNazev'],
					/*'edit' => [
						'text' => '', 'icon' => 'icon-edit', 'docAction' => 'edit', 'table' => 'e10pro.zus.vyukyrozvrh',
						'type' => 'button', 'actionClass' => 'btn btn-xs', 'class' => 'pull-right',
						'pk' => $tt['ndx'],
						'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
					]*/
				];
				$popoverData[] = $poItem;

				$idx++;
			}
			$line ['text'] .= $tt['konec'];
			$line ['suffix'] = $tt['ucebnaNazev'];

			$c .= $this->app()->ui()->composeTextLine($line);

			$lineIdx++;
			if ($lineIdx !== count($rows))
				$c .= '<br/>';
		}

		$title = [
			['text' => $this->teachers[$teacherNdx], 'icon' => 'x-teacher', 'class' => 'h2'],
			['text' => utils::$dayNames[$day], 'class' => 'h2 pull-right']
		];
		$popoverHtml = $this->app()->ui()->composeTextLine($title);
		$popoverCols = ['begin' => 'Od', 'end' => 'Do', 'title' => 'Výuka', 'predmetNazev' => 'Předmět', 'rocnik' => 'Ročník', 'ucebna' => 'Učebna'/*, 'edit' => ''*/];
		$popoverHtml .= $this->app->ui()->renderTableFromArray ($popoverData, $popoverCols);
		$this->popovers[$tdid] = $popoverHtml;

		return $c;
	}
}


/**
 * Class WidgetPlan
 * @package e10pro\zus
 */
class WidgetPlan extends \Shipard\UI\Core\WidgetPane
{
	var $calParams;
	var $calParamsValues;
	var $viewType;
	var $today;

	var $plan;

	var $enumLocalOffices;

	public function createContent ()
	{
		$this->today = new \DateTime();

		$this->createContent_Toolbar();

		if ($this->viewType === 'daily')
		{
			$this->plan = new \e10pro\zus\PlanDailyTeachers($this->app);
			$this->plan->widgetId = $this->widgetId;
			$this->plan->setYear($this->calParamsValues['skolniRok']['value'], $this->calParamsValues['day']['value']);
			$this->plan->setLocalOffice($this->calParamsValues['localOffice']['value'], $this->calParamsValues['room']['value']);
			$this->plan->init();
			//$this->plan->load();

			$code = $this->plan->renderPlan();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
		}
		else
		if ($this->viewType === 'weekly')
		{
			$this->plan = new PlanOverview ($this->app);
			$this->plan->widgetId = $this->widgetId;
			$this->plan->setYear($this->calParamsValues['skolniRok']['value']);
			$this->plan->init();
			$this->plan->load();

			$code = $this->plan->renderPlan();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
		}
		else
		if ($this->viewType === 'teacher')
		{
			$this->plan = new PlanTeacher ($this->app);
			$this->plan->widgetId = $this->widgetId;
			$this->plan->setYear($this->calParamsValues['skolniRok']['value']);
			$this->plan->setteacher($this->calParamsValues['teacher']['value']);
			$this->plan->init();
			$this->plan->load();

			$code = $this->plan->renderPlan();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
		}
		else
		if ($this->viewType === 'localOffice')
		{
			$this->plan = new PlanLocalOffice ($this->app);
			$this->plan->widgetId = $this->widgetId;
			$this->plan->setYear($this->calParamsValues['skolniRok']['value']);
			$this->plan->setLocalOffice($this->calParamsValues['localOffice']['value'], $this->calParamsValues['room']['value']);
			$this->plan->init();
			$this->plan->load();

			$code = $this->plan->renderPlan();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
		}
	}

	public function createContent_Toolbar ()
	{
		$this->calParams = new \E10\Params ($this->app);
		$viewTypes = ['daily' => 'Den', 'weekly' => 'Týden', 'teacher' => 'Učitel', 'localOffice' => 'Pobočka'];
		$this->calParams->addParam('switch', 'viewType', ['title' => 'Pohled', 'switch' => $viewTypes, 'radioBtn' => 1, 'defaultValue' => 'daily']);

		$this->addParamDays();
		$this->addParamTeachers();
		$this->addParamLocalOffices();
		$this->addParamRoom();

		$this->calParams->addParam('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		$this->calParams->detectValues();
		$this->calParamsValues = $this->calParams->getParams();
		$this->viewType = $this->calParamsValues['viewType']['value'];

		$c = '';

		$c .= "<div id='ttParams' class='padd5' style='display: inline-block; width: 100%;'>";

		$c .= $this->calParams->createParamCode('skolniRok');


		if ($this->viewType === 'daily') {
			$c .= '&nbsp;';
			$c .= $this->calParams->createParamCode('day');
		}

		if ($this->viewType === 'teacher') {
			$c .= '&nbsp;';
			$c .= $this->calParams->createParamCode('teacher');
		}
		if ($this->viewType === 'localOffice' || $this->viewType === 'daily') {
			$c .= '&nbsp;';
			$c .= $this->calParams->createParamCode('localOffice');
		}

		$c .= $this->calParams->createParamCode('room');

		if ($this->viewType === 'teacher')
		{
			$printButton = [
				'text' => 'Tisk', 'icon' => 'system/actionPrint',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print',
				'data' => ['report-class' => 'e10pro.zus.ReportPlan', 'subreport' => 'teachers']
			];
			$c .= $this->app()->ui()->composeTextLine($printButton);
		}
		if ($this->viewType === 'weekly')
		{
			$printButton = [
				'text' => 'Tisk', 'icon' => 'system/actionPrint',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print',
				'data' => ['report-class' => 'e10pro.zus.ReportPlan']
			];
			$c .= $this->app()->ui()->composeTextLine($printButton);
		}
		if ($this->viewType === 'localOffice')
		{
			$printButton = [
				'text' => 'Tisk', 'icon' => 'system/actionPrint',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print',
				'data' => ['report-class' => 'e10pro.zus.ReportPlan', 'subreport' => 'offices']
			];
			$c .= $this->app()->ui()->composeTextLine($printButton);
			$printButton = [
				'text' => 'Po učebnách', 'icon' => 'system/actionPrint',
				'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print',
				'data' => ['report-class' => 'e10pro.zus.ReportPlan', 'subreport' => 'rooms']
			];
			$c .= $this->app()->ui()->composeTextLine($printButton);
		}

		$c .= "<span class='pull-right'>";
		$c .= $this->calParams->createParamCode('viewType');
		$c .= '</span>';

		$c .= '</div>';

		if ($this->viewType !== 'daily')
		{
			$c .= "<script>
					var maxh = $('#e10dashboardWidget').innerHeight();
					$('#e10dashboardWidget').find ('div.df2-viewer').each (function () {
							var oo = $(this).parent();
							oo.height(maxh - oo.position().top - 15);
							var viewerId = $(this).attr ('id');
							initViewer (viewerId);
					});
					$('#e10dashboardWidget').find ('table.e10-timetable').each (function () {
							var oo = $(this).parent();
							oo.height(maxh - oo.position().top - 5);
					});
				</script>
				";
		}


		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function addParamDays ()
	{
		$days = ['0' => 'Po', '1' => 'Út', '2' => 'St', '3' => 'Čt', '4' => 'Pá'];
		$this->calParams->addParam ('switch', 'day', ['title' => 'Den', 'switch' => $days, 'radioBtn' => 1, 'defaultValue' => '0']);
	}

	public function addParamTeachers ()
	{
		$enum = zusutils::ucitele($this->app, FALSE);
		if ($this->app->hasRole('uctl'))
			$this->calParams->addParam ('switch', 'teacher', ['title' => 'Učitel', 'switch' => $enum, 'defaultValue' => $this->app->userNdx()]);
		else
			$this->calParams->addParam ('switch', 'teacher', ['title' => 'Učitel', 'switch' => $enum]);
	}

	public function addParamLocalOffices ()
	{
		$enableAll = FALSE;
		$vt = uiutils::detectParamValue('viewType', 'daily');
		if ($vt === 'daily')
			$enableAll = TRUE;

		$enum = zusutils::pobocky($this->app, $enableAll);
		$this->calParams->addParam ('switch', 'localOffice', ['title' => 'Pobočka', 'switch' => $enum, 'defaultValue' => key($enum)]);

		$this->enumLocalOffices = $enum;
	}

	public function addParamRoom ()
	{
		$vt = uiutils::detectParamValue('viewType', 'daily');
		$lo = uiutils::detectParamValue('localOffice', '0');

		if ($vt === 'localOffice' && $lo == 0)
			$lo = key($this->enumLocalOffices);

		if ($lo == 0)
			return;

		$enum = [];
		$enum['0'] = 'Vše';

		$q[] = 'SELECT * FROM [e10_base_places] WHERE ';
		array_push($q, 'placeParent = %i', $lo);
		array_push($q, 'ORDER BY fullName');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$enum[$r['ndx']] = $r['shortName'];
		}
		$this->calParams->addParam ('switch', 'room', ['title' => 'Učebna', 'switch' => $enum, 'defaultValue' => 0]);
	}

	public function title() {return FALSE;}
}
