<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \E10\utils, \E10\Utility;


/**
 * Class PlanDailyTeachers
 * @package e10pro\zus
 */
class PlanDailyTeachers extends Utility
{
	var $teacherNdx;
	var $tableHodiny;
	var $znamkyHodnoceni;
	var $ucebniPlanPredpis;

	protected $academicYear;
	protected $localOffice = 0;
	protected $room = 0;
	protected $todayDow;

	public $widgetId;

	protected $timetable = [];
	protected $pobocky = [];
	protected $ucitele = [];

	protected $table = [];

	protected $code = '';
	protected $codeHours = '';
	protected $codeLeftBar = '';
	protected $codeToPlan = '';
	protected $gridCodeHours = '';
	protected $gridCodeTop = '';
	protected $pixelsPerMin = 3;
	protected $hourRowHeight = 69;
	protected $topBarHeight = 22;
	protected $leftBarWidth = 65;
	protected $toPlanWidth = 0;

	protected $zacatekMin = 100000;
	protected $konecMin = 0;
	protected $delkaMin = 0;
	protected $firstHour = 0;
	protected $lastHour = 0;
	protected $countHoursRows = 0;
	protected $errorCounter = 1;

	public function loadTimetable ()
	{
		$today = utils::today();

		$q[] = 'SELECT rozvrh.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky,
		vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev,';
		array_push ($q, ' ucitele.fullName as ucitelJmeno');
		array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS ucitele ON vyuky.ucitel = ucitele.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->academicYear);
		array_push ($q, ' AND rozvrh.den = %i', $this->todayDow);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		if ($this->localOffice != 0)
			array_push ($q, ' AND rozvrh.pobocka = %i', $this->localOffice);
		if ($this->room != 0)
			array_push ($q, ' AND rozvrh.ucebna = %i', $this->room);

		array_push ($q, ' ORDER BY pobocky.id, rozvrh.ucitel, rozvrh.zacatek, rozvrh.ndx');


		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['zacatek'] === '')
				$r['zacatek'] = '06:00';
			if ($r['konec'] === '')
				$r['konec'] = '06:45';
			$pobockaId = $r['pobocka'];
			$item = [
					'ndx' => $r['ndx'], 'stav' => $r['stav'],
					'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'],
					'ucebnaNazev' => $r['ucebnaNazev'], 'ucebna' => $r['ucebna'],
					'zacatek' => $r['zacatek'], 'konec' => $r['konec'],
					'zacatekMin' => utils::timeToMinutes($r['zacatek']), 'konecMin' => utils::timeToMinutes($r['konec']),
					'predmet' => $r['predmet'], 'predmetNazev' => $r['predmetNazev'],
					'vyuka' => $r['vyuka'], 'typVyuky' => $r['typVyuky'], 'vyukaNazev' => $r['vyukaNazev'],
					'ucitel' => $r['ucitel'], 'ucitelJmeno' => $r['ucitelJmeno'],
					'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky']),
					'srcId' => $r['zacatek'].'_'.$r['konec'].'_'.$r['ucitel'].'_'.$r['ucebna'].'_'.$r['pobocka'], 'shiftUp' => 0,
					'content' => []
			];

			$item['vyukaIcon'] = ($r['typVyuky'] === 0) ? 'iconGroupClass' : 'system/iconUser';
			$item['delkaMin'] = $item['konecMin'] - $item['zacatekMin'];

			$docState = $this->tableHodiny->getDocumentState ($r);
			$docStateStyle = $this->tableHodiny->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');
			$item['docStateStyle'] = $docStateStyle;

			$this->timetable[$pobockaId][] = $item;

			if (!isset ($this->pobocky[$pobockaId]))
				$this->pobocky[$pobockaId] = ['nazev' => $r['pobockaId'], 'od' => $r['zacatek'], 'rows' => []];

			$this->pobocky[$pobockaId]['do'] = $r['konec'];
			if (!isset($this->ucitele[$pobockaId]))
				$this->ucitele[$pobockaId] = [];
			if (!in_array($r['ucitel'], $this->ucitele[$pobockaId]))
				$this->ucitele[$pobockaId][] = $r['ucitel'];
		}
	}

	public function loadToPlan ()
	{
		$q [] = 'SELECT vyuky.*, ucitele.fullName as jmenoUcitele, pobocky.fullName as pobocka,';
		array_push($q, ' studium.cisloStudia as cisloStudia, studium.rocnik as studiumRocnik, obory.id as idOboru');
		array_push($q, ' FROM [e10pro_zus_vyuky] as vyuky ');
		array_push($q, ' LEFT JOIN e10_persons_persons AS ucitele ON vyuky.ucitel = ucitele.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS pobocky ON vyuky.misto = pobocky.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_studium AS studium ON vyuky.studium = studium.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_obory AS obory ON vyuky.svpObor = obory.ndx');
		array_push($q, ' WHERE 1');

		array_push ($q, ' AND vyuky.[stavHlavni] < %i AND vyuky.[skolniRok] = %s', 4, $this->academicYear);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{

		}
	}

	public function createTimetable ()
	{
		foreach ($this->pobocky as $pobockaId => $pobocka)
		{
			foreach ($this->ucitele[$pobockaId] as $ucitel)
			{
				$forceBreak = 1;
				foreach ($this->timetable[$pobockaId] as $hodina)
				{
					if ($hodina['ucitel'] != $ucitel)
						continue;
					$this->pridatHodinuNaPobocku($pobockaId, $hodina, $forceBreak);
					$forceBreak = 0;
				}
			}
		}

		$this->delkaMin = $this->konecMin - $this->zacatekMin + 5;
		$this->firstHour = intval($this->zacatekMin / 60);
		$this->lastHour = intval($this->konecMin / 60) + 1;

		$codeHodiny = '';
		foreach ($this->pobocky as $pobockaId => $pobocka)
		{
			$codePobockyRowStyle = '';

			$cntOfficeRows = 0;
			foreach ($this->timetable[$pobockaId]['rows'] as $uid => $ud)
			{
				$cntOfficeRows += count($this->timetable[$pobockaId]['rows'][$uid]);
			}

			$codePobockyRowStyle .= 'height: '.($cntOfficeRows * $this->hourRowHeight).'px; ';
			$this->codeLeftBar .= "<div style='$codePobockyRowStyle position: relative; width: ".$this->leftBarWidth."px; border-bottom: 1px dashed rgba(0,0,0,.5);'>";
			$this->codeLeftBar .= utils::es($pobocka['nazev']);
			$this->codeLeftBar .= '</div>';

			$c = '';

			$rowStyle = '';
			$rowStyle .= 'height: '.($cntOfficeRows*$this->hourRowHeight).'px; ';
			$rowStyle .= 'width: '.($this->delkaMin*$this->pixelsPerMin).'px; ';
			$c .= "<div style='$rowStyle position: relative; border-bottom: 1px dashed rgba(64,64,128,.45);'>";

			$rowNumber = 0;
			foreach ($this->timetable[$pobockaId]['rows'] as $ucitel => $ud)
			{
				foreach ($this->timetable[$pobockaId]['rows'][$ucitel] as $row)
				{
					$tableRow = ['hodiny' => []];
					$tableRow ['pobocka'] = $pobocka['nazev'];

					foreach ($row as $hodina)
					{
						$c .= $this->oneItemCode($hodina, $rowNumber);
					}
					$tableRow['hodiny'][] = ['code' => $c];
					$this->table[] = $tableRow;

					$this->countHoursRows++;
					$rowNumber++;
				}
			}
			$c .= '</div>';
			$codeHodiny .= $c;
		}

		$this->codeHours = $codeHodiny;
	}

	public function createGrid ()
	{
		$left = 0;
		for ($hr = $this->firstHour; $hr < $this->lastHour; $hr++)
		{
			$width = 60;

			$hrTopBarStart = "$hr:00";
			if ($hr === $this->firstHour)
			{
				$width -= ($this->zacatekMin - $this->firstHour * 60);
				$hrTopBarStart = utils::minutesToTime($this->zacatekMin);
			}

			if ($hr === $this->lastHour - 1)
				$width -=  ($this->lastHour*60 - $this->konecMin - 5);

			$width *= $this->pixelsPerMin;

			$style = '';
			$style .= 'left: '.$left.'px;';
			$style .= 'width: '.$width.'px;';
			$style .= 'height: '.($this->countHoursRows*$this->hourRowHeight).'px; ';
			if ($hr % 2 !== 0)
				$style .= 'background-color: #ececec; ';
			else
				$style .= 'background-color: #f0f0f0; ';
			$this->gridCodeHours .= "<div style='$style position: absolute; top: 0px; border-right: 1px dotted rgba(0,0,0,.3);'></div>";


			$style = '';
			$style .= 'left: '.$left.'px;';
			$style .= 'width: '.$width.'px;';
			$style .= 'height: '.($this->topBarHeight-2).'px; ';
			if ($hr % 2 !== 0)
				$style .= 'background-color: #e0e0ff; ';
			else
				$style .= 'background-color: #f0f0ff; ';
			$this->gridCodeTop .= "<div style='$style; position: absolute; top: 0px; border-right: 1px dotted rgba(0,0,0,.3);'>$hrTopBarStart</div>";

			$left += $width;
		}
	}

	public function composeCode()
	{
		$this->createToPlan();
		$this->createGrid();

		$this->code .= "<div id='ttTopBar' style='position: absolute; height: ".$this->topBarHeight."px; left: ".($this->leftBarWidth+1)."px; border-left: 1px dotted rgba(0,0,0,.5); border-top: 1px dotted rgba(0,0,0,.5); overflow: hidden;'>";
		$this->code .= "<div style='position: absolute;'>".$this->gridCodeTop.'</div>';
		$this->code .= '</div>';


		$this->code .= "<div id='ttHours' style='position: relative; border-top: 1px dotted rgba(0,0,0,.5); border-left: 1px dotted rgba(0,0,0,.5); overflow: scroll;'>";
		$this->code .= $this->gridCodeHours;
		$this->code .= $this->codeHours;
		$this->code .= '</div>';


		$this->code .= "<div id='ttLeftBar' style='position: absolute; left: 2px; top: ".$this->topBarHeight."px; width: ".($this->leftBarWidth-1)."px; border-top: 1px dotted rgba(0,0,0,.5); overflow: hidden;'>";
		$this->code .= $this->codeLeftBar;
		$this->code .= '</div>';

		if ($this->codeToPlan !== '')
		{
			$this->toPlanWidth = 200;
			$this->code .= "<div id='ttToPlan' style='position: absolute; right: 3px; width: " . $this->toPlanWidth .
					"px; border-left: 1px solid rgba(0,0,0,.5); overflow: scroll;'>";
			$this->code .= $this->codeToPlan;
			$this->code .= '</div>';
		}
		$this->code .= "

		<script>
				var elParams = $('#ttParams');


				var maxh = $('#e10dashboardWidget').innerHeight() - elParams.height() - elParams.position().top - 10;
				var maxw = $('#e10dashboardWidget').innerWidth() - {$this->toPlanWidth};
				var elHours = $('#ttHours');
				elHours.height (maxh - {$this->topBarHeight} - 1);
				elHours.width (maxw - {$this->leftBarWidth} - 2);
				elHours.css ({top: {$this->topBarHeight} - 1, left: {$this->leftBarWidth}});

				var elLeftBar = $('#ttLeftBar');
				elLeftBar.height (maxh - elLeftBar.position().top - 2);
				elLeftBar.css ({top: {$this->topBarHeight} - 1 + elParams.height() + 8});

				var elTopBar = $('#ttTopBar');
				elTopBar.width (maxw - {$this->leftBarWidth} - 2);

				var elToPlan = $('#ttToPlan');
				elToPlan.height (maxh);
				elToPlan.css ({top: 1 + elHours.position().top - {$this->topBarHeight}});

	var divs = $('#ttHours, #ttLeftBar');
	var sync = function(e){
    var other = divs.not(this).off('scroll'), other = other.get(0);
    other.scrollTop = this.scrollTop;
    $('#ttTopBar>div').css({left: - this.scrollLeft});
}
divs.on( 'scroll', sync);

			</script>
		";
	}

	public function createToPlan()
	{
		$this->ucebniPlanPredpis = zusutils::ucebniPlan($this->app);

		$q[] = 'SELECT vyuky.ndx as vyuka, ';
		array_push($q, ' vyuky.nazev as vyukaNazev, vyuky.svpPredmet, vyuky.rocnik, vyuky.svp, vyuky.svpObor,',
				' vyuky.svpOddeleni, vyuky.typ as vyukaTyp, vyuky.misto as vyukaPobocka, vyuky.ucitel as vyukaUcitel,');
		array_push($q, ' predmety.nazev as predmetNazev, ucitele.fullName as ucitelJmeno, ');

		$q[] = '(SELECT SUM(rr.delka) FROM [e10pro_zus_vyukyrozvrh] AS rr WHERE rr.vyuka = vyuky.ndx AND rr.[stavHlavni] < 4) AS naplanovano';

		array_push($q, ' FROM [e10pro_zus_vyuky] AS vyuky');
		array_push($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON vyuky.svpPredmet = predmety.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS ucitele ON vyuky.ucitel = ucitele.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND vyuky.[stavHlavni] < %i', 4);
		array_push($q, ' AND vyuky.[skolniRok] = %i', zusutils::aktualniSkolniRok());

		$rows = $this->db()->query ($q);

		$c = '';
		$cnt = 0;
		foreach ($rows as $r)
		{
			$teachPlanId = $r['svpPredmet'].'-'.$r['rocnik'].'-'.$r['svp'].'-'.$r['svpObor'].'-'.$r['svpOddeleni'];

			$request = isset($this->ucebniPlanPredpis[$teachPlanId]) ? $this->ucebniPlanPredpis[$teachPlanId] : 0;

			if (!$request || $request && $r['naplanovano'] == $request)
				continue;

			if (!$this->app->hasRole('zusadm') && $r['vyukaUcitel'] !== $this->app->userNdx())
				continue;

			$naplanovano = ($r['naplanovano']) ? $r['naplanovano'] : '0';

			$addParams = "__vyuka={$r['vyuka']}&__pobocka={$r['vyukaPobocka']}&__den={$this->todayDow}&__ucitel={$r['vyukaUcitel']}";
			$c .= "<div class='e10-pane-plan-missing e10-document-trigger' data-action='new' data-table='e10pro.zus.vyukyrozvrh' data-addparams='$addParams' data-srcobjecttype='widget' data-srcobjectid='{$this->widgetId}'>";

			$c .= $this->app()->ui()->composeTextLine(['text' => $r['vyukaNazev'], 'icon' => ($r['vyukaTyp'] === 0) ? 'iconGroupClass' : 'system/iconUser', 'class' => 't1 block']);
			$c .= $this->app()->ui()->composeTextLine(['text' => $r['predmetNazev'], 'suffix' => $naplanovano.' min z '.$this->ucebniPlanPredpis[$teachPlanId], 'class' => 'block']);
			$c .= $this->app()->ui()->composeTextLine(['text' => $r['ucitelJmeno'], 'class' => 'e10-small block']);

			$c .='</div>';
			$cnt++;
		}

		if ($cnt)
		{
			$this->codeToPlan = $this->app()->ui()->composeTextLine(['text' => 'Nezaplánované výuky', 'class' => 'h2 block']);
			$this->codeToPlan .= $c;
		}
	}

	public function pridatHodinuNaPobocku ($pobockaId, &$hodina, $forceBreak)
	{
		$ucitel = 'U'.$hodina['ucitel'];

		if ($hodina['zacatekMin'] < $this->zacatekMin)
			$this->zacatekMin = $hodina['zacatekMin'];
		if ($hodina['konecMin'] > $this->konecMin)
			$this->konecMin = $hodina['konecMin'];

		if (isset($this->timetable[$pobockaId]['rows'][$ucitel]) && !$forceBreak)
		{
			$rowNumber = 0;
			foreach ($this->timetable[$pobockaId]['rows'] as $uid => $ud)
			{
				if ($uid !== $ucitel)
					$rowNumber += count ($this->timetable[$pobockaId]['rows'][$uid]);
				else
					break;
			}
		}

		// -- najít kolize v rozvrhu
		foreach ($this->timetable[$pobockaId]['rows'] as $uid => $ud)
		{
			foreach ($this->timetable[$pobockaId]['rows'][$uid] as $rowId => &$row)
			{
				foreach ($row as &$h)
				{
					$n = 0;
					if ($hodina['zacatekMin'] >= $h['zacatekMin'] && $hodina['zacatekMin'] < $h['konecMin'])
						$n = 1;
					if ($hodina['konecMin'] >= $h['zacatekMin'] && $hodina['konecMin'] < $h['konecMin'])
						$n = 2;

					if ($n && $hodina['srcId'] === $h['srcId'])
						$hodina['shiftUp'] = $h['shiftUp'] + 1;
					else
						if ($n && ($hodina['ucebna'] === $h['ucebna']))
						{
							if (isset ($h['error']))
								$error = $h['error'];
							else
								if (isset ($hodina['error']))
									$error = $hodina['error'];
								else
									$error = $this->errorCounter++;

							$hodina['error'] = $error;
							$h['error'] = $error;
						}
				}
			}
		}


		foreach ($this->timetable[$pobockaId]['rows'][$ucitel] ?? [] as $rowId => &$row)
		{
			$nalezeno = 0;
			foreach ($row as &$h)
			{
				$n = 0;
				if ($hodina['zacatekMin'] >= $h['zacatekMin'] && $hodina['zacatekMin'] < $h['konecMin'])
					$n = 1;
				if ($hodina['konecMin'] >= $h['zacatekMin'] && $hodina['konecMin'] < $h['konecMin'])
					$n = 2;

				if ($n && $hodina['srcId'] === $h['srcId'])
					$hodina['shiftUp'] = $h['shiftUp'] + 1;
				else
					if ($n && ($hodina['ucebna'] === $h['ucebna']))
					{
						if (isset ($h['error']))
							$error = $h['error'];
						else
							if (isset ($hodina['error']))
								$error = $hodina['error'];
							else
								$error = $this->errorCounter++;

						$hodina['error'] = $error;
						$h['error'] = $error;
					}

				if ($n)
					$nalezeno = 1;
			}
			$rowNumber++;

			if (!$nalezeno)
			{
				$hodina['rowNumber'] = $rowNumber + 1;
				$this->timetable[$pobockaId]['rows'][$ucitel][$rowId][] = $hodina;
				return;
			}
		}

		$hodina['rowNumber'] = $rowNumber + 1;
		$this->timetable[$pobockaId]['rows'][$ucitel][] = [$hodina];
	}

	public function oneItemCode ($item, $rn)
	{
		$onePx = $this->pixelsPerMin;

		$itemStyle = '';
		$itemStyle .= 'width: '.($item['delkaMin']*$onePx/*+1*/).'px;';
		$itemStyle .= 'height: '.($this->hourRowHeight - 4).'px;';
		$itemStyle .= 'left: '.(($item['zacatekMin'] - $this->zacatekMin)*$onePx/*-1*/).'px;';
		$itemStyle .= 'top: '.((/*$item['rowNumber']*/$rn)*$this->hourRowHeight + 2 - $item['shiftUp'] * 5).'px;';

		$icon = utils::icon($item['vyukaIcon']);
		$icon .= ' e10-off';

		$class = $item['docStateStyle'];
		if (isset($item['error']))
			$class .= ' e10-error';
		$c = "<span class='e10-pane-plan $class' style='position: absolute; top: 0px; display: inline-block; overflow: hidden; $itemStyle'>";

		$titleClass = ($this->app->userNdx() === $item['ucitel']) ? ' e10-row-this' : '';
		$button = [
				'docAction' => 'edit', 'table' => 'e10pro.zus.vyukyrozvrh', 'pk' => $item['ndx'],
				'text' => $item['vyukaNazev'], 'type' => 'div', 'actionClass' => 't1'.$titleClass, 'icon' => $item['vyukaIcon'],
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
		];
		$c .= $this->app()->ui()->composeTextLine($button);


		$c .= "<div class='t2'>".utils::es ($item['predmetNazev']).'</div>';

		$c .= "<div class='t3'>".utils::es ($item['ucitelJmeno']).'</div>';
		$c .= "<div class='t4'>".utils::es ($item['zacatek'].'‧'.$item['konec'].' '.$item['ucebnaNazev']).'</div>';

		if (isset($item['error']))
		{
			$c .= "<div style='position: absolute; right: 0px; top: 0px; background-color: red; font-family: monospace; font-size: 16px;color: white; padding: 4px;'>" . chr(64+$item['error']) . '</div>';
		}

		$c .= '</span>';

		return $c;
	}

	public function renderPlan ()
	{
		$this->loadTimetable();
		$this->loadToPlan();
		$this->createTimetable();
		$this->composeCode();

		return $this->code;
	}

	public function setYear ($year, $dow)
	{
		$this->academicYear = $year;
		$this->todayDow = $dow;
	}

	public function setLocalOffice ($lo, $room)
	{
		$this->localOffice = $lo;
		$this->room = $room;
	}

	public function init ()
	{
		$this->tableHodiny = $this->app->table('e10pro.zus.hodiny');
		$this->znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');
	}
}
