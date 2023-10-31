<?php

namespace e10pro\zus;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use e10\utils, e10\str;


/**
 * Class WidgetPlanTeacher
 * @package e10pro\zus
 */
class WidgetPlanTeacher extends \Shipard\UI\Core\WidgetPane
{
	var $teacher;
	var $today;
	var $planMode = 'week';

	/** @var \e10\table */
	var $tableHodiny;

	var $firstDay;
	var $lastDay;

	var $academicYear;
	var $dataTimeTable = [];
	var $dataExistedHours = [];

	function loadTimetable ()
	{
		$q[] = 'SELECT rozvrh.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky, vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON rozvrh.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS ucitele ON rozvrh.ucitel = ucitele.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND rozvrh.ucitel = %i', $this->teacher);
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->academicYear);
		array_push ($q, ' AND rozvrh.stavHlavni <= 2');

		array_push ($q, ' AND (vyuky.datumUkonceni IS NULL OR vyuky.datumUkonceni > %t)', $this->today);
		array_push ($q, ' AND (vyuky.datumZahajeni IS NULL OR vyuky.datumZahajeni <= %t)', $this->today);

		array_push ($q, ' ORDER BY ucitele.lastName, ucitele.firstName, rozvrh.den, rozvrh.zacatek, rozvrh.ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$teacherNdx = $r['ucitel'];
			$item = [
				'ndx' => $r['ndx'], 'pobocka' => $r['pobocka'], 'pobockaId' => $r['pobockaId'],
				'ucebna' => $r['ucebna'], 'ucebnaNazev' => $r['ucebnaNazev'],
				'zacatek' => $r['zacatek'], 'konec' => $r['konec'],
				'vyuka' => $r['vyuka'], 'vyukaNazev' => $r['vyukaNazev'], 'typVyuky' => $r['typVyuky'], 'rozvrh' => $r['ndx'],
				'predmetNazev' => $r['predmetNazev'],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app, $r['rocnik'], $r['typVyuky'], 'zkratka'),
			];
			$this->dataTimeTable[$r['den']][] = $item;
		}
	}

	public function loadExistedHours ()
	{
		$q[] = 'SELECT hodiny.*, pobocky.shortName as pobockaId, vyuky.nazev as vyukaNazev, vyuky.typ as typVyuky,
		vyuky.rocnik as rocnik, predmety.nazev as predmetNazev, ucebny.shortName as ucebnaNazev';
		array_push ($q, ' FROM [e10pro_zus_hodiny] AS hodiny');
		array_push ($q, ' LEFT JOIN e10_base_places AS pobocky ON hodiny.pobocka = pobocky.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS ucebny ON hodiny.ucebna = ucebny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON vyuky.svpPredmet = predmety.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND hodiny.ucitel = %i', $this->teacher);

		array_push ($q, ' AND (hodiny.datum >= %d', $this->firstDay, ' AND hodiny.datum <= %d)', $this->lastDay);
		array_push ($q, ' AND hodiny.stavHlavni < 5');

		array_push ($q, ' ORDER BY hodiny.zacatek, hodiny.ndx');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$itemId = $r['datum']->format('Ymd').'_'.$r['vyuka'].'_'.$r['rozvrh'];

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

			//$this->loadHour($item);
			$this->dataExistedHours[$itemId] = $item;
		}
	}

	function loadData()
	{
		$this->loadTimetable();
		$this->loadExistedHours();
	}

	function renderData()
	{
		$this->renderDataMobile();
	}

	function renderDataMobile()
	{
		$tabsMode = $this->app->mobileMode;

		$h = ['time' => 'Čas', 'content' => 'Výuka'];
		$tabs = [];

		if (!$tabsMode)
			$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-widget-columns']);

		$cwClass = 'width20';
		$dow = intval($this->firstDay->format('N')) - 1;

		$firstDay = 0;
		$numDays = 5;

		if ($this->planMode === 'today')
		{
			$cwClass = 'width50';
		}

		if (!$tabsMode)
		{
			//$firstDay = intval($this->today->format('N')) - 1;
			//$numDays = 5 - $firstDay;
		}

		$activeDate = clone $this->firstDay;
		if ($firstDay !== 0)
			$activeDate->add (new \DateInterval('P'.$firstDay.'D'));

		$dayIndex = 0;
		for ($day = $firstDay; $day < $firstDay + $numDays; $day++)
		{
			$t = [];
			$lastOffice = 0;
			if (isset($this->dataTimeTable[$day]))
			{
				foreach ($this->dataTimeTable[$day] as $tt)
				{
					$itemId = $activeDate->format('Ymd').'_'.$tt['vyuka'].'_'.$tt['rozvrh'];
					$rowClass = 'e10-row-info';

					if ($lastOffice !== $tt['pobocka'])
					{
						$officeRow = ['time' => $tt['pobockaId'], '_options' => ['class' => 'subheader', 'colSpan' => ['time' => 2]]];
						$t[] = $officeRow;
					}

					$rt = [
						['text' => $tt['zacatek'], 'suffix' => $tt['konec'], 'class' => 'block']
					];

					$eex = $this->db()->query('SELECT * FROM [e10pro_zus_omluvenkyHodiny] AS hodiny ',
																		' LEFT JOIN [e10pro_zus_omluvenky] AS omluvenky ON hodiny.omluvenka = omluvenky.ndx',
																		' WHERE [vyuka] = %i', $tt['vyuka'],
																		' AND [datum] = %d', $activeDate,
																		' AND [omluvenky].[docState] = %i', 4000)->fetch();

					$rc = [];
					if ($eex)
					{
						$rc[] = ['text' => $tt['vyukaNazev'], 'class' => 'e10-bold'];
						$rc[] = ['text' => 'Omluvenka', 'class' => 'label label-danger pull-right'];
					}
					else
						$rc[] = ['text' => $tt['vyukaNazev'], 'class' => 'block e10-bold'];
					$rc[] = ['text' => $tt['predmetNazev'], 'class' => 'block'];
					if ($tt['ucebnaNazev'] && $tt['ucebnaNazev'] !== '')
						$rc[] = ['text' => $tt['ucebnaNazev'], 'class' => 'block id'];

					if (isset($this->dataExistedHours[$itemId]))
					{
						$rowClass = $this->dataExistedHours[$itemId]['docStateStyle'];
						if (!$this->app->mobileMode)
						{
							$rt[] = [
								'text' => '', 'docAction' => 'edit', 'icon' => 'system/actionOpen',
								'table' => 'e10pro.zus.hodiny', 'pk' => $this->dataExistedHours[$itemId]['ndx'],
								'actionClass' => 'btn btn-sm btn-default width90',
								'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
							];
						}
						else
						{
							$rt[] = [
								'text' => '', 'action' => 'form', 'data-table' => 'e10pro.zus.hodiny', 'data-pk' => $this->dataExistedHours[$itemId]['ndx'],
								'data-classId' => 'e10pro.zus.MobileFormETK', 'data-operation' => 'open',
								'type' => 'button', 'actionClass' => 'btn btn-sm btn-default width90 e10-trigger-action', 'icon' => 'system/actionOpen',
								'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId
							];
						}
					}
					else
					{
						$addParams = '__vyuka='.$tt['vyuka'].'&__rozvrh='.$tt['ndx'];
						$addParams .= '&__ucitel='.$this->teacher;
						$addParams .= '&__datum='.$activeDate->format('Y-m-d');
						$addParams .= '&__zacatek='.$tt['zacatek'].'&__konec='.$tt['konec'];
						$addParams .= '&__pobocka='.$tt['pobocka'].'&__ucebna='.$tt['ucebna'];

						if (!$this->app->mobileMode)
						{
							$rt[] = [
								'text' => '', 'docAction' => 'new', 'table' => 'e10pro.zus.hodiny',
								'type' => 'button', 'actionClass' => 'btn btn-sm btn-success width90', 'icon' => 'system/actionAdd',
								'addParams' => $addParams,
								'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId];
						}
						else
						{
							$rt[] = [
								'text' => '', 'action' => 'form', 'data-table' => 'e10pro.zus.hodiny',
								'data-classId' => 'e10pro.zus.MobileFormETK', 'data-operation' => 'new',
								'type' => 'button', 'actionClass' => 'btn btn-sm btn-default width90 e10-trigger-action', 'icon' => 'system/actionAdd',
								'data-addParams' => $addParams,
								'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widgetId];
						}
					}

					$row = ['time' => $rt, 'content' => $rc, '_options' => ['cellClasses' => ['time' => $rowClass, 'content' => $rowClass]]];
					$t[] = $row;

					$lastOffice = $tt['pobocka'];
				}
			}

			if (count($t))
			{
				$content = [
					'type' => 'table', 'table' => $t, 'header' => $h,
					'params' => ['hideHeader' => 1, 'colClasses' => ['time' => 'width10 nowrap']]
				];
			}
			else
			{
				$content = ['type' => 'line', 'line' => ['text' => 'Tento den nemáte žádnou výuku...', 'class' => 'padd5 e10-off']];
			}

			$dow2 = intval($activeDate->format('N')) - 1;

			$tabTitle = ['text' => str::toupper(utils::$dayShortcuts[$dow2]), 'suffix' => $activeDate->format ('d.m')];
			$tabs[] = ['title' => $tabTitle, 'content' => [$content]];

			if (!$tabsMode)
			{
				$active = ($activeDate->format('Ymd') === $this->today->format('Ymd')) ? ' active' : '';
				$tabTitle['class'] = ($active !== '') ? 'padd5 h2 e10-bg-t5' : 'padd5 h2 ';
				$this->addContent(['line' => $tabTitle, 'openCell' => 'e10-fx-col '.$cwClass.$active]);

				$content['closeCell'] = 1;
				$content['pane'] = 'padd5';
				$this->addContent($content);

				if ($dayIndex == 1 || $dayIndex == 3)
				{
					$this->addContent(['type' => 'grid', 'cmd' => 'fxClose']);
					$this->addContent(['type' => 'grid', 'cmd' => 'mt1 e10-fx-row e10-widget-columns']);
				}
			}
			$dayIndex++;
			$activeDate->add (new \DateInterval('P1D'));
		}

		if ($tabsMode)
		{
			$selectedTab = ($dow < 5) ? strval($dow) : '0';
			$this->addContent(['pane' => 'e10-pane-widget', 'tabsId' => 'mainTabs', 'selectedTab' => $selectedTab, 'tabs' => $tabs]);
		}
		else
			$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}

	public function createContent ()
	{
		$this->tableHodiny = $this->app->table('e10pro.zus.hodiny');

		$this->teacher = $this->app->userNdx();
		//$this->teacher = 4255;

		$this->today = utils::today();
		//$this->today = new \DateTime('2022-09-05');

		$this->firstDay = clone $this->today;
		$this->firstDay = $this->firstDay->modify(('Monday' === $this->firstDay->format('l')) ? 'monday this week' : 'last monday');

		$this->lastDay = clone $this->firstDay;
		$this->lastDay->add (new \DateInterval('P7D'));

		$this->academicYear = zusutils::aktualniSkolniRok();

		$this->loadData();
		$this->renderData();
	}

	public function title()
	{
		return FALSE;
	}
}
