<?php

namespace e10pro\zus\libs\ezk;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Utils\Utils, \Shipard\Utils\Str;
use \e10pro\zus\zusutils;


/**
 * Class WidgetGrades
 */
class WidgetGrades extends \Shipard\UI\Core\WidgetPane
{
	var $academicYear;
	var $halfYearDate;
	var $dataTimeTable = [];

	var $studentNdx = 0;
	var $userContext = NULL;

	var $grades = [];

	function loadData()
	{
		$q = [];

		array_push ($q, 'SELECT hodiny.*, ');
		array_push ($q, ' vyuky.nazev AS vyukaNazev, vyuky.typ AS vyukaTyp,');
		array_push ($q, ' vyuky.svpPredmet AS predmetNdx, predmety.nazev AS predmetNazev');
		array_push ($q, ' FROM [e10pro_zus_hodiny] AS [hodiny]');
		array_push ($q, ' LEFT JOIN [e10pro_zus_vyuky] AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN [e10pro_zus_predmety] AS predmety ON vyuky.svpPredmet = predmety.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND hodiny.stavHlavni = %i', 3);
		if (isset($this->userContext['vyuky']) && count($this->userContext['vyuky']))
			array_push ($q, ' AND vyuka IN %in', $this->userContext['vyuky']);
		else
			array_push ($q, ' AND vyuka = %i', -1);
		array_push($q, ' AND (');
    	array_push($q, '([vyuky].[typ] = %i', 1, ')');
			array_push($q, ' OR ');
			array_push ($q, '([vyuky].[typ] = %i', 0, ' AND EXISTS (',
											'SELECT hodina FROM e10pro_zus_hodinydochazka AS hodinyDochazka',
											' WHERE hodinyDochazka.student = %i', $this->studentNdx,
											' AND hodinyDochazka.hodina = hodiny.ndx',
											'))');
		array_push($q, ')');
		array_push ($q, ' ORDER BY [datum] DESC, [zacatek] DESC');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$predmetId = 'P'.$r['predmetNdx'];

			$item = $r->toArray();

			if ($item['vyukaTyp'] === 0)
			{
				$kd = $this->db()->query('SELECT * FROM e10pro_zus_hodinydochazka WHERE hodina = %i', $item['ndx'], ' AND student = %i', $this->studentNdx)->fetch();
				if ($kd)
				{
					$item['pritomnost'] = $kd['pritomnost'];
					$item['klasifikaceZnamka'] = $kd['klasifikaceZnamka'];
				}
			}

			if ($item['klasifikaceZnamka'] === '')
				continue;
			if ($item['pritomnost'] !== 1)
				continue;


			$halfYearId =	$item['datum'] < $this->halfYearDate ? 'H1' : 'H2';

			if (!isset($this->grades[$halfYearId][$predmetId]))
				$this->grades[$halfYearId][$predmetId] = ['hours' => [], 'sumGrades' => 0, 'cntGrades' => 0];

			$znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');
			$znamka = $znamkyHodnoceni[$item['klasifikaceZnamka']]['sc'];

			$this->grades[$halfYearId][$predmetId]['sumGrades'] += intval($item['klasifikaceZnamka']);
			$this->grades[$halfYearId][$predmetId]['cntGrades']++;

			$item = [
				'date' => $item['datum'],
				'grade' => $znamka,
				'note' => $item['klasifikacePoznamka'],
			];

			$this->grades[$halfYearId][$predmetId]['hours'][] = $item;
		}
	}

	function renderData()
	{
		$gradesData = [
			'halfYears' => [],
		];

		foreach ($this->grades as $halfYearId => $halfYearContent)
		{
			$halfYear = [
				'id' => $halfYearId,
				'number' => substr($halfYearId, 1),
				'subjects' => [],
			];

			foreach ($halfYearContent as $predmetId => $predmetContent)
			{
				$pDef = $this->app()->cfgItem ('e10pro.zus.predmety.'.substr($predmetId, 1), NULL);

				$subject = [
					'subjectId' => $halfYearId.'_'.$predmetId,
					'title' => $pDef['nazev'],
					'grades' => [],
				];

				if ($predmetContent['cntGrades'])
				{
					$subject['gradeAvg'] = strval(round($predmetContent['sumGrades'] / $predmetContent['cntGrades'], 2));
				}

				foreach ($predmetContent['hours'] as $hour)
				{
					$item = [
						'grade' => $hour['grade'],
						'note' => $hour['note'],
						'date' => Utils::datef($hour['date'], '%S'),
					];

					$subject['grades'][] = $item;
				}

				$halfYear['subjects'][] = $subject;

			}

			$gradesData['halfYears'][] = $halfYear;
		}

		$this->router->uiTemplate->data['grades'] = $gradesData;
		$templateStr = $this->router->uiTemplate->subTemplateStr('modules/e10pro/zus/libs/ezk/subtemplates/grades');
		$code = $this->router->uiTemplate->render($templateStr);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
	}

	public function createContent ()
	{
		$this->academicYear = zusutils::aktualniSkolniRok();

		$academicYear = $this->app->cfgItem ('e10pro.zus.roky.'.$this->academicYear);
		$this->halfYearDate = utils::createDateTime($academicYear['KK1'] ?? $academicYear['V1']);

		$userContexts = $this->app()->uiUserContext ();
		$ac = $userContexts['contexts'][$this->app()->uiUserContextId] ?? NULL;
		if ($ac)
			$this->studentNdx = $ac['studentNdx'] ?? 0;

		$this->userContext = $userContexts['ezk']['students'][$this->studentNdx];

		$this->loadData();
		$this->renderData();
	}

	public function title()
	{
		return FALSE;
	}
}
