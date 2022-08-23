<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \E10\utils, \E10\Utility, \E10\uiutils, \E10Pro\Zus\zusutils;


/**
 * Class SchoolDayDashboardWidget
 * @package e10pro\zus
 */
class SchoolDayDashboardWidget extends \Shipard\UI\Core\WidgetPane
{
	var $teacherNdx;
	var $tableHodiny;
	var $znamkyHodnoceni;

	var $today;
	protected $todayYear;
	protected $todayMonth;
	protected $todayDay;
	protected $todayDow;
	protected $todayTime;
	protected $todayTimeMin;
	protected $academicYear;

	protected $timetable = [];
	protected $pobocky = [];

	//protected $tiles = [];

	protected $table = [];

	protected $code = '';
	protected $codeHours = '';
	protected $codeLeftBar = '';
	protected $gridCodeHours = '';
	protected $gridCodeTop = '';
	protected $pixelsPerMin = 3;
	protected $hourRowHeight = 65;
	protected $topBarHeight = 22;
	protected $leftBarWidth = 65;

	protected $zacatekMin = 100000;
	protected $konecMin = 0;
	protected $delkaMin = 0;
	protected $firstHour = 0;
	protected $lastHour = 0;
	protected $countHoursRows = 0;

	public function loadTimetable ()
	{
		$q[] = 'SELECT hodiny.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky,';
		array_push ($q, ' vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev,');
		array_push ($q, ' ucitele.fullName as ucitelJmeno');
		array_push ($q, ' FROM [e10pro_zus_hodiny] AS hodiny');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON hodiny.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON hodiny.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS ucitele ON hodiny.ucitel = ucitele.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON vyuky.svpPredmet = predmety.ndx');

		array_push ($q, ' WHERE 1');

		//array_push ($q, ' AND rozvrh.ucitel = %i', $this->teacherNdx);
		//array_push ($q, ' AND vyuky.skolniRok = %s', $this->academicYear);
		array_push ($q, ' AND hodiny.datum = %d', $this->today);
		//array_push ($q, ' AND hodiny.stavHlavni <= 2');

		array_push ($q, ' ORDER BY pobocky.id, hodiny.ucebna, hodiny.zacatek, hodiny.konec, hodiny.ndx');


		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$pobockaId = $r['pobocka'];
			$item = [
				'ndx' => $r['ndx'], 'stav' => $r['stav'],
				'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'],
				'zacatekMin' => utils::timeToMinutes($r['zacatek']), 'konecMin' => utils::timeToMinutes($r['konec']),
				'predmet' => $r['predmet'], 'predmetNazev' => $r['predmetNazev'],
				'vyuka' => $r['vyuka'], 'typVyuky' => $r['typVyuky'], 'vyukaNazev' => $r['vyukaNazev'],
				'ucitelJmeno' => $r['ucitelJmeno'],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky']),
				'srcId' => $r['zacatek'].'_'.$r['konec'].'_'.$r['ucitel'].'_'.$r['ucebna'].'_'.$r['pobocka'], 'shiftUp' => 0,
				'content' => []
			];

			$item['vyukaIcon'] = ($r['typVyuky'] === 0) ? 'icon-group' : 'icon-user';
			$item['delkaMin'] = $item['konecMin'] - $item['zacatekMin'];

			$docState = $this->tableHodiny->getDocumentState ($r);
			$docStateStyle = $this->tableHodiny->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');
			$item['docStateStyle'] = $docStateStyle;

			//$this->loadHour($item);
			$this->timetable[$pobockaId][] = $item;
			if (!isset ($this->pobocky[$pobockaId]))
				$this->pobocky[$pobockaId] = ['nazev' => $r['pobockaId'], 'od' => $r['zacatek'], 'order' => $item['order'], 'rows' => []];

			$this->pobocky[$pobockaId]['do'] = $r['konec'];
		}

		//$this->pobocky = \E10\sortByOneKey($this->pobocky, 'order', TRUE);
	}

	public function createTimetable ()
	{
		foreach ($this->pobocky as $pobockaId => $pobocka)
		{
			foreach ($this->timetable[$pobockaId] as $hodina)
			{
				//$this->timetable[$pobockaId]['rows'][0][] = $hodina;
				$this->pridatHodinuNaPobocku ($pobockaId, $hodina);
			}
		}
		$this->delkaMin = $this->konecMin - $this->zacatekMin + 5;
		$this->firstHour = intval($this->zacatekMin / 60);
		$this->lastHour = intval($this->konecMin / 60) + 1;

		$codeHodiny = '';
		foreach ($this->pobocky as $pobockaId => $pobocka)
		{
			$codePobockyRowStyle = '';
			$codePobockyRowStyle .= 'height: '.(count($this->timetable[$pobockaId]['rows'])*$this->hourRowHeight).'px; ';
			$this->codeLeftBar .= "<div style='$codePobockyRowStyle position: relative; width: ".$this->leftBarWidth."px; border-bottom: 1px dashed rgba(0,0,0,.5);'>";
			$this->codeLeftBar .= utils::es($pobocka['nazev']);
			$this->codeLeftBar .= '</div>';

			$c = '';

			$rowStyle = '';
			$rowStyle .= 'height: '.(count($this->timetable[$pobockaId]['rows'])*$this->hourRowHeight).'px; ';
			$rowStyle .= 'width: '.($this->delkaMin*$this->pixelsPerMin).'px; ';
			$c .= "<div style='$rowStyle position: relative; border-bottom: 1px dashed rgba(64,64,128,.45);'>";

			foreach ($this->timetable[$pobockaId]['rows'] as $row)
			{
				$tableRow = ['hodiny' => []];
				$tableRow ['pobocka'] = $pobocka['nazev'];

				foreach ($row as $hodina)
				{
					$c .= $this->oneItemCode($hodina);
				}
				$tableRow['hodiny'][] = ['code' => $c];
				$this->table[] = $tableRow;

				$this->countHoursRows++;
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

		if ($this->todayTimeMin >= $this->zacatekMin && $this->todayTimeMin <= $this->konecMin)
		{
			$style = '';
			$style .= 'left: ' . (($this->todayTimeMin - $this->zacatekMin) * $this->pixelsPerMin - 2) . 'px;';
			$style .= 'width: 4px;';
			$style .= 'height: ' . ($this->countHoursRows * $this->hourRowHeight) . 'px; ';
			$style .= 'background-color: rgba(0,196,0,.3); ';
			$this->gridCodeHours .= "<div style='$style position: absolute; top: 0px;'></div>";
		}
	}

	public function composeCode()
	{
		$fullCode = intval($this->app->testGetParam('fullCode'));

		$this->createGrid();

		$this->code .= "<div id='ttTopBar' style='position: absolute; height: ".$this->topBarHeight."px; left: ".($this->leftBarWidth+1)."px; border-left: 1px dotted rgba(0,0,0,.5); overflow: hidden;'>";
		$this->code .= "<div style='position: absolute;'>".$this->gridCodeTop.'</div>';
		$this->code .= '</div>';


		$this->code .= "<div id='ttHours' style='position: relative; border-top: 1px dotted rgba(0,0,0,.5); border-left: 1px dotted rgba(0,0,0,.5); overflow: scroll;'>";
		$this->code .= $this->gridCodeHours;
		$this->code .= $this->codeHours;
		$this->code .= '</div>';


		$this->code .= "<div id='ttLeftBar' style='position: absolute; left: 2px; top: ".$this->topBarHeight."px; width: ".($this->leftBarWidth-1)."px; border-top: 1px dotted rgba(0,0,0,.5); overflow: hidden;'>";
		$this->code .= $this->codeLeftBar;
		$this->code .= '</div>';


		$this->code .= "

		<script>
				var maxh = $('#e10dashboardWidget').innerHeight();
				var maxw = $('#e10dashboardWidget').innerWidth();
				var elHours = $('#ttHours');
				elHours.height (maxh - {$this->topBarHeight} - 1);
				elHours.width (maxw - {$this->leftBarWidth} - 2);
				elHours.css ({top: {$this->topBarHeight} - 1, left: {$this->leftBarWidth}});

				var elLeftBar = $('#ttLeftBar');
				elLeftBar.height (maxh - elHours.position().top - 2);

				var elTopBar = $('#ttTopBar');
				elTopBar.width (maxw - {$this->leftBarWidth} - 2);


var divs = $('#ttHours, #ttLeftBar');
var sync = function(e){
    var other = divs.not(this).off('scroll'), other = other.get(0);
    other.scrollTop = this.scrollTop;
    $('#ttTopBar>div').css({left: - this.scrollLeft});
}
divs.on( 'scroll', sync);
";

		if ($fullCode)
			$this->code .= "e10.widgets.autoRefresh (\"{$this->widgetId}\");";

		$this->code .= "
			</script>
		";
	}

	public function pridatHodinuNaPobocku ($pobockaId, &$hodina)
	{
		if ($hodina['zacatekMin'] < $this->zacatekMin)
			$this->zacatekMin = $hodina['zacatekMin'];
		if ($hodina['konecMin'] > $this->konecMin)
			$this->konecMin = $hodina['konecMin'];

		if (isset($this->timetable[$pobockaId]['rows']))
		{
			foreach ($this->timetable[$pobockaId]['rows'] as $rowId => &$row) {
				$nalezeno = 0;
				foreach ($row as $h)
				{
					if ($hodina['zacatekMin'] >= $h['zacatekMin'] && $hodina['zacatekMin'] < $h['konecMin'])
						$nalezeno = 1;
					if ($hodina['konecMin'] >= $h['zacatekMin'] && $hodina['konecMin'] < $h['konecMin'])
						$nalezeno = 1;

					if ($nalezeno && $hodina['srcId'] === $h['srcId'])
						$hodina['shiftUp'] = $h['shiftUp'] + 1;
				}

				if (!$nalezeno)
				{
					$hodina['rowNumber'] = $rowId;
					$this->timetable[$pobockaId]['rows'][$rowId][] = $hodina;
					return;
				}
			}
		}
		$hodina['rowNumber'] = (isset($this->timetable[$pobockaId]['rows'])) ? count($this->timetable[$pobockaId]['rows']) : 0;
		$this->timetable[$pobockaId]['rows'][] = [$hodina];
	}

	public function oneItemCode ($item)
	{
		$onePx = $this->pixelsPerMin;
		$current = FALSE;

		if ($this->todayTimeMin >= $item['zacatekMin'] && $this->todayTimeMin < $item['konecMin'])
			$current = TRUE;


		$itemStyle = '';
		$itemStyle .= 'width: '.($item['delkaMin']*$onePx/*+1*/).'px;';
		$itemStyle .= 'height: '.($this->hourRowHeight - 4).'px;';
		$itemStyle .= 'left: '.(($item['zacatekMin'] - $this->zacatekMin)*$onePx/*-1*/).'px;';
		$itemStyle .= 'top: '.(($item['rowNumber'])*$this->hourRowHeight + 2 - $item['shiftUp'] * 5).'px;';

		$icon = utils::icon($item['vyukaIcon']);

		if ($current)
		{
			$itemStyle .= 'box-shadow: 1px 1px 2px rgba(0,200,0,.1); ';
			$itemStyle .= 'border: 1px solid rgba(0,156,0,.6); ';
			$itemStyle .= 'border-left: 3px solid rgba(0,156,0,.6); ';
			$icon .= ($item['stav'] === 1200) ? ' e10-success' : ' e10-error';
		}
		else
		{
			$itemStyle .= 'border: 1px solid rgba(0,0,0,.3); ';
			$icon .= ' e10-off';
		}

		$class = $item['docStateStyle'];
		$c = "<span class='e10-pane-plan $class' style='position: absolute; top: 0px; display: inline-block; overflow: hidden; $itemStyle'>";

		$c .= "<div class='t1'><i class='$icon'></i>".utils::es ($item['vyukaNazev']).'</div>';

		$c .= "<div class='t2'>".utils::es ($item['predmetNazev']).'</div>';

		$c .= "<div style='clear:both; font-size: 11px; padding-left: 2px;'>".utils::es ($item['ucitelJmeno']).'</div>';
		$c .= "<div style='clear:both; opacity: .8; font-size: 11px; padding-left: 2px;'>".utils::es ($item['zacatek'].'-'.$item['konec'].' '.$item['ucebnaNazev']).'</div>';

		$c .= '</span>';

		return $c;
	}

	public function createContent ()
	{
		$this->loadTimetable();
		$this->createTimetable();
		$this->composeCode();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
	}

	public function init ()
	{
		parent::init();

		$this->today = utils::today();

		//$this->today = new \DateTime('2015-03-19');

		$this->todayYear = intval($this->today->format('Y'));
		$this->todayMonth = intval($this->today->format('m'));
		$this->todayDay = intval($this->today->format('d'));
		$this->todayDow = intval($this->today->format('N')) - 1;
		$this->academicYear = zusutils::aktualniSkolniRok ();

		$now = new \DateTime();
		$this->todayTime = $now->format('H:i');
		$this->todayTimeMin = utils::timeToMinutes($this->todayTime);

		//$this->todayTime = '12:40';

		$this->tableHodiny = $this->app->table('e10pro.zus.hodiny');
		$this->znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');
	}


	public function title() {return FALSE;}
}
