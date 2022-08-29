<?php

namespace e10pro\zus;

require_once __APP_DIR__ . '/e10-modules/e10/persons/tables/persons.php';
require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';
require_once __APP_DIR__ . '/e10-modules/e10pro/zus/zus.php';

use \E10\utils, \E10\Utility, \E10\uiutils, \E10Pro\Zus\zusutils;

// TODO: delete?


/**
 * Class TeachersDashboardWidget
 * @package e10pro\zus
 */
class TeachersDashboardWidget extends \E10\widgetPane
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
	protected $academicYear;

	protected $timetable = [];
	protected $timetableVyukyPks = [];
	protected $ETKHours = [];

	protected $pobocky = [];
	protected $tiles = [];
	var $smallTimeTable = [];

	public function pritomnostVHodine ($dochazkaNdx, &$item, $pritomnost, $stav)
	{
		$cell = ['text' => ''];

		switch($pritomnost)
		{
			case 0:
				$cell['icon'] = 'icon-question';
				$item ['_options']['cellClasses']['pritomnost'] = 'e10-off';
				break;
			case 1:
				$cell['icon'] = 'system/iconCheck';
				$item ['_options']['cellClasses']['pritomnost'] = 'e10-row-plus';
				break;
			case 2:
				$cell['icon'] = 'icon-circle-o';
				$item ['_options']['cellClasses']['pritomnost'] = 'e10-row-info';
				break;
			case 3:
				$cell['icon'] = 'icon-times';
				$item ['_options']['cellClasses']['pritomnost'] = 'e10-row-minus';
				break;
		}

		if ($stav === 1200)
		{
			$cell += ['type' => 'widget', 'action' => 'nastavitPritomnost-'.$dochazkaNdx, 'btnClass' => 'h1', 'element' => 'div'];
		}

		$item ['_options']['cellClasses']['pritomnost'] .= ' e10-icon';

		$item ['pritomnost'] = $cell;
	}

	public function nacistHodnoceni (&$dochazka, $hodina)
	{
		$q[] = 'SELECT * FROM e10pro_zus_hodnoceni WHERE 1';
		array_push($q, ' AND hodina = %i', $hodina['ndx'], ' AND stavHlavni < 4');

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$h = [
				'docAction' => 'edit', 'table' => 'e10pro.zus.hodnoceni', 'pk' => $r['ndx'],
				'text' => $this->znamkyHodnoceni[$r['znamka']]['nazev'], 'type' => 'span', 'actionClass' => 'tag tag-contact', 'class' => 'pull-right',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
			];
			if ($r['poznamka'] !== '')
				$h['text'] .= ' ('.$r['poznamka'].')';

			$dochazka[$r['student']]['hodnoceni'][] = $h;
		}
	}

	public function loadHour (&$hodina)
	{
		$q[] = 'SELECT dochazka.*, studenti.fullName AS studentJmeno FROM e10pro_zus_hodinydochazka as dochazka';
		array_push($q, ' LEFT JOIN e10_persons_persons AS studenti ON dochazka.student = studenti.ndx');
		array_push($q, ' WHERE hodina = %i', $hodina['ndx']);
		$dochazka = $this->app->db()->query ($q);
		$tabulkaDochazky = [];

		foreach ($dochazka as $d)
		{
			$sb = ['jmeno' => $d['studentJmeno'], 'student' => $d['student']];

			$this->pritomnostVHodine($d['ndx'], $sb, $d['pritomnost'], $hodina['stav']);

			if ($hodina['stav'] === 1200)
				$sb['hodnoceni'][] = [
					'docAction' => 'new', 'table' => 'e10pro.zus.hodnoceni', 'icon' => 'system/actionAdd',
					'text' => 'Známka', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-success',
					'class' => 'pull-right',
					'addParams' => '__student='.$d['student'].'&__predmet='.$hodina['predmet'].'&__ucitel='.$this->app->user()->data ('id').
						'&__hodina='.$hodina['ndx'].'&__vyuka='.$hodina['vyuka'],
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
				];

			$tabulkaDochazky[$d['student']] = $sb;
		}
		$this->nacistHodnoceni($tabulkaDochazky, $hodina);

		if ($hodina['stav'] !== 1000)
		{
			$h = ['pritomnost' => '', 'jmeno' => 'Jméno', 'hodnoceni' => 'Známky'];
			$hodina['content'][] = ['class' => 'info', 'table' => $tabulkaDochazky, 'header' => $h, 'params' => ['hideHeader' => 1]];

			$btns[] = ['text' => ' ', 'class' => 'block h1 padd5'];
		}


		// -- zahajeni hodiny
		if ($hodina['stav'] === 1000)
		{
			$btns[] = [
				'type' => 'widget', 'action' => 'zahajitHodinu-'.$hodina['ndx'], 'text' => 'Zahájit hodinu', 'icon' => 'system/iconCheck',
				'actionClass' => 'btn btn-success', 'class' => ''
			];
		}

		if ($hodina['stav'] === 1200 || $hodina['stav'] === 4000)
		{
			$btns[] = ['text' => 'Otevřít', 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx'],
					'class' => '', 'type' => 'button', 'actionClass' => 'btn btn-primary', 'icon' => 'system/actionOpen',
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId];
		}

		// -- ukonceni hodiny
		if ($hodina['stav'] === 1200)
		{
			$btns[] = [
				'type' => 'widget', 'action' => 'ukoncitHodinu-'.$hodina['ndx'], 'text' => 'Ukončit hodinu', 'icon' => 'system/iconCheck',
				'actionClass' => 'btn btn-success', 'class' => ''
			];
		}

		//$hodina['content'][] = ['class' => 'info', 'value' => $btns];
		if (isset($this->pobocky[$hodina['pobocka']]) && $hodina['order'] < $this->pobocky[$hodina['pobocka']]['order'])
			$this->pobocky[$hodina['pobocka']]['order'] = $hodina['order'];
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
		array_push ($q, ' LEFT JOIN e10_persons_persons AS ucitele ON rozvrh.ucitel = ucitele.ndx');

		array_push ($q, ' WHERE 1');

		if ($this->teacherNdx)
			array_push ($q, ' AND rozvrh.ucitel = %i', $this->teacherNdx);

		array_push ($q, ' AND rozvrh.den = %i', $this->todayDow);

		array_push ($q, ' AND vyuky.skolniRok = %s', $this->academicYear);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $today);

		array_push ($q, ' ORDER BY ucitele.lastName, ucitele.firstName, rozvrh.den, rozvrh.zacatek, rozvrh.ndx');

		$rows = $this->db()->query ($q);
		$smallTt = FALSE;
		foreach ($rows as $r)
		{
			$itemId = $r['vyuka'].'_'.$r['ndx'];

			$teacherNdx = $r['ucitel'];
			$item = [
				'ndx' => $r['ndx'],
				'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'],
				'ucebna' => $r['ucebna'], 'ucebnaNazev' => $r['ucebnaNazev'],

				'vyuka' => $r['vyuka'], 'vyukaNazev' => $r['vyukaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'predmetNazev' => $r['predmetNazev'],

				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky'], 'zkratka'),
			];

			$item['vyukaIcon'] = ($r['typVyuky'] === 0) ? 'iconGroupClass' : 'system/iconUser';

			$this->timetableVyukyPks[] = $r['vyuka'];
			$this->timetable[$itemId] = $item;

			if ($smallTt === FALSE || $smallTt['pobocka'] !== $r['pobocka'])
			{
				if ($smallTt !== FALSE)
					$this->smallTimeTable[] = $smallTt;
				$smallTt = [
					'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'pobocka' => $r['pobocka'], 'icon' => 'system/iconClock'
				];
			}
			else
			{
				$smallTt['konec'] = $r['konec'];
			}

			$smallTt['text'] = $smallTt['zacatek'].' - '.$smallTt['konec'];
			$smallTt['suffix'] = $r['pobockaId'];
		}
		if ($smallTt !== FALSE)
			$this->smallTimeTable[] = $smallTt;
	}

	public function loadETKHours ()
	{
		$q[] = 'SELECT hodiny.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky,
		vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_hodiny] AS hodiny');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON hodiny.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON hodiny.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON vyuky.svpPredmet = predmety.ndx');

		array_push ($q, ' WHERE 1');

		if (count($this->timetableVyukyPks))
			array_push ($q, ' AND (hodiny.ucitel = %i', $this->teacherNdx, ' OR hodiny.vyuka IN %in)', $this->timetableVyukyPks);
		else
			array_push ($q, ' AND hodiny.ucitel = %i', $this->teacherNdx);

		//array_push ($q, ' AND vyuky.skolniRok = %s', $this->academicYear);
		array_push ($q, ' AND hodiny.datum = %d', $this->today);
		//array_push ($q, ' AND hodiny.stavHlavni <= 2');

		array_push ($q, ' ORDER BY hodiny.zacatek, hodiny.ndx');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$itemId = $r['vyuka'].'_'.$r['rozvrh'];

			$pobockaId = $r['pobocka'];
			$item = [
				'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'], 'vyukaNazev' => $r['vyukaNazev'],
				'predmet' => $r['predmet'], 'predmetNazev' => $r['predmetNazev'],
				'vyuka' => $r['vyuka'], 'typVyuky' => $r['typVyuky'],
				'stav' => $r['stav'],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky']),
				'content' => []
			];

			$item['vyukaIcon'] = ($r['typVyuky'] === 0) ? 'iconGroupClass' : 'system/iconUser';

			$docState = $this->tableHodiny->getDocumentState ($r);
			$docStateStyle = $this->tableHodiny->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');
			$item['docStateStyle'] = $docStateStyle;
			$item['order'] = $docState['state']['mainState'].$item['zacatek'];

			$item['content'][] = ['class' => 'e10-text padd5', 'value' => $r['probiranaLatka']];


			$this->loadHour($item);

			if (isset($this->timetable[$itemId]))
			{
				$this->timetable[$itemId]['existedHour'] = $item;
			}
			else
				$this->ETKHours[] = $item;
		}
	}

	function createData()
	{
		foreach ($this->timetable as $item)
		{
			$tile = ['title' => [], 'body' => [], 'class' => 'e10-pane'];
			$title = [];

			$time = ['class' => 'h2', 'text' => $item['zacatek'] . ' - ' . $item['konec']];
			if ($this->todayTime >= $item['zacatek'] && $this->todayTime <= $item['konec'])
				$time['class'] .= ' e10-me';
			$title[] = $time;

			$titleClass = 'title';
			if (isset($item['existedHour']))
			{
				$titleClass .= ' e10-ds-block ' . $item['existedHour']['docStateStyle'];
			}
			$title[] = ['class' => 'e10-off pull-right', 'text' => $item['predmetNazev'], 'icon' => 'icon-angle-right'];
			$title[] = ['class' => 'pull-right', 'text' => $item['vyukaNazev'], 'icon' => $item['vyukaIcon']];

			$title[] = ['class' => 'block', 'text' => ''];
			if ($item['pobockaId'])
				$title[] = ['class' => 'e10-small', 'text' => $item['pobockaId'], 'icon' => 'system/iconOwner'];
			if ($item['ucebnaNazev'])
				$title[] = ['class' => 'e10-small', 'text' => $item['ucebnaNazev'], 'icon' => 'icon-sign-in'];

			if (isset($item['existedHour']))
			{
				if (!$this->app->mobileMode)
					$title[] = ['text' => 'Opravit hodinu v ETK', 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $item['existedHour']['ndx'],
						'class' => 'pull-right break', 'type' => 'button', 'actionClass' => 'btn btn-sm btn-primary', 'icon' => 'system/actionOpen',
						'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId];
			}
			else
			{
				$addParams = '__vyuka='.$item['vyuka'].'&__rozvrh='.$item['ndx'];
				$addParams .= '&__ucitel='.$this->app->userNdx();
				$addParams .= '&__datum='.$this->today->format('Y-m-d');
				$addParams .= '&__zacatek='.$item['zacatek'].'&__konec='.$item['konec'];
				$addParams .= '&__pobocka='.$item['pobocka'].'&__ucebna='.$item['ucebna'];

				if (!$this->app->mobileMode)
					$title[] = ['text' => 'Zadat hodinu do ETK', 'docAction' => 'new', 'table' => 'e10pro.zus.hodiny', //'pk' => $hodina['ndx'],
						'class' => 'pull-right', 'type' => 'button', 'actionClass' => 'btn btn-sm btn-success', 'icon' => 'system/actionAdd',
						'addParams' => $addParams,
						'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId];
			}


			$tile['title'][] = ['class' => $titleClass, 'value' => $title];

			if (isset($item['existedHour']))
			{
				$tile['body'] = array_merge($tile['body'], $item['existedHour']['content']);
			}

			$this->tiles[] = $tile;
		}


		foreach ($this->ETKHours as $item)
		{
			$tile = ['info' => [], 'class' => 'e10-pane e10-pane-table clear'];
			$title = [];

			$time = ['class' => 'h2', 'text' => $item['zacatek'] . ' - ' . $item['konec']];
			if ($this->todayTime >= $item['zacatek'] && $this->todayTime <= $item['konec'])
				$time['class'] .= ' e10-me';
			$title[] = $time;

			$title[] = ['class' => 'info pull-right', 'text' => $item['predmetNazev'], 'icon' => 'icon-angle-right'];
			$title[] = ['class' => 'info pull-right', 'text' => $item['vyukaNazev'], 'icon' => $item['vyukaIcon']];
			$title[] = ['class' => 'e10-small block', 'text' => $item['ucebnaNazev']];

			$tile['info'][] = ['class' => 'title '.$item['docStateStyle'], 'value' => $title];

			$btns = [];

			$addParams = '__vyuka='.$item['vyuka'].'&__rozvrh='.$item['ndx'];
			$addParams .= '&__ucitel='.$this->app->userNdx();
			$addParams .= '&__datum='.$this->today->format('Y-m-d');
			$addParams .= '&__zacatek='.$item['zacatek'].'&__konec='.$item['konec'];
			$addParams .= '&__pobocka='.$item['pobocka'].'&__ucebna='.$item['ucebna'];

			$btns[] = ['text' => 'Zadat hodinu do ETK', 'docAction' => 'new', 'table' => 'e10pro.zus.hodiny', //'pk' => $hodina['ndx'],
				'class' => 'pull-right XXclear', 'type' => 'button', 'actionClass' => 'btn btn-sm btn-success', 'icon' => 'system/actionAdd',
				'addParams' => $addParams,
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId];

			$tile['info'][] = ['class' => '', 'value' => $btns];

			$this->tiles[] = $tile;
		}

	}

	public function createContent ()
	{
		if (substr($this->widgetAction, 0, 14) === 'nastavitHodinu')
		{
			$hodinaNdx = intval (substr($this->widgetAction, 15));
			$this->tableHodiny->novaHodina ($hodinaNdx, $this->today);
		}
		else if (substr($this->widgetAction, 0, 13) === 'zahajitHodinu')
		{
			$hodinaNdx = intval (substr($this->widgetAction, 14));
			$this->tableHodiny->zahajitHodinu ($hodinaNdx);
		}
		else if (substr($this->widgetAction, 0, 13) === 'ukoncitHodinu')
		{
			$hodinaNdx = intval (substr($this->widgetAction, 14));
			$this->tableHodiny->ukoncitHodinu ($hodinaNdx);
		}
		else if (substr($this->widgetAction, 0, 18) === 'nastavitPritomnost')
		{
			$dochazkaNdx = intval (substr($this->widgetAction, 19));
			$this->tableHodiny->prepnoutPritomnost ($dochazkaNdx);
		}

		$this->loadTimetable();
		$this->loadETKHours();
		$this->createData();

		if (count($this->tiles))
			$this->addContent(['type' => 'tiles', 'tiles' => $this->tiles, 'class' => 'panes']);
		else
			$this->addContent(['type' => 'line', 'line' => ['text' => 'Dnes (už) neučíte...']]);
	}

	public function init ()
	{
		parent::init();

		$this->teacherNdx = $this->app->user()->data ('id');
		$this->today = utils::today();


		//$this->teacherNdx = 11; // březíková olga: 6 // -- hradilová: 1196 // ambrůzová: 5 //černoch petr: 9 // davidová: 684 // gerych tomáš: 11
		//$this->today = new \DateTime('2022-09-05');

		$this->todayYear = intval($this->today->format('Y'));
		$this->todayMonth = intval($this->today->format('m'));
		$this->todayDay = intval($this->today->format('d'));
		$this->todayDow = intval($this->today->format('N')) - 1;
		$this->academicYear = zusutils::aktualniSkolniRok ();

		$now = new \DateTime();
		$this->todayTime = $now->format('H:i');

		//$this->todayTime = '12:40';

		$this->tableHodiny = $this->app->table('e10pro.zus.hodiny');
		$this->znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');
	}


	public function title() {return FALSE;}
}
