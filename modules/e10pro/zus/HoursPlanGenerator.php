<?php

namespace e10pro\zus;

//require_once __APP_DIR__ . '/e10-modules/e10/persons/tables/persons.php';
//require_once __APP_DIR__ . '/e10-modules/e10/base/base.php';
//require_once __APP_DIR__ . '/e10-modules/e10pro/zus/zus.php';

use function E10\sortByOneKey;
use \E10\utils, \E10\Utility, \e10\str, \E10Pro\Zus\zusutils;


/**
 * Class HoursPlanGenerator
 * @package e10pro\zus
 */
class HoursPlanGenerator extends Utility
{
	var $beginDate;
	var $startDate;
	var $endDate;

	var $academicYearId;
	var $academicYear;
	var $halfYearDate;
	var $attHalfYearDate;
	var $attEndYearDate;

	var $cntNew = 0;
	var $cntExist = 0;

	var $params;
	var $tablePersons;
	var $tableHodiny;
	var $znamkyHodnoceni;
	var $dochazkaPritomnost;
	var $hourAttendanceTypes;
	var $hourAttendanceLetters;
	var $fullAttendanceShortcuts;

	var $etkRecData;
	var $studiumRecData = NULL;
	var $datumPredcasnehoUkonceni = NULL;

	var $allHours = [];
	var $existedHours = [];
	var $newHours = [];
	var $halfYears = [];
	var $collectiveAttendance = [];
	var $collectiveYears = [];

	protected function createDateTime ($day, $time)
	{
		return utils::createDateTimeFromTime($day, $time);
	}

	public function setParams ($params)
	{
		$this->etkRecData = $params['etkRecData'];

		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->tableHodiny = $this->app->table('e10pro.zus.hodiny');
		$this->znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');
		$this->dochazkaPritomnost = $this->app->cfgItem ('zus.pritomnost');
		$this->hourAttendanceLetters = [0 => '-', 1 => 'P', 2 => 'O', 3 => 'N'];
		$this->fullAttendanceShortcuts = [
			0 => "--", 1 =>  "P", 2 => "NO", 3 => "NN", 4 => "SS", 5 => "PR", 6 => "ŘV", 7 => "V"
		];

		if ($this->etkRecData['typ'] === 1)
		{ // individualni
			$this->studiumRecData = $this->app()->loadItem($this->etkRecData['studium'], 'e10pro.zus.studium');
			if (!utils::dateIsBlank($this->studiumRecData['datumUkonceniSkoly']))
			{
				$this->datumPredcasnehoUkonceni = $this->studiumRecData['datumUkonceniSkoly'];
			}
		}
		$this->hourAttendanceTypes = $this->tableHodiny->columnInfoEnum ('pritomnost');

		$this->params = $params;

		$this->academicYearId = $this->etkRecData['skolniRok'];
		$this->academicYear = $this->app->cfgItem ('e10pro.zus.roky.'.$this->academicYearId);
		$this->halfYearDate = utils::createDateTime($this->academicYear['V1']);

		$this->beginDate = utils::createDateTime($this->academicYear['zacatek']);
		$this->startDate = utils::createDateTime($this->academicYear['zacatek']);

		if (isset($this->academicYear['konec']))
			$this->endDate = utils::createDateTime($this->academicYear['konec']);
		else
			$this->endDate = utils::createDateTime($this->academicYear['V2']);

		if (!utils::dateIsBlank($this->etkRecData['datumUkonceni']))
		{
			$this->endDate = utils::createDateTime($this->etkRecData['datumUkonceni']);
			$this->addMessage("Předčasné ukončení výuky k ".utils::datef($this->etkRecData['datumUkonceni'], '%d'));
		}
		if (!utils::dateIsBlank($this->etkRecData['datumZahajeni']))
		{
			$this->beginDate = utils::createDateTime($this->etkRecData['datumZahajeni']);
			$this->startDate = utils::createDateTime($this->etkRecData['datumZahajeni']);
			$this->addMessage("Opožděné zahájení výuky od ".utils::datef($this->etkRecData['datumZahajeni'], '%d'));
		}

		if ($this->etkRecData['typ'] === 1)
		{ // individualni
			if (!utils::dateIsBlank($this->studiumRecData['datumUkonceniSkoly']) && $this->studiumRecData['datumUkonceniSkoly'] < $this->beginDate)
			{
				$this->endDate = utils::createDateTime($this->studiumRecData['datumUkonceniSkoly']);
			}

			if (!utils::dateIsBlank($this->studiumRecData['datumNastupuDoSkoly']) && $this->studiumRecData['datumNastupuDoSkoly'] > $this->beginDate)
			{
				$this->beginDate = utils::createDateTime($this->studiumRecData['datumNastupuDoSkoly']);
			}
		}

		//echo json_encode($this->academicYear)."\n";
	}

	function loadExistedHours ()
	{
		$hoursIdsCnt = [];

		$this->attHalfYearDate = utils::createDateTime($this->academicYear['V1']);
		if (isset($this->academicYear['KK1']))
			$this->attHalfYearDate = utils::createDateTime($this->academicYear['KK1']);
		$this->attEndYearDate = utils::createDateTime($this->academicYear['V2']);
		if (isset($this->academicYear['KK2']))
			$this->attEndYearDate = utils::createDateTime($this->academicYear['KK2']);

		$q[] = 'SELECT hodiny.*, ucitele.firstName, ucitele.lastName ';
		array_push($q, ' FROM e10pro_zus_hodiny AS hodiny');
		array_push($q, ' LEFT JOIN e10_persons_persons AS [ucitele] ON hodiny.ucitel = ucitele.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND hodiny.vyuka = %i', $this->params['etkNdx']);

		//if (!$this->app()->hasRole('root'))
			array_push($q, ' AND hodiny.stav != %i', 9800);

		array_push($q, ' ORDER BY hodiny.[datum], hodiny.[zacatek]');
		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			$hourLenMinutes = utils::timeToMinutes($r['konec']) - utils::timeToMinutes($r['zacatek']);
			$hourLen = intval((utils::timeToMinutes ($r['konec']) - utils::timeToMinutes ($r['zacatek'])) / 45);
			$docState = $this->tableHodiny->getDocumentState ($r);
			if ($docState)
				$docStateClass = $this->tableHodiny->getDocumentStateInfo ($docState ['states'], $r, 'styleClass');

			$teacherNick = str::substr($r['lastName'], 0, 3).str::substr($r['firstName'], 0, 3);

			if ($r['datum'])
				$hourId = strftime('%V_%g_', $r['datum']->format('U')).$r['vyuka'];
			else
				$hourId = '9999999_'.$r['vyuka'];
			if (!isset($hoursIdsCnt[$hourId]))
				$hoursIdsCnt[$hourId] = 0;
			else
			{
				$hoursIdsCnt[$hourId]++;
				$hourId .= '_'.$hoursIdsCnt[$hourId];
				$this->addMessage("Duplicitní vyučovací hodina ".utils::datef($r['datum'], '%d'));
			}
			$item = [
					'ndx' => $r['ndx'], 'docStateClass' => $docStateClass,
					'datum' => $r['datum'], 'ucitel' => $teacherNick,
					'info' => [],
					'title' => [],
					'order' => ($r['datum']) ? $r['datum']->format ('Ymd') : '9999999'
			];

			$hy = ($r['datum'] <= $this->attHalfYearDate) ? 1 : 2;
			if ($r['datum'] >= $this->attEndYearDate)
				$hy = 3;

			if (!isset($this->halfYears[$hy]))
			{
				$this->halfYears[$hy] = [
						'countItems' => 0, 'countHours' => 0, 'hours' => [], 'grading' => ['sum' => 0, 'cnt' => 0], 'attendance' => []
				];
				$this->halfYears[$hy]['attendance']['ALL'] = ['cnt' => 0];
				foreach ($this->dochazkaPritomnost as $dpid => $dpdef)
				{
					$this->halfYears[$hy]['attendance'][$dpid] = ['cnt' => 0];
				}
			}

			$item['title'][] = ['text' => utils::datef($r['datum'], '%d'), 'suffix' => $hourLenMinutes.' min', 'icon' => 'system/iconClock', 'class' => 'h2'];
			if (utils::dateIsBlank($r['datum']))
			{
				$item['title'][] = ['text' => 'Není zadáno datum hodiny', 'class' => 'label label-danger', 'icon' => 'system/iconWarning'];
				$this->addMessage('Vyučovací hodina bez datumu');
			}
			$this->halfYears[$hy]['attendance']['ALL']['cnt'] += $hourLen;

			if ($this->etkRecData['typ'] === 0)
			{ // kolektivní
				$this->collectiveHourAttendanceRecap ($r['ndx'], $item, $hy, $hourLen);
			}
			else
			{ // individuální
				$hat = $this->attendanceType ($r['pritomnost']);
				$this->halfYears[$hy]['attendance'][$hat]['cnt'] += $hourLen;

				if ($this->etkRecData['typ'] !== 2)
				{
					$dochazkaPritomnost = $this->dochazkaPritomnost[$hat];
					if ($dochazkaPritomnost['showRecap'])
					{
						$p = ['text' => $dochazkaPritomnost['name'], 'icon' => $dochazkaPritomnost['icon'], 'class' => 'label pull-right ' . $dochazkaPritomnost['labelClass']];
						$item['title'][] = $p;
					}
					elseif ($r['pritomnost'] > 3)
					{
						$p = ['text' => $this->hourAttendanceTypes[$r['pritomnost']], 'icon' => 'system/iconCheck', 'class' => 'label pull-right ' . 'label-default'];
						$item['title'][] = $p;
					}
					if (intval($r['klasifikaceZnamka']) != 0)
					{
						//$hodnoceni = $this->znamkyHodnoceni[$r['klasifikaceZnamka']];
						$z = ['text' => strval($r['klasifikaceZnamka']), 'class' => 'label label-primary pull-right', 'icon' => 'system/iconStar'];
						if ($r['klasifikacePoznamka'] !== '')
							$z['suffix'] = $r['klasifikacePoznamka'];
						$item['title'][] = $z;

						$this->halfYears[$hy]['grading']['sum'] += intval($r['klasifikaceZnamka']);
						$this->halfYears[$hy]['grading']['cnt']++;
					}
				}
			}


			$item['info'][] = ['texy' => $r['probiranaLatka'].' ', 'infoClass' => 'padd5'];

			$this->halfYears[$hy]['hours'][$hourId] = $item;

			if ($r['datum'])
				$this->startDate = clone $r['datum'];
		}
	}

	protected function prepareAllHours ()
	{
		if (!count($this->halfYears))
			return;

		$this->startDate->add (new \DateInterval('P1D'));

		foreach ([3, 2, 1] as $hyId)
		{
			if (!isset ($this->halfYears[$hyId]))
				continue;

			$hyTitle = ['title' => [], 'class' => 'tl-header'];
			$ttl = ($hyId === 3) ? 'ukončení roku' : $hyId.'. '.'pololetí';
			$hyTitle['title'][] = ['text' => $ttl, 'class' => 'h2'];

			if ($this->etkRecData['typ'] !== 2)
			{
				foreach ($this->dochazkaPritomnost as $dpid => $dpdef)
				{
					if (isset($this->halfYears[$hyId]['attendance']) && $this->halfYears[$hyId]['attendance'][$dpid]['cnt'])
					{
						if (!$dpdef['showRecap'])
							continue;
						$hyTitle['title'][] = [
							'text' => $dpdef['name'].': '.$this->halfYears[$hyId]['attendance'][$dpid]['cnt'],
							'icon' => $dpdef['icon'], 'class' => 'padd5 pull-right label '.$dpdef['labelClass'],
							'suffix' => 'hod'
						];
					}
				}

				if (isset($this->halfYears[$hyId]['grading']) && $this->halfYears[$hyId]['grading']['cnt'])
				{
					$gradingAvg = $this->halfYears[$hyId]['grading']['sum'] / $this->halfYears[$hyId]['grading']['cnt'];
					$hyTitle['title'][] = ['text' => 'Průměr: '.round($gradingAvg, 1), 'icon' => 'system/iconStar', 'class' => 'padd5 pull-right label label-primary'];
				}
			}

			$this->existedHours[] = $hyTitle;
			$this->allHours[] = $hyTitle;

			foreach (sortByOneKey($this->halfYears[$hyId]['hours'], 'order', TRUE, FALSE) as $hourItem)
			{
				$this->existedHours[] = $hourItem;
				$this->allHours[] = $hourItem;
			}
		}

	}

	protected function attendanceType ($hourAttendance)
	{
		$ha = intval($hourAttendance);
		if ($ha === 0)
			return 0;

		if ($ha === 2)
			return 2;

		if ($ha === 3)
			return 3;

		return 1;
	}

	protected function collectiveHourAttendanceRecap ($hourNdx, &$item, $hy, $hourLen)
	{
		if (count($this->collectiveAttendance) === 0)
			$this->loadStudents();

		$sum = [];

		$q[] = 'SELECT * FROM e10pro_zus_hodinydochazka';
		array_push ($q, ' WHERE [hodina] = %i', $hourNdx);

		$rows = $this->db()->query ($q);
		$cntRows = 0;
		foreach ($rows as $r)
		{
			$hat = $this->attendanceType ($r['pritomnost']);
			if (!isset($sum[$hat]))
				$sum[$hat] = $hourLen;
			else
				$sum[$hat] += $hourLen;

			$dateId = ($item['datum']) ? $item['datum']->format('Y-m-d') : '!!!';

			if (!isset($this->collectiveAttendance[$r['studium']]['attHours'][$hy][$dateId]))
			{
				$this->collectiveAttendance[$r['studium']]['attHours'][$hy][$dateId] = [
					'type' => $this->hourAttendanceLetters[$hat],
					'typeFull' => $this->fullAttendanceShortcuts[$r['pritomnost']],
					'date' => $item['datum'], 'studentNdx' => $r['student']
				];
			}
			if (!isset($this->collectiveAttendance[$r['studium']]['attSums'][$hy]))
				$this->collectiveAttendance[$r['studium']]['attSums'][$hy] = [0 => 0, 1 => 0, 2 => 0, 3 => 0];

			$this->collectiveAttendance[$r['studium']]['attSums'][$hy][$hat] += $hourLen;

			$cntRows++;
		}


		foreach ($sum as $hatId => $cnt)
		{
			if ($hatId !== 1)
			{
				$dochazkaPritomnost = $this->dochazkaPritomnost[$hatId];
				$p = ['text' => $dochazkaPritomnost['name'].': '.$cnt, 'icon' => $dochazkaPritomnost['icon'], 'class' => 'label pull-right ' . $dochazkaPritomnost['labelClass']];
				$item['title'][] = $p;
			}
		}

		if ($cntRows === 0 && $this->app()->hasRole('zusadm'))
		{
			$item['title'][] = ['text' => 'Chybí docházka', 'class' => 'pull-right label label-danger'];
		}
	}

	function loadStudents ()
	{
		$q[] = 'SELECT vyukyStudenti.*, studenti.fullName as studentName, studenti.ndx as studentNdx,';
		array_push ($q, ' vyukyRocniky.zkratka as rocnikZkratka, vyukyRocniky.poradi as rocnikPoradi,');
		array_push ($q, ' ucitele.firstName as ucitelJmeno, ucitele.lastName as ucitelPrijmeni');
		array_push ($q, ' FROM e10pro_zus_vyukystudenti AS vyukyStudenti');
		array_push ($q, ' LEFT JOIN [e10pro_zus_studium] AS vyukyStudia ON vyukyStudenti.studium = vyukyStudia.ndx');
		array_push ($q, ' LEFT JOIN [e10pro_zus_rocniky] AS vyukyRocniky ON vyukyStudia.rocnik = vyukyRocniky.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS studenti ON vyukyStudia.student = studenti.ndx');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS ucitele ON vyukyStudia.ucitel = ucitele.ndx');
		array_push ($q, ' WHERE vyukyStudenti.vyuka = %i', $this->params['etkNdx']);
		array_push ($q, ' ORDER BY studenti.lastName');

		$pks = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$teacherNick = str::substr($r['ucitelPrijmeni'], 0, 5).str::substr($r['ucitelJmeno'], 0, 3);

			$item = [
				'studentNdx' => $r['studentNdx'], 'studentName' => $r['studentName'],
				'rocnikZkratka' => $r['rocnikZkratka'], 'ucitel' => $teacherNick,
				'attHours' => [], 'attSum' => []
			];
			$this->collectiveAttendance[$r['studium']] = $item;
			if (!isset($this->collectiveYears[$r['rocnikZkratka']]))
				$this->collectiveYears[$r['rocnikZkratka']] = ['zkratka' => $r['rocnikZkratka'], 'poradi' => $r['rocnikPoradi']];

			if ($r['studentNdx'] && !in_array($r['studentNdx'], $pks))
				$pks[] = $r['studentNdx'];
		}

		// -- věk studenta
		$properties = $this->tablePersons->loadProperties ($pks);
		foreach ($this->collectiveAttendance as $studiumNdx => $studentInfo)
		{
			$studentNdx = $studentInfo['studentNdx'];
			$bdate = \E10\base\searchArrayItem($properties [$studentNdx]['ids'], 'pid', 'birthdate');
			if ($bdate ['valueDate'])
			{
				$age = $bdate ['valueDate']->diff($this->startDate)->y;
				$this->collectiveAttendance[$studiumNdx]['vek'] = $age;
			}
		}
	}

	public function collectiveAttendanceTable ($hy)
	{
		$table = [];
		$columns = ['student' => '_Student', 'vek' => '|Věk', 'rocnik' => '|Roč.', 'ucitel' => 'Učit.'];
		$dates = [];
		$months = [];

		foreach ($this->collectiveAttendance as $studiumNdx => $studentAtt)
		{
			$studentNdx = $studentAtt['studentNdx'];
			$item = ['student' => $studentAtt['studentName'], 'vek' => $studentAtt['vek'], 'rocnik' => $studentAtt['rocnikZkratka'], 'ucitel' => $studentAtt['ucitel']];

			if (isset($studentAtt['attHours'][$hy]))
			{
				foreach ($studentAtt['attHours'][$hy] as $dateId => $att)
				{
					$item[$dateId] = $att['typeFull']; // $att['type']
					//$item['ucitel'] => $studentAtt['ucitel']

					if (!isset($dates[$dateId]))
						$dates[$dateId] = ['date' => $att['date'], 'test' => $dateId];

					if (!isset($columns[$dateId]))
						$columns[$dateId] = '|' . (($att['date']) ? $att['date']->format('d') : '!!!');
				}
			}

			$attSumStr = '';
			$attSum = isset($this->collectiveAttendance[$studiumNdx]['attSums'][$hy]) ? $this->collectiveAttendance[$studiumNdx]['attSums'][$hy] : 0;

			if ($attSum[2])
				$attSumStr .= $attSum[2].'oml.';
			if ($attSum[3])
				$attSumStr .= ' '.$attSum[3].'neom.';

			if ($attSumStr === '')
				$attSumStr = '0';

			$item['ATT-SUM'] = $attSumStr;

			$table[] = $item;
		}

		foreach ($this->newHours as $nh)
		{
			$nhy = ($nh['date'] <= $this->attHalfYearDate) ? 1 : 2;
			if ($nh['date'] >= $this->attEndYearDate)
				$nhy = 3;

			if ($nhy !== $hy)
				continue;

			$dateId = $nh['date']->format ('Y-m-d');
			if (!isset($dates[$dateId]))
				$dates[$dateId] = ['date' => $nh['date'], 'plan' => 1];
			if (!isset($columns[$dateId]))
				$columns[$dateId] = '|'.$nh['date']->format('d');
		}


		// -- dates
		foreach ($dates as $dateId => $dateDef)
		{
			$month = intval($dateDef['date']->format('m'));

			if (!isset($months[$month]))
				$months[$month] = ['days' => 0, 'plan' => 0, 'name' => utils::$monthNames[$month - 1], 'dateId' => $dateId];

			$months[$month]['days']++;
			if (isset($dateDef['plan']))
				$months[$month]['plan']++;
		}



		$columns['ATT-SUM'] = '|Nepř.';


		// -- create header
		$header = [];
		$firstRow = [
			'student' => 'Příjmení a jméno žáka', 'vek' => 'Věk', 'rocnik' => 'Roč.', 'ucitel' => 'Učitel',
			'ATT-SUM' => 'Zam.h.',
			'_options' => [
				'rowSpan' => ['student' => 2, 'ATT-SUM' => 2, 'rocnik' => 2, 'vek' => 2, 'ucitel' => 2],
				'colSpan' => [], 'cellClasses' => ['rocnik' => 'center', 'vek' => 'center']
			]
		];
		foreach ($months as $monthNum => $monthDef)
		{
			$firstRow[$monthDef['dateId']] = $monthDef['name'];
			$firstRow['_options']['colSpan'][$monthDef['dateId']] = $monthDef['days'];
			$firstRow['_options']['cellClasses'][$monthDef['dateId']] = 'center';
			if ($monthDef['days'] === $monthDef['plan'])
				$firstRow['_options']['cellClasses'][$monthDef['dateId']] .= ' e10-off';
		}
		$header[] = $firstRow;

		$secondRow = [];
		foreach ($dates as $dateId => $dateDef)
		{
			$secondRow[$dateId] = (($dateDef['date']) ? intval($dateDef['date']->format('d')) : '!!!');
			$secondRow['_options']['cellClasses'][$dateId] = 'e10-icon';

			if (isset($dateDef['plan']))
				$secondRow['_options']['cellClasses'][$dateId] .= ' e10-off';
		}
		$header[] = $secondRow;

		// -- final content
		$c = [
			'type' => 'table', 'table' => $table, 'header' => $columns, 'title' => $hy.'. pololetí',
			'params' => ['tableClass' => 'e10-print-small', 'header' => $header]
		];

		if ($hy === 1 || count($table) > 10)
			$c['params']['newPage'] = 1;

		return $c;
	}

	public function addHour ($rozvrh, $date)
	{
		if ($rozvrh['typVyuky'] === 0)
		{ // skupinova vyuka, zkontrolovat studenty
			$pocetStudentu = $this->app->db()->query ('SELECT COUNT(*) AS cnt FROM e10pro_zus_vyukystudenti WHERE vyuka = %i', $rozvrh['vyuka'])->fetch();
			if (!$pocetStudentu || !$pocetStudentu['cnt'])
			{
				return;
			}
		}

		$hy = ($date <= $this->attHalfYearDate) ? 1 : 2;
		if ($date >= $this->attEndYearDate)
			$hy = 3;

		if (!isset($this->halfYears[$hy]))
		{
			$this->halfYears[$hy] = [
				'countItems' => 0, 'countHours' => 0, 'hours' => [], 'grading' => ['sum' => 0, 'cnt' => 0], 'attendance' => []
			];
			$this->halfYears[$hy]['attendance']['ALL'] = ['cnt' => 0];
			foreach ($this->dochazkaPritomnost as $dpid => $dpdef)
			{
				$this->halfYears[$hy]['attendance'][$dpid] = ['cnt' => 0];
			}
		}


		$item = ['rozvrh' => $rozvrh, 'date' => clone $date];
		if ($item['date'] > $this->startDate)
		{
			$this->newHours[$this->cntNew] = $item;
			$this->cntNew++;
		}

		$hourId = strftime('%V_%g_', $item['date']->format('U')).$item['rozvrh']['vyuka'];
		$newHourItem = [
			"class" => "e10-warning1",
			'title' =>[['text' => $item['date']->format ('d.m.Y'), 'icon' => 'system/iconClock', 'class'=>'h2']],
			'info' => [['text' => 'Hodina není zadána', 'class' => 'padd5 e10-off']],
			'order' => $item['date']->format ('Ymd')
		];


		$newHourItem['title'][] = [
			'text' => ' ', 'icon' => 'system/actionAdd', 'action' => 'new', 'data-table' => 'e10pro.zus.hodiny',
			'class' => 'pull-right', 'CCCbtnClass' => 'btn-sm',
			'data-addParams' => '__vyuka='.$item['rozvrh']['vyuka'].'&__rozvrh='.$item['rozvrh']['ndx'].'&__ucitel='.$item['rozvrh']['ucitel'].
				'&__datum='.$item['date']->format('Y-m-d').'&__zacatek='.$item['rozvrh']['zacatek'].'&__konec='.$item['rozvrh']['konec'].
				'&__pobocka='.$item['rozvrh']['pobocka'].'&__ucebna='.$item['rozvrh']['ucebna']
		];

		if ($item['date'] > utils::today())
			return;

		if (!isset($this->halfYears[$hy]['hours'][$hourId]))
			$this->halfYears[$hy]['hours'][$hourId] = $newHourItem;
	}

	public function addDay ($date)
	{
		$dow = intval($date->format('N')) - 1;

		$q[] = 'SELECT rozvrh.*, vyuky.typ as typVyuky FROM [e10pro_zus_vyukyrozvrh] AS rozvrh';
		array_push($q, ' LEFT JOIN [e10pro_zus_vyuky] AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push($q, ' WHERE vyuky.stav <= 4000');
		array_push($q, ' AND [den] = %i', $dow);

		if (isset($this->params['etkNdx']))
			array_push($q, ' AND rozvrh.vyuka = %i', $this->params['etkNdx']);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->addHour($r->toArray(), $date);
		}
	}

	public function run ()
	{
		$this->loadExistedHours();
		$date = utils::createDateTime($this->beginDate);
		while ($date <= $this->endDate)
		{
			$this->addDay($date);
			$date->add (new \DateInterval('P1D'));
		}

		$this->prepareAllHours();
	}
}
