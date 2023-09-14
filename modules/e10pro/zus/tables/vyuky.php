<?php

namespace E10Pro\Zus;

//require_once __APP_DIR__.'/e10-modules/e10doc/core/core.php';
require_once __SHPD_MODULES_DIR__.'e10pro/zus/zus.php';

use \E10\utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;
use \Shipard\Viewer\TableViewPanel, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Report\FormReport, E10Pro\Zus\zusutils;


/**
 * Class TableVyuky
 * @package E10Pro\Zus
 */
class TableVyuky extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.zus.vyuky', 'e10pro_zus_vyuky', 'Výuky', 1219);
	}

	public function tableIcon ($recData, $options = NULL)
	{
		static $icons = ['iconGroupClass', 'system/iconUser', 'iconRehearsalsClass'];
		if (isset($recData['typ']) && isset($icons[$recData['typ']]))
			return $icons[$recData['typ']];
		return parent::tableIcon ($recData, $options);
	}

	public function testValues (&$recData)
	{
		if ($recData ['typ'] == 1) // individuál
		{
			$nazev = '';
			if (isset($recData ['studium']) && $recData ['studium'] != 0)
			{
				$studium = $this->loadItem ($recData['studium'], 'e10pro_zus_studium');
				$recData ['student'] = $studium ['student'];
				$recData ['svp'] = $studium ['svp'];
				$recData ['svpObor'] = $studium ['svpObor'];
				$recData ['svpOddeleni'] = $studium ['svpOddeleni'];
				$recData ['rocnik'] = $studium ['rocnik'];
				if (isset($recData ['misto']) && $recData ['misto'] == 0)
					$recData ['misto'] = $studium ['misto'];
			}
			else
				$recData ['student'] = 0;
			if (isset($recData ['student']) && $recData ['student'] != 0)
			{
				$student = $this->loadItem ($recData['student'], 'e10_persons_persons');
				$nazev = $student ['fullName'];
			}
			$recData ['nazev'] = $nazev;
		}
		else
		{ // kolektivní
			$recData ['studium'] = 0;
			$recData ['rocnik'] = 0;

			$obor = $this->app()->cfgItem ('e10pro.zus.obory.'.$recData['svpObor'], []);
			if (isset($obor['typVyuky']) && $obor['typVyuky'] == 1)
				$recData ['svpOddeleni'] = 0;
		}
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		if ($recData['stav'] !== 4000 || $recData['typ'] != 0)
			return;

		if ($recData ['typ'] == 0) // kolektivní
		{
			$this->db()->query('DELETE FROM [e10pro_zus_vyukystudenti] WHERE [studium] = %i', 0, ' AND [vyuka] = %i', $recData['ndx']);
		}


		$this->checkHoursAttendance($recData);
	}

	public function checkBeforeSave (&$saveData, $ownerData = NULL)
	{
		parent::checkBeforeSave($saveData, $ownerData);
		$this->testValues($saveData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
		if (!isset ($recData ['ucitel']))
			$recData ['ucitel'] = $this->app()->user()->data ('id');
		if (!isset ($recData ['skolniRok']))
			$recData ['skolniRok'] = zusutils::aktualniSkolniRok();
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		/*
		if ($columnId == 'svpObor')
		{
			if (!$form)
				return TRUE;

			if (!isset($cfgItem ['svp']))
				return TRUE;
			if ($form->recData ['svp'] != $cfgItem ['svp'])
				return FALSE;

			return TRUE;
		}

		if ($columnId == 'svpOddeleni')
		{
			if (!$form)
				return TRUE;
			if (!isset($cfgItem ['svp']))
				return TRUE;
			if ($form->recData ['svp'] != $cfgItem ['svp'])
				return FALSE;
			if ($cfgItem ['obor'] == '')
				return TRUE;
			if ($form->recData ['svpObor'] != $cfgItem ['obor'])
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
		*/
		$r = zusutils::columnInfoEnumTest ($columnId, $cfgItem, $form);
		return ($r !== NULL) ? $r : parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);

	}

	public function createHeader ($recData, $options)
	{
		$hdr = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
		{
			$hdr ['icon'] = 'icon-bullhorn';

			$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
			$hdr ['info'][] = ['class' => 'title', 'value' => ' '];
			return $hdr;
		}

		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$tablePersons = $this->app()->table('e10.persons.persons');
		$tablePlaces = $this->app()->table('e10.base.places');

		$hdr ['icon'] = ($recData['typ'] === 0) ? 'icon-group' : 'icon-user';

		if ($recData['typ'] === 1)
		{ // individuální
			$student = $tablePersons->loadItem ($recData['student']);
			$hdr ['info'][] = [
				'class' => 'title',
				'value' => [
					['text' => $student['fullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $recData['student']],
					//['text' => strval($recData['cisloStudia']), 'class' => 'pull-right']
				]
			];

			$hdr ['info'][] = [
				'class' => 'info',
				'value' => [
					['text' => $this->app()->cfgItem ("e10pro.zus.predmety.{$recData['svpPredmet']}.nazev")]
				]
			];
		}
		else
			$hdr ['info'][] = ['class' => 'title', 'value' => ($recData['nazev'] !== '') ? $recData['nazev'] : ' '];

		$place = $tablePlaces->loadItem ($recData['misto']);
		$teacher = $tablePersons->loadItem ($recData['ucitel']);
		$hdr ['info'][] = [
			'class' => 'info',
			'value' => [
				['icon' => 'icon-map-marker', 'text' => ($place) ? $place['fullName'] : '-- nezadáno --'],
				['icon' => 'x-teacher', 'text' => $teacher['fullName']],
				['text' => $skolniRoky [$recData['skolniRok']]['nazev'], 'class' => 'pull-right', 'prefix' => ' ']
			]
		];

		return $hdr;
	}

	protected function checkHoursAttendance ($recData)
	{
		// -- students list
		$students = [];
		$studentsRows = $this->db()->query ('SELECT studenti.*, studia.student AS studentNdx, studia.datumNastupuDoSkoly AS zahajeniStudia, studia.datumUkonceniSkoly AS ukonceniStudia ',
			'FROM [e10pro_zus_vyukystudenti] AS studenti ',
			'LEFT JOIN [e10pro_zus_studium] AS studia ON studenti.studium = studia.ndx',
			' WHERE studenti.[vyuka] = %i', $recData['ndx']);
		foreach ($studentsRows as $s)
		{
			$students[] = [
				'student' => $s['studentNdx'], 'studium' => $s['studium'],
				'zahajeniStudia' => $s['zahajeniStudia'], 'ukonceniStudia' => $s['ukonceniStudia'],
				'platnostOd' => $s['platnostOd'], 'platnostDo' => $s['platnostDo']
			];
		}

		// check & insert
		$q[] = 'SELECT * FROM [e10pro_zus_hodiny] ';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [vyuka] = %i', $recData['ndx']);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			foreach ($students as $studentInfo)
			{
				$studentNdx = $studentInfo['student'];
				$studiumNdx = $studentInfo['studium'];
				$exist = $this->db()->query ('SELECT * FROM [e10pro_zus_hodinydochazka] WHERE [hodina] = %i', $r['ndx'],
					' AND [student] = %i', $studentNdx, ' AND [studium] = %i', $studiumNdx)->fetch();
				if ($exist)
					continue;

				if ($studentInfo['zahajeniStudia'] && $r['datum'] < $studentInfo['zahajeniStudia'])
					continue;
				if ($studentInfo['ukonceniStudia'] && $r['datum'] > $studentInfo['ukonceniStudia'])
					continue;

				if ($studentInfo['platnostOd'] && $r['datum'] < $studentInfo['platnostOd'])
					continue;
				if ($studentInfo['platnostDo'] && $r['datum'] > $studentInfo['platnostDo'])
					continue;

				$newItem = ['hodina' => $r['ndx'], 'student' => $studentNdx, 'studium' => $studiumNdx, 'pritomnost' => 1];
				$this->db()->query ('INSERT INTO [e10pro_zus_hodinydochazka] ', $newItem);
			}
		}
	}

	/*
	public function checkSaveData (&$saveData, &$saveResult)
	{
		if (!isset ($saveData['saveOptions']))
			return;

		$saveOptions = $saveData['saveOptions'];
		if (isset ($saveOptions['appendRowList']) && $saveOptions['appendRowList'] === 'rozvrh')
		{
			if (isset($saveOptions['appendBlankRow']))
			{
				$saveData ['lists']['rozvrh'][] = ['ucitel' => $saveData ['recData']['ucitel'], 'predmet' => $saveData ['recData']['svpPredmet']];
				return;
			}
		}

		parent::checkSaveData ($saveData, $saveResult);
	}
	*/
}

/**
 * Class ViewVyuky
 * @package E10Pro\Zus
 */

class ViewVyuky extends TableView
{
	var $today;

	public function init ()
	{
		$this->today = Utils::today();

		if ($this->app->hasRole('zusadm'))
		{
			$mq [] = ['id' => 'aktualni', 'title' => 'Aktuální', 'side' => 'left'];
			$mq [] = ['id' => 'moje', 'title' => 'Moje', 'side' => 'left'];
		}
		else
		{
			$mq [] = ['id' => 'aktualni', 'title' => 'Aktuální'];
		}

		$mq [] = ['id' => 'archiv', 'title' => 'Archív'];
		$mq [] = ['id' => 'vse', 'title' => 'Vše'];
		$mq [] = ['id' => 'kos', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		$this->setPanels(TableView::sptQuery);

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$qv = $this->queryValues();

		$q [] = 'SELECT vyuky.*, ucitele.fullName as jmenoUcitele, pobocky.fullName as pobocka,';
		array_push($q, ' studium.cisloStudia as cisloStudia, studium.rocnik as studiumRocnik,');
		array_push($q, ' studium.stavHlavni as studiumStavHlavni, studium.datumUkonceniSkoly as studiumDatumUkonceniSkoly,');
		array_push($q, ' obory.id as idOboru');
		array_push($q, ' FROM [e10pro_zus_vyuky] as vyuky ');
		array_push($q, ' LEFT JOIN e10_persons_persons AS ucitele ON vyuky.ucitel = ucitele.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS pobocky ON vyuky.misto = pobocky.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_studium AS studium ON vyuky.studium = studium.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_obory AS obory ON vyuky.svpObor = obory.ndx');
		array_push($q, ' WHERE 1');

		$this->defaultQuery($q);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' vyuky.nazev LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR EXISTS (SELECT e10pro_zus_vyukystudenti.vyuka FROM e10pro_zus_vyukystudenti',
											' LEFT JOIN e10pro_zus_studium AS colStudia ON e10pro_zus_vyukystudenti.studium = colStudia.ndx ',
											' WHERE e10pro_zus_vyukystudenti.vyuka = vyuky.ndx ',
											' AND colStudia.nazev LIKE %s', '%'.$fts.'%',
											')');
			array_push ($q, ')');
		}

		$this->qryMain($q);

		// Panel query...
		if (isset($qv['ucitel']['']) && $qv['ucitel'][''] != 0)
			array_push ($q, " AND vyuky.[ucitel] = %i", $qv['ucitel']['']);
		if (isset($qv['pobocka']['']) && $qv['pobocka'][''] != 0)
			array_push ($q, " AND vyuky.[misto] = %i", $qv['pobocka']['']);
		if (isset($qv['obor']['']) && $qv['obor'][''] != 0)
			array_push ($q, " AND vyuky.[svpObor] = %i", $qv['obor']['']);
		if (isset($qv['predmet']['']) && $qv['predmet'][''] != 0)
			array_push ($q, ' AND (vyuky.[svpPredmet] = %i', $qv['predmet'][''], ' OR vyuky.[svpPredmet2] = %i', $qv['predmet'][''], ')');
		if (isset($qv['typVyuky']['']) && $qv['typVyuky'][''] != 99)
			array_push ($q, " AND vyuky.[typ] = %i", $qv['typVyuky']['']);
		if (isset($qv['skolniRok']['']) && $qv['skolniRok'][''] != '0')
			array_push ($q, " AND vyuky.[skolniRok] = %i", $qv['skolniRok']['']);

		// errors
		$withoutTimetable = isset ($qv['errors']['withoutTimetable']);
		if ($withoutTimetable)
			array_push ($q, 'AND (SELECT COUNT(*) FROM e10pro_zus_vyukyrozvrh as vr WHERE vr.vyuka = vyuky.ndx) = 0');

		$withoutStudents = isset ($qv['errors']['withoutStudents']);
		if ($withoutStudents)
			array_push ($q, 'AND (vyuky.typ = 0 AND (SELECT COUNT(*) FROM e10pro_zus_vyukystudenti as vs WHERE vs.vyuka = vyuky.ndx) = 0)');

		$withoutHours = isset ($qv['errors']['withoutHours']);
		if ($withoutHours)
		{
			$dateLimit = new \DateTime();
			$dateLimit->sub (new \DateInterval('P14D'));

			array_push($q, 'AND ((SELECT COUNT(*) FROM e10pro_zus_hodiny AS hodiny WHERE hodiny.vyuka = vyuky.ndx AND hodiny.datum > %d', $dateLimit, ') = 0)');
		}

		$withoutPlan = isset ($qv['errors']['withoutPlan']);
		if ($withoutPlan)
			array_push ($q, 'AND (vyuky.studijniPlan IS NULL OR LENGTH(vyuky.studijniPlan) < %i', 30, ')');

		$nonEnded = isset ($qv['errors']['nonEnded']);
		if ($nonEnded)
		{
			array_push ($q, 'AND (vyuky.datumUkonceni IS NULL AND studium.datumUkonceniSkoly IS NOT NULL',
														' AND vyuky.[typ] = %i', 1,
											')');
		}

		array_push ($q, ' ORDER BY vyuky.[stavHlavni], vyuky.nazev, vyuky.[skolniRok] DESC' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows

	public function qryMain (&$q)
	{
		$mainQuery = $this->mainQueryId ();

		// -- aktuální
		if ($mainQuery === 'aktualni' || $mainQuery === '')
			array_push ($q, " AND vyuky.[stavHlavni] < %i", 4, " AND vyuky.[skolniRok] = %i", \E10Pro\Zus\aktualniSkolniRok ());

		// -- archív
		if ($mainQuery === 'archiv')
			array_push ($q, " AND (vyuky.[stavHlavni] = %i", 5, " OR vyuky.[skolniRok] < %i)", \E10Pro\Zus\aktualniSkolniRok ());

		// -- moje
		if ($mainQuery === 'moje')
			array_push ($q, ' AND (vyuky.[skolniRok] < %i)', \E10Pro\Zus\aktualniSkolniRok ());

		// koš
		if ($mainQuery === 'kos')
			array_push ($q, " AND vyuky.[stavHlavni] = %i", 4);

		//if ($this->queryParam ('student'))
		//	array_push ($q, " AND vyuky.[student] = %i", intval($this->queryParam ('student')));

		// -- jen sekretariát a admin vidí cizí studium
		if (((!$this->app->hasRole ('scrtr')) && (!$this->app->hasRole ('zusadm'))) || $mainQuery === 'moje')
			array_push ($q, " AND vyuky.[ucitel] = %i", $this->app->userNdx());
	}

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);

		$listItem ['t1'] = $item['nazev'];
		$listItem ['t2'] = [];

		if ($item['typ'] == 1)
		{ // individual
			$listItem ['i1'] = strval ($item['cisloStudia']);
		}

		if ($item['studiumRocnik'])
			$listItem ['i2'] = $rocniky [$item['studiumRocnik']]['nazev']; //['icon' => 'x-teacher', 'text' => $item['jmenoUcitele']];

		//$listItem ['t2'] = $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item['svpOddeleni']}.nazev");
		$listItem ['t2'][] = ['text' => $item['idOboru'].' / '.$this->app()->cfgItem ("e10pro.zus.predmety.{$item['svpPredmet']}.nazev"), 'class' => ''];

		if (!utils::dateIsBlank($item['datumZahajeni']))
			$listItem ['t2'][] = ['text' => 'Zahájeno '.utils::datef($item['datumZahajeni']), 'class' => 'label label-info'];
		if (!utils::dateIsBlank($item['datumUkonceni']))
			$listItem ['t2'][] = ['text' => 'Ukončeno '.utils::datef($item['datumUkonceni']), 'class' => 'label label-info'];

		if ($item ['jmenoUcitele'])
			$listItem ['t3'][] = ['icon' => 'x-teacher', 'text' => $item ['jmenoUcitele']];
		if ($item ['pobocka'])
			$listItem ['t3'][] = ['icon' => 'icon-map-marker', 'text' => $item ['pobocka']];
		$listItem ['t3'][] = ['text' => $skolniRoky [$item['skolniRok']]['nazev'], 'class' => 'pull-right'];

		return $listItem;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = array();

		$paramsRows = new \e10doc\core\libs\GlobalParams ($panel->table->app());

		if ($this->app->hasRole('zusadm'))
		{
			$paramsRows->addParam('switch', 'query.ucitel', ['title' => 'Učitel', 'switch' => zusutils::ucitele($this->app)]);
			$paramsRows->addParam('switch', 'query.pobocka', ['title' => 'Pobočka', 'switch' => zusutils::pobocky($this->app)]);
			$paramsRows->addParam('switch', 'query.obor', ['title' => 'Obor', 'place' => 'panel', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
				'enableAll' => ['0' => ['title' => 'Vše']]]);
			$paramsRows->addParam('switch', 'query.predmet', ['title' => 'Předmět', 'place' => 'panel', 'cfg' => 'e10pro.zus.predmety', 'titleKey' => 'nazev',
				'enableAll' => ['0' => ['title' => 'Vše']]]);
			$paramsRows->addParam('switch', 'query.typVyuky', ['title' => 'Typ výuky', 'place' => 'panel',
				'switch' => [
					'99' => 'Vše',
					'0' => 'Kolektivní',
					'1' => 'Individuální'
				]]);
		}

		$paramsRows->addParam('switch', 'query.skolniRok', ['title' => 'Školní rok', 'switch' => zusutils::skolniRoky($this->app)]);

		$paramsRows->detectValues();
		$qry[] = ['id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat', 'params' => $paramsRows, 'class' => 'switches'];

		// -- errors
		if ($this->app->hasRole('zusadm'))
		{
			$chbxErrors = [
				'withoutTimetable' => ['title' => 'Bez rozvrhu', 'id' => 'withoutTimetable'],
				'withoutStudents' => ['title' => 'Skupinové výuky bez studentů', 'id' => 'withoutStudents'],
				'withoutHours' => ['title' => 'Chybí zápisy hodin', 'id' => 'withoutHours'],
				'withoutPlan' => ['title' => 'Není studijní plán', 'id' => 'withoutPlan'],
				'nonEnded' => ['title' => 'Neukončené ETK s ukončeným studiem', 'id' => 'nonEnded'],
			];
			$paramsErrors = new \E10\Params ($this->app());
			$paramsErrors->addParam('checkboxes', 'query.errors', ['items' => $chbxErrors]);
			$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Problémy', 'params' => $paramsErrors];
		}

		$panel->addContent(array ('type' => 'query', 'query' => $qry));
	}
}


/**
 * Class ViewVyukyETK
 * @package E10Pro\Zus
 */
class ViewVyukyETK extends ViewVyuky
{
}


/**
 * Class ViewVyukyCombo
 * @package E10Pro\Zus
 */
class ViewVyukyCombo extends ViewVyuky
{
	var $ucebniPlanPredpis;
	var $plan = [];

	public function defaultQuery (&$q)
	{
		if ($this->queryParam('ucitel'))
		{
			array_push ($q, ' AND vyuky.[ucitel] = %i ', $this->queryParam('ucitel'));
		}
	}

	public function renderRow ($item)
	{
		$teachPlanId = $item['svpPredmet'].'-'.$item['rocnik'].'-'.$item['svp'].'-'.$item['svpObor'].'-'.$item['svpOddeleni'];
		$listItem ['teachPlanId'] = $teachPlanId;

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = ($item['typ'] === 0) ? 'icon-group' : 'icon-user';

		$listItem ['t1'] = $item['nazev'];

		if ($item['pobocka'])
			$listItem ['i2'] = ['icon' => 'icon-map-marker', 'text' => $item['pobocka']];

		$listItem ['t2'] = $this->app()->cfgItem ("e10pro.zus.predmety.{$item['svpPredmet']}.nazev");

		return $listItem;
	}

	function decorateRow (&$item)
	{
		$item ['i1'] = '';

		$ndx = $item['pk'];
		if (isset ($this->plan[$ndx]))
		{
			$item ['i1'] = strval($this->plan[$ndx]['naplanovano']);
		}
		else
			$item ['i1'] = '0';

		$tpid = $item['teachPlanId'];
		if (isset ($this->ucebniPlanPredpis[$tpid]))
		{
			$item ['i1'] .= ' / '.strval($this->ucebniPlanPredpis[$tpid]);
		}
	}

	public function selectRows2 ()
	{
		$this->ucebniPlanPredpis = zusutils::ucebniPlan($this->app());
		if (!count ($this->pks))
			return;

		parent::selectRows2();

		$q[] = 'SELECT rozvrh.vyuka as vyuka, SUM(rozvrh.delka) AS naplanovano';
		$q[] = ' FROM [e10pro_zus_vyukyrozvrh] AS rozvrh';
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push($q, ' WHERE rozvrh.[stavHlavni] < %i', 4);
		array_push($q, ' AND vyuky.[skolniRok] = %i', zusutils::aktualniSkolniRok());
		array_push($q, ' GROUP BY 1');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
			$this->plan[$r['vyuka']] = ['naplanovano' => $r['naplanovano']];
	}

}


/**
 * Class ViewDetailVyuka
 * @package E10Pro\Zus
 */
class ViewDetailVyuka extends TableViewDetail
{

	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.zus.DocumentCardETK');
	}
}


/**
 * Class FormVyuka
 * @package E10Pro\Zus
 */
class FormVyuka extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);


		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		if ($this->recData['typ'] === 0)
			$tabs ['tabs'][] = ['text' => 'Studenti', 'icon' => 'iconStudents'];
		$tabs ['tabs'][] = ['text' => 'Rozvrh', 'icon' => 'e10.widgetDashboard/timeTable'];
		$tabs ['tabs'][] = ['text' => 'Studijní plán', 'icon' => 'reportStudyPlan'];
		$tabs ['tabs'][] = ['text' => 'Účinkování', 'icon' => 'formPerforming'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
		$this->openTabs ($tabs, TRUE);

		$this->openTab (TableForm::ltForm);
			$this->addColumnInput ('typ');

			if ($this->recData['typ'] === 0)
			{ // kolektivní
				$this->addColumnInput ('svpObor');
				$this->addColumnInput ('svpOddeleni');
				$this->addColumnInput ('nazev');
				$this->addColumnInput ('svpPredmet');
				$this->addColumnInput ('svpPredmet2');
				$this->addColumnInput ('svpPredmet3');
				$this->addColumnInput ('ucitel');
				$this->addColumnInput ('ucitel2');
				$this->addColumnInput ('misto');
			}
			elseif ($this->recData['typ'] === 1)
			{ // individuální
				$this->addColumnInput ('studium');

				$this->addColumnInput ('svpPredmet');
				$this->addColumnInput ('ucitel');
				$this->addColumnInput ('misto');

				$this->addColumnInput ('studentSkola');
				$this->addColumnInput ('studentZP');
			}
			elseif ($this->recData['typ'] === 2)
			{ // korepetice
				$this->addColumnInput ('nazev');
				$this->addColumnInput ('svpPredmet');
				$this->addColumnInput ('ucitel');
				$this->addColumnInput ('misto');
			}
		$this->closeTab ();

		if ($this->recData['typ'] === 0)
		{
			$this->openTab (TableForm::ltNone);
				$this->addList ('studenti');
			$this->closeTab ();
		}

		$this->openTab (TableForm::ltNone);
			$this->addList ('rozvrh');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addInputMemo ('studijniPlan', NULL, TableForm::coFullSizeY);
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addInputMemo ('ucinkovani', NULL, TableForm::coFullSizeY);
		$this->closeTab ();

		$this->openTab ();
			$this->addColumnInput ('datumZahajeni');
			$this->addColumnInput ('datumUkonceni');
		$this->closeTab ();

		$this->openTab (TableForm::ltNone);
			$this->addAttachmentsViewer ();
		$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10pro.zus.vyuky')
		{
			if ($srcColumnId === 'svpPredmet' || $srcColumnId === 'svpPredmet2' || $srcColumnId === 'svpPredmet3')
			{
				$cp = [
					'typVyuky' => strval($allRecData ['recData']['typ']),
					'obor' => strval($allRecData ['recData']['svpObor'])
				];
				return $cp;
			}
		}
		if ($srcTableId === 'e10pro.zus.vyukystudenti')
		{
			if ($srcColumnId === 'studium')
			{
				$cp = ['obor' => $allRecData ['recData']['svpObor']];
			}
			return $cp;
		}
		if ($srcTableId === 'e10pro.zus.vyukyrozvrh')
		{
			$cp = [];
			if ($srcColumnId === 'ucebna')
				$cp = ['pobocka' => $recData ['pobocka']];
			if (count($cp))
				return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

	function columnLabel ($colDef, $options)
	{
		switch ($colDef ['sql'])
		{
			case 'svpOddeleni': return $this->app()->cfgItem ("e10pro.zus.svp.{$this->recData ['svp']}.pojmenovani");
		}

		return parent::columnLabel ($colDef, $options);
	}
}


/**
 * Class ReportETK
 * @package E10Pro\Zus
 */
class ReportETK extends \e10doc\core\libs\reports\DocReportBase
{
	function init ()
	{
		$this->reportId = 'e10pro.zus.etk';
		$this->reportTemplate = 'reports.modern.e10pro.zus.etk';
	}

	public function loadData ()
	{
    parent::loadData();
		$this->loadData_DocumentOwner ();

		$texy = new \E10\Web\E10Texy ($this->app());

		$skolniRok = $this->app->cfgItem ('e10pro.zus.roky.'.$this->recData ['skolniRok']);

		$this->data ['individual'] = intval($this->recData ['typ']);
		$this->data ['skolniRok'] = $skolniRok['nazev'];
		$this->data ['svpOddeleni'] = $this->app()->cfgItem ("e10pro.zus.svp.{$this->recData ['svp']}.pojmenovani");
		$this->data ['studijniPlan'] = $texy->process($this->recData['studijniPlan']);

		// učitel
		$q = "SELECT * FROM [e10_persons_persons] WHERE [ndx] = %i";
		$this->data ['ucitel'] = $this->table->db()->query($q, $this->recData ['ucitel'])->fetch ();

		// studium
		if ($this->recData ['studium'])
		{
			$q = "SELECT * FROM [e10pro_zus_studium] WHERE [ndx] = %i";
			$this->data ['studium'] = $this->table->db()->query($q, $this->recData ['studium'])->fetch();
		}

		// student
		$tablePersons = $this->app->table ('e10.persons.persons');
		$this->data ['student'] = $this->table->loadItem ($this->recData ['student'], 'e10_persons_persons');
		$this->data ['student']['lists'] = $tablePersons->loadLists ($this->data ['student']);

		$bdate = \E10\base\searchArrayItem ($this->data ['student']['lists']['properties'], 'property', 'birthdate');
		if ($bdate)
			$this->data ['birthDate'] = $bdate ['value']->format ('j') . '.&nbsp;' . utils::$monthNamesForDate [$bdate ['value']->format ('n') - 1]  . '&nbsp;' . $bdate ['value']->format ('Y');

		$rodneCislo = \E10\base\searchArrayItem ($this->data ['student']['lists']['properties'], 'property', 'pid');
		if ($rodneCislo)
			$this->data ['rodneCislo'] = $rodneCislo ['value'];

		$telefon = \E10\base\searchArrayItem ($this->data ['student']['lists']['properties'], 'property', 'phone');
		if ($telefon)
			$this->data ['telefon'] = $telefon ['value'];

		$zz1_jmeno = \E10\base\searchArrayItem ($this->data ['student']['lists']['properties'], 'property', 'e10-zus-zz-jmeno');
		if ($zz1_jmeno)
			$this->data ['zz1_jmeno'] = $zz1_jmeno ['value'];

		$zz1_telefon = \E10\base\searchArrayItem ($this->data ['student']['lists']['properties'], 'property', 'e10-zus-zz-telefon');
		if ($zz1_telefon)
			$this->data ['zz1_telefon'] = $zz1_telefon ['value'];

		// -- předměty
		$this->data ['predmet'] = $this->table->loadItem ($this->recData ['svpPredmet'], 'e10pro_zus_predmety');
		if ($this->recData ['svpPredmet2'])
			$this->data ['predmet2'] = $this->table->loadItem ($this->recData ['svpPredmet2'], 'e10pro_zus_predmety');

		// -- nacist seznam hodin
		$fullAttendanceShortcuts = [
			0 => "--", 1 =>  "P", 2 => "NO", 3 => "NN", 4 => "SS", 5 => "PR", 6 => "ŘV", 7 => "V"
		];

		$dochazkaPritomnost = $this->app->cfgItem ('zus.pritomnost');
		$znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');
		$this->data ['hodiny'] = [];
		$q = [];
		$q[] = 'SELECT * FROM e10pro_zus_hodiny WHERE 1';
		array_push($q, ' AND vyuka = %i', $this->recData['ndx']);
		array_push($q, ' AND stav != %i', 9800);
		array_push($q, ' ORDER BY [datum], [zacatek]');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
					'datum' => ($r['datum']) ? $r['datum']->format ('j.n') : '!!!',
					'probiranaLatka' => $r['probiranaLatka'],
					'pritomnost' => /*$dochazkaPritomnost[$r['pritomnost']]['sc']*/$fullAttendanceShortcuts[$r['pritomnost']],
					'znamka' => $znamkyHodnoceni[$r['klasifikaceZnamka']]['sc'],
			];
			$this->data ['hodiny'][] = $item;
		}

		// -- docházka kolektivní
		if ($this->recData ['typ'] === 0)
		{
			$this->paperOrientation = 'landscape';

			$hoursPlanGenerator = new \e10pro\zus\HoursPlanGenerator($this->app());
			$hoursPlanGenerator->setParams(['etkNdx' => $this->recData['ndx'], 'etkRecData' => $this->recData]);
			$hoursPlanGenerator->run();

			$this->data['dochazka'][] = $hoursPlanGenerator->collectiveAttendanceTable (1);
			$this->data['dochazka'][] = $hoursPlanGenerator->collectiveAttendanceTable (2);

			$this->data['rocniky'] = \e10\sortByOneKey($hoursPlanGenerator->collectiveYears, 'poradi');
			$rocniky = [];
			foreach ($this->data['rocniky'] as $rocnik)
				$rocniky[] = $rocnik['zkratka'];
			$this->data['rocnikyText'] = implode (', ', $rocniky);
		}
	}
}
