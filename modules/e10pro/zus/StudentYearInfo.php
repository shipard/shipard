<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use \e10\utils, \e10\Utility, \e10pro\zus\zusutils;


/**
 * Class StudentYearInfo
 * @package e10pro\zus
 */
class StudentYearInfo extends Utility
{
	var $studentNdx;
	var $studiumNdx;
	var $academicYearId;
	var $academicYear;
	var $halfYearDate;
	var $attHalfYearDate;
	var $attEndYearDate;

	var $params;
	var $povolenePredmety = NULL;

	var $etk = [];
	var $info = [];

	var $infoTable;
	var $infoHeader;
	var $infoHeaderHorizontal;

	public function setParams ($params)
	{
		$this->params = $params;

		if (isset($params['predmety']))
		{
			$this->povolenePredmety = [];
			foreach ($params['predmety'] as $ppNdx)
			{
				$this->povolenePredmety[] = $ppNdx;
				$pDef = $this->app()->cfgItem ('e10pro.zus.predmety.'.$ppNdx, FALSE);
				if ($pDef !== FALSE && isset($pDef['podobne']))
				{
					$this->povolenePredmety[] = $ppNdx;
					foreach ($pDef['podobne'] as $ppNdx2)
						$this->povolenePredmety[] = $ppNdx2;
				}
			}
		}

		$this->studentNdx = $params['studentNdx'];
		$this->studiumNdx = $params['studiumNdx'];
		$this->academicYearId = $params['skolniRok'];
		$this->academicYear = $this->app->cfgItem ('e10pro.zus.roky.'.$this->academicYearId);
		$this->halfYearDate = utils::createDateTime($this->academicYear['V1']);

		$this->attHalfYearDate = utils::createDateTime($this->academicYear['V1']);
		if (isset($this->academicYear['KK1']))
			$this->attHalfYearDate = utils::createDateTime($this->academicYear['KK1']);
		$this->attEndYearDate = utils::createDateTime($this->academicYear['V2']);
		if (isset($this->academicYear['KK2']))
			$this->attEndYearDate = utils::createDateTime($this->academicYear['KK2']);
	}

	function load ()
	{
		$this->loadETKs();
		$this->loadHours();
	}

	function loadETKs ()
	{
		$q[] = 'SELECT vyuky.*, predmety.nazev as predmetNazev FROM [e10pro_zus_vyuky] AS vyuky';
		array_push ($q, ' LEFT JOIN [e10pro_zus_predmety] as predmety ON vyuky.svpPredmet = predmety.ndx');
		array_push ($q, ' WHERE vyuky.[skolniRok] = %s', $this->academicYearId);
		array_push ($q, ' AND vyuky.[stav] = %i', 4000);

		array_push ($q, ' AND (');
		array_push ($q, ' vyuky.[studium] = %i', $this->studiumNdx);
		array_push ($q, ' OR ');
		array_push ($q, 'EXISTS (',
			'SELECT vyuka FROM e10pro_zus_vyukystudenti AS vyukyStudenti LEFT JOIN [e10pro_zus_studium] AS vyukyStudia ON vyukyStudenti.studium = vyukyStudia.ndx',
			' WHERE vyukyStudenti.[studium] = %i', $this->studiumNdx,
			' AND vyukyStudenti.vyuka = vyuky.ndx)');
		array_push ($q, ')');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$predmetNdx = $r['svpPredmet'];
			$predmetNazev = $r['predmetNazev'];

			if ($this->povolenePredmety && !in_array($predmetNdx, $this->povolenePredmety))
				continue;

			$item = [
				'ndx' => $r['ndx'], 'typ' => $r['typ'],
				'svpPredmet' => $predmetNdx, 'predmetNazev' => $predmetNazev
			];
			$this->etk[$r['ndx']] = $item;
		}

		foreach ([2, 1] as $hyId)
		{
			$hy = ['predmety' => []];
			if ($this->povolenePredmety)
			{
				foreach ($this->povolenePredmety as $pndx)
					$hy['predmety'][$pndx] = [];
			}

			foreach ($this->etk as $etkNdx => $etkInfo)
			{
				$hy['predmety'][$etkInfo['svpPredmet']] = [
					'title' => $etkInfo['predmetNazev'],
					'att' => ['P' => 0, 'O' => 0, 'N' => 0],
					'grading' => ['cnt' => 0, 'sum' => 0]
				];
			}
			$this->info[$hyId] = $hy;
		}
	}

	function loadHours ()
	{
		$q[] = 'SELECT * FROM e10pro_zus_hodiny WHERE 1';
		array_push($q, ' AND vyuka IN %in', array_keys($this->etk));
		array_push($q, ' AND stav != %i', 9800);
		array_push($q, ' ORDER BY [datum], [zacatek]');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$hourLen = intval(round((utils::timeToMinutes ($r['konec']) - utils::timeToMinutes ($r['zacatek'])) / 45));
			$predmetNdx = $this->etk[$r['vyuka']]['svpPredmet'];
			$hyId = ($r['datum'] <= $this->attHalfYearDate) ? 1 : 2;
			if ($r['datum'] >= $this->attEndYearDate)
				$hyId = 3;

			$at = $this->attendanceType($r['pritomnost']);
			if ($this->etk[$r['vyuka']]['typ'] === 1)
			{ // individuální
				$this->info[$hyId]['predmety'][$predmetNdx]['att'][$at] += $hourLen;
			}
			else
			{ // kolektivní
				$ah = $this->db()->query('SELECT * FROM e10pro_zus_hodinydochazka',
					' WHERE [hodina] = %i', $r['ndx'], ' AND [studium] = %i', $this->studiumNdx)->fetch();
				if (!$ah)
					continue;
				$at = $this->attendanceType($ah['pritomnost']);
				$this->info[$hyId]['predmety'][$predmetNdx]['att'][$at] += $hourLen;

				if (intval($ah['klasifikaceZnamka']) != 0)
				{
					$this->info[$hyId]['predmety'][$predmetNdx]['grading']['sum'] += intval($ah['klasifikaceZnamka']);
					$this->info[$hyId]['predmety'][$predmetNdx]['grading']['cnt']++;
				}
			}

			if (intval($r['klasifikaceZnamka']) != 0)
			{
				$this->info[$hyId]['predmety'][$predmetNdx]['grading']['sum'] += intval($r['klasifikaceZnamka']);
				$this->info[$hyId]['predmety'][$predmetNdx]['grading']['cnt']++;
			}
		}
	}

	protected function attendanceType ($hourAttendance)
	{
		$ha = intval($hourAttendance);
		if ($ha === 0)
			return 'X';

		if ($ha === 2)
			return 'O';

		if ($ha === 3)
			return 'N';

		return 'P';
	}

	function createTableVertical()
	{
		$this->infoTable = [];

		foreach ($this->info as $hyId => $hy)
		{
			$row = [
				'predmet' => $hyId.'. pololetí',
				'P' => 'P', 'O' => 'O', 'N' => 'N', 'G' => 'průměr',
				'_options' => ['class' => 'subheader']
			];

			$this->infoTable[] = $row;

			$sum = [
				'predmet' => 'CELKEM',
				'P' => 0, 'O' => 0, 'N' => 0,
				'_options' => ['class' => 'subtotal']
			];

			foreach ($hy['predmety'] as $pNdx => $pInfo)
			{
				if (count ($pInfo) <= 1)
					continue;
				$row2 = [
					'predmet' => $pInfo['title'],
					'P' => $pInfo['att']['P'], 'O' => $pInfo['att']['O'], 'N' => $pInfo['att']['N']
				];
				if ($pInfo['grading']['cnt'])
					$row2['G'] = round ($pInfo['grading']['sum'] / $pInfo['grading']['cnt'], 1);
				$this->infoTable[] = $row2;

				$sum['P'] += $pInfo['att']['P'];
				$sum['O'] += $pInfo['att']['O'];
				$sum['N'] += $pInfo['att']['N'];
			}

			$this->infoTable[] = $sum;
		}

		$this->infoHeader = ['predmet' => 'Předmět', 'P' => ' P', 'O' => ' O', 'N' => ' N', 'G' => ' Průměr'];
	}

	function createTableHorizontal()
	{
		$this->infoTable = [];

		$sum = [
			'predmet' => 'CELKEM',
			'P1' => 0, 'O1' => 0, 'N1' => 0,
			'P2' => 0, 'O2' => 0, 'N2' => 0,
			'_options' => ['class' => 'subtotal']
		];

		foreach ($this->info as $hyId => $hy)
		{
			foreach ($hy['predmety'] as $pNdx => $pInfo)
			{
				if (count ($pInfo) <= 1)
					continue;
				if (!isset($this->infoTable[$pNdx]))
					$this->infoTable[$pNdx] = ['predmet' => $pInfo['title']];

				if ($pInfo['att']['P'])
					$this->infoTable[$pNdx]['P'.$hyId] = $pInfo['att']['P'];

				if ($pInfo['att']['O'])
					$this->infoTable[$pNdx]['O'.$hyId] = $pInfo['att']['O'];

				if ($pInfo['att']['N'])
					$this->infoTable[$pNdx]['N'.$hyId] = $pInfo['att']['N'];

				$sum['P'.$hyId] += $pInfo['att']['P'];
				$sum['O'.$hyId] += $pInfo['att']['O'];
				$sum['N'.$hyId] += $pInfo['att']['N'];

				if ($pInfo['grading']['cnt'])
					$this->infoTable[$pNdx]['G'.$hyId] = round ($pInfo['grading']['sum'] / $pInfo['grading']['cnt'], 2);
			}
		}

		if (count($this->infoTable) > 1)
			$this->infoTable['SUM'] = $sum;

		$this->infoHeader = [
			'predmet' => 'Předmět',
			'P1' => '|P1', 'O1' => '|O1', 'N1' => '|N1', 'G1' => '|Průměr1',
			'P2' => '|P2', 'O2' => '|O2', 'N2' => '|N2', 'G2' => '|Průměr2',
		];

		$this->infoHeaderHorizontal = [
			[
				'predmet' => 'Předmět', 'P1' => '1. pololetí', 'P2' => '2. pololetí',
				'_options' => [
					'colSpan' => ['P1' => 4, 'P2' => 4], 'rowSpan' => ['predmet' => 2],
					'cellClasses' => ['P1' => 'center', 'P2' => 'center']
				]
			],
			[
				'P1' => 'P', 'O1' => 'O', 'N1' => 'N', 'G1' => 'průměr',
				'P2' => 'P', 'O2' => 'O', 'N2' => 'N', 'G2' => 'průměr',
				'_options' => [
					'cellClasses' => ['P1' => 'center', 'P2' => 'center', 'O1' => 'center', 'O2' => 'center',
						'N1' => 'center', 'N2' => 'center', 'G1' => 'center', 'G2' => 'center']
				]
			],
		];
	}

	public function run ($vertical = FALSE)
	{
		$this->load();
		if ($vertical)
			$this->createTableVertical();
		else
			$this->createTableHorizontal();
	}
}


