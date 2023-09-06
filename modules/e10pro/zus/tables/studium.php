<?php

namespace E10Pro\Zus;

require_once __SHPD_MODULES_DIR__.'e10pro/zus/zus.php';
//require_once __SHPD_MODULES_DIR__.'e10doc/core/core.php';

use \E10\Application, \E10\utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;


/**
 * Class TableStudium
 * @package E10Pro\Zus
 */
class TableStudium extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.studium", "e10pro_zus_studium", "Studium studenta", 1217);
	}

  public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['skolniRok']))
			$recData['skolniRok'] = \E10Pro\Zus\aktualniSkolniRok ();

		if (!isset($recData['cisloStudia']) || $recData['cisloStudia'] == 0)
		{
			$max = $this->db()->query ('SELECT MAX(cisloStudia) as cisloStudia FROM e10pro_zus_studium')->fetch();
			if (isset ($max['cisloStudia']))
				$recData['cisloStudia'] = $max['cisloStudia'] + 1;
			else
				$recData['cisloStudia'] = 1;
		}
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (!isset($recData['cisloStudia']) || $recData['cisloStudia'] == 0)
		{
			$max = $this->db()->query ('SELECT MAX(cisloStudia) as cisloStudia FROM e10pro_zus_studium')->fetch();
			if (isset ($max['cisloStudia']))
				$recData['cisloStudia'] = $max['cisloStudia'] + 1;
			else
				$recData['cisloStudia'] = 1;
		}

		$obor = $this->app()->cfgItem ('e10pro.zus.obory.'.$recData['svpObor'], FALSE);
		if ($obor)
		{
			if ($recData ['skolnePrvniPol'] == 0.0)
				$recData ['skolnePrvniPol'] = $obor['skolne1p'];
			if ($recData ['skolneDruhePol'] == 0.0)
				$recData ['skolneDruhePol'] = $obor['skolne1p'];
		}

    $recData ['skolVyPrvniPol'] = $recData ['skolnePrvniPol'] - $recData ['skolSlPrvniPol'];
    $recData ['skolVyDruhePol'] = $recData ['skolneDruhePol'] - $recData ['skolSlDruhePol'];

    $nazev = '';
    if ($recData['student'] != 0)
		{
			$student = $this->loadItem ($recData ['student'], 'e10_persons_persons');
			if ($student)
			{
				$nazev = $student ['fullName'];
				$nazev .= ' ('.$recData['cisloStudia'].')';
				$nazev .= ' / '.$this->app()->cfgItem ("e10pro.zus.oddeleni.{$recData ['svpOddeleni']}.nazev");
				$nazev .= ' / '.$this->app()->cfgItem ("e10pro.zus.roky.{$recData ['skolniRok']}.nazev");
			}
		}
		$recData ['nazev'] = $nazev;

		$rocnik = $this->app()->cfgItem ('e10pro.zus.rocniky.'.$recData['rocnik'], FALSE);
		if ($rocnik !== FALSE)
			$recData['stupen'] = $rocnik['stupen'];
	}

  public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		$r = zusutils::columnInfoEnumTest ($columnId, $cfgItem, $form);
		return ($r !== NULL) ? $r : parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

  public function createHeader ($recData, $options)
	{
		$hdr = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
		{
			$hdr ['icon'] = 'personBirthDate';

			$hdr ['info'][] = ['class' => 'info', 'value' => ' '];
			$hdr ['info'][] = ['class' => 'title', 'value' => ' '];
			return $hdr;
		}

		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$stupne = $this->app()->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');
		$tablePersons = $this->app()->table('e10.persons.persons');
		$tablePlaces = $this->app()->table('e10.base.places');


		$student = $tablePersons->loadItem ($recData['student']);
		$hdr ['icon'] = $tablePersons->tableIcon ($student);
		$hdr ['info'][] = [
			'class' => 'title',
			'value' => [
				['text' => $student['fullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $recData['student']],
				['text' => strval($recData['cisloStudia']), 'class' => 'pull-right']
			]
		];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => [
				['text' => $this->app()->cfgItem ("e10pro.zus.oddeleni.{$recData ['svpOddeleni']}.nazev")],
				['text' => $rocniky [$recData['rocnik']]['nazev'] ?? '---', 'class' => 'pull-right']
			]
		];

		$leftInfo = [];

		$place = $tablePlaces->loadItem ($recData['misto']);
		if ($place)
			$leftInfo[] = ['icon' => 'system/iconMapMarker', 'text' => $place['fullName']];
		$teacher = $tablePersons->loadItem ($recData['ucitel']);
		if ($teacher)
			$leftInfo [] = ['icon' => 'iconTeachers', 'text' => $teacher['fullName']];

		$leftInfo [] = ['text' => $skolniRoky [$recData['skolniRok']]['nazev'], 'class' => 'pull-right', 'prefix' => ' '];


		$periodInfo = '';
		if (!utils::dateIsBlank($recData['datumNastupuDoSkoly']))
			$periodInfo = utils::datef($recData['datumNastupuDoSkoly']).' → ';
		if (!utils::dateIsBlank($recData['datumUkonceniSkoly']))
		{
			if ($periodInfo === '')
				$periodInfo .= ' → ';
			$periodInfo .= utils::datef($recData['datumUkonceniSkoly']);
		}
		if ($periodInfo !== '')
			$leftInfo [] = ['text' => $periodInfo, 'class' => 'pull-right label label-info', 'prefix' => ' ', 'icon' => 'system/iconCalendar'];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => $leftInfo
		];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return parent::tableIcon($recData, $options);
	}
}


/**
 * Základní pohled na Studium
 *
 */

class ViewStudium extends TableView
{
	public function init ()
	{
		parent::init();

		$mq [] = array ('id' => 'aktualni', 'title' => 'Aktuální');
		$mq [] = array ('id' => 'archiv', 'title' => 'Archív');
		$mq [] = array ('id' => 'vse', 'title' => 'Vše');
		$mq [] = array ('id' => 'kos', 'title' => 'Koš');

		$this->setMainQueries ($mq);

		$panels = array ();
		$panels [] = array ('id' => 'qry', 'title' => 'Hledání');
		if (isset ($this->viewerDefinition['panels']))
			$panels = array_merge($panels, $this->viewerDefinition['panels']);

		if ($this->app->hasRole('zusadm') || $this->app->hasRole('scrtr'))
			$this->setPanels($panels);
	} // init

	public function selectRows ()
	{
		$q = $this->selectRowsCmd (0);
		$this->runQuery ($q);

		if (count ($this->queryRows) !== 0)
		  return;

		$q = $this->selectRowsCmd (1);
		$this->runQuery ($q);
	}

	public function selectRowsCmd ($selectLevel)
	{
		$academicYear = \E10Pro\Zus\aktualniSkolniRok ();
		$academicYearCfg = $this->app->cfgItem ('e10pro.zus.roky.'.$academicYear);
		$today = utils::createDateTime($academicYearCfg['zacatek']);
		$todayYear = intval($today->format ('Y'));
		$beginDateStr = sprintf ("%04d-09-01", $todayYear);
		$endDateStr = sprintf ("%04d-06-30", $todayYear+1);


		$this->checkFastSearch ();

		$dotaz = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$qv = $this->queryValues();

		$q [] = 'SELECT studium.*, ucitel.fullName as ucitelFullName, student.fullName as studentFullName, student.lastName as studentLastName, student.company as studentCompany, student.gender as studentGender, places.fullName as placeName';
		$q [] = ' FROM [e10pro_zus_studium] as studium ';
		$q [] = ' LEFT JOIN e10_persons_persons AS ucitel ON studium.ucitel = ucitel.ndx ';
		$q [] = ' LEFT JOIN e10_persons_persons AS student ON studium.student = student.ndx ';
		$q [] = ' LEFT JOIN e10_base_places AS places ON studium.misto = places.ndx ';
		$q [] = ' WHERE 1';

		$this->defaultQuery($q);

		// -- fulltext
		if ($dotaz != '')
		{
			array_push ($q, " AND (");

			$numItems = count($this->fastSearch);

			$i = 0;
			foreach($this->fastSearch as $searchValue)
			{
					if ($selectLevel === 0)
					{
							array_push ($q, '(student.[lastName] LIKE %s', $searchValue.'%');
							array_push ($q, ' OR student.[firstName] LIKE %s', $searchValue.'%');
							array_push ($q, ')');
							//array_push ($q, ' OR (student.[lastName] LIKE %s', '%'.$searchValue.'%', ' AND [company] = 1)', ')');
					}
					else
							if ($selectLevel === 1)
									array_push ($q, '(student.[lastName] LIKE %s', '%'.$searchValue.'%', ' OR student.[firstName] LIKE %s', '%'.$searchValue.'%', ')');
					if(++$i !== $numItems)
							array_push ($q, ' AND ');
			}

			//array_push ($q, " student.[fullName] LIKE %s", '%'.$dotaz.'%');
			//array_push ($q, " OR ucitel.[lastName] LIKE %s", $dotaz.'%');

			if (strval(intval($dotaz)) == $dotaz)
				array_push ($q, "OR [cisloStudia] = %i", $dotaz);

			array_push ($q, ")");
		}

		// -- jen admin vidí cizí studia
		//if (!$this->table->app()->hasRole ('admin'))
		//	array_push ($q, " AND [ucitel] = %i", intval($this->table->app()->user()->data ('id')));

		// -- aktuální
		if ($mainQuery == 'aktualni' || $mainQuery == '')
			array_push ($q, " AND [stavHlavni] < %i AND [skolniRok] = %i", 4, \E10Pro\Zus\aktualniSkolniRok ());

		// -- archív
		if ($mainQuery == 'archiv')
			array_push ($q, " AND ([stavHlavni] = %i OR [skolniRok] < %i)", 5, \E10Pro\Zus\aktualniSkolniRok ());

		// koš
		if ($mainQuery == 'kos')
			array_push ($q, " AND [stavHlavni] = %i", 4);
		//else
		//	array_push ($q, " AND [stavHlavni] <> %i", 4);

		if ($this->queryParam ('student'))
			array_push ($q, " AND [student] = %i", intval($this->queryParam ('student')));

		// Panel query...
		if (isset($qv['ucitel']['']) && $qv['ucitel'][''] != 0)
		{
			/*
			array_push($q, ' AND (',
					'studium.[ucitel] = %i', $qv['ucitel'][''],
					' OR ',
					' EXISTS (',
					' SELECT ndx FROM e10pro_zus_studiumpre WHERE studium.ndx = e10pro_zus_studiumpre.studium ',
						' AND e10pro_zus_studiumpre.ucitel = %i', $qv['ucitel'][''], ')',
					')'
			);*/
			array_push($q, ' AND ', 'studium.[ucitel] = %i', $qv['ucitel']['']);
		}
		if (isset($qv['pobocka']['']) && $qv['pobocka'][''] != 0)
			array_push ($q, " AND studium.[misto] = %i", $qv['pobocka']['']);
		if (isset($qv['typVysvedceni']['']) && $qv['typVysvedceni'][''] != 99)
			array_push ($q, " AND studium.[typVysvedceni] = %i", $qv['typVysvedceni']['']);
		if (isset($qv['obor']['']) && $qv['obor'][''] != 0)
			array_push ($q, " AND studium.[svpObor] = %i", $qv['obor']['']);
		if (isset($qv['predmet']['']) && $qv['predmet'][''] != 0)
			array_push ($q, " AND EXISTS (SELECT * FROM [e10pro_zus_studiumpre] as studiumpre WHERE (studiumpre.[studium] = studium.[ndx] AND studiumpre.[svpPredmet] = %i))", $qv ['predmet']['']);
		if (isset($qv['rocnik']['']) && $qv['rocnik'][''] != 0)
			array_push ($q, " AND studium.[rocnik] = %i", $qv['rocnik']['']);

		if (isset($qv['skolniRok']['']) && $qv['skolniRok'][''] != '0')
			array_push ($q, " AND studium.[skolniRok] = %i", $qv['skolniRok']['']);

		// -- errors
		if (isset ($qv['errors']['withoutSubjects']))
			array_push ($q, 'AND (SELECT COUNT(*) FROM e10pro_zus_studiumpre as sp WHERE sp.studium = studium.ndx) = 0');

		if (isset ($qv['errors']['notFullYear']))
		{
			array_push($q, 'AND (');
			array_push ($q, '(datumUkonceniSkoly IS NOT NULL AND datumUkonceniSkoly < %d)', $endDateStr);
			array_push($q, ' OR ');
			array_push ($q, '(datumNastupuDoSkoly IS NOT NULL AND datumNastupuDoSkoly > %d)', $beginDateStr);
			array_push($q, ')');
		}

		if (isset ($qv['errors']['lateBegin']))
		{
			array_push($q, 'AND (');
			array_push ($q, '(datumNastupuDoSkoly IS NOT NULL AND datumNastupuDoSkoly > %d)', $beginDateStr);
			array_push($q, ')');
		}

		if (isset ($qv['errors']['prematureQuit']))
		{
			array_push($q, 'AND (');
			array_push ($q, '(datumUkonceniSkoly IS NOT NULL AND datumUkonceniSkoly < %d)', $endDateStr);
			array_push($q, ')');
		}

		if (isset ($qv['errors']['withoutBegin']))
		{
			array_push($q, 'AND (');
			array_push ($q, 'datumNastupuDoSkoly IS NULL');
			array_push($q, ')');
		}

		if (isset ($qv['errors']['withSale']))
			array_push ($q, ' AND (skolSlPrvniPol != %i', 0, ' OR skolSlDruhePol != %i', 0, ')');

		array_push ($q, ' ORDER BY [stavHlavni], student.fullName, [cisloStudia], [skolniRok] DESC' . $this->sqlLimit ());

		return $q;
	} // selectRowsCmd

	public function defaultQuery (&$q)
	{
		// -- jen sekretariát a admin vidí cizí studium
		if ((!$this->table->app()->hasRole ('scrtr')) && (!$this->table->app()->hasRole ('admin')))
		{
			array_push($q, ' AND [ucitel] = %i', $this->app()->userNdx());
		}
	}

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$stupne = $this->app()->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['studentFullName'];
		$listItem ['i1'] = strval ($item['cisloStudia']);

    $listItem ['t2'] = /*\E10\Application::cfgItem ("e10pro.zus.svp.{$item ['svp']}.pojmenovani")
                      . ": " . */ $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item ['svpOddeleni']}.nazev");
		$listItem ['i2'] = $rocniky [$item['rocnik']]['nazev'];

		if ($item ['ucitelFullName'])
			$listItem ['t3'][] = ['icon' => 'iconTeachers', 'text' => $item ['ucitelFullName']];
		else
			$listItem ['t3'][] = ['icon' => 'iconTeachers', 'text' => '---'];

		if ($item ['placeName'])
			$listItem ['t3'][] = ['icon' => 'system/iconMapMarker', 'text' => $item ['placeName']];
		$listItem ['t3'][] = ['text' => $skolniRoky [$item['skolniRok']]['nazev'], 'class' => 'pull-right'];

		return $listItem;
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = array();

		$paramsRows = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsRows->addParam ('switch', 'query.ucitel', ['title' => 'Učitel', 'switch' => zusutils::ucitele($this->app)]);
		$paramsRows->addParam ('switch', 'query.pobocka', ['title' => 'Pobočka', 'switch' => zusutils::pobocky($this->app)]);
		$paramsRows->addParam ('switch', 'query.typVysvedceni', ['title' => 'Typ vysvědčení', 'place' => 'panel', 'cfg' => 'zus.typyVysvedceni',
			'enableAll' => ['99' => ['title' => 'Vše']]]);
		$paramsRows->addParam ('switch', 'query.obor', ['title' => 'Obor', 'place' => 'panel', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->addParam ('switch', 'query.predmet', ['title' => 'Předmět', 'place' => 'panel', 'cfg' => 'e10pro.zus.predmety', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->addParam ('switch', 'query.rocnik', ['title' => 'Ročník', 'place' => 'panel', 'cfg' => 'e10pro.zus.rocniky', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);

		$paramsRows->addParam ('switch', 'query.skolniRok', ['title' => 'Školní rok', 'switch' => zusutils::skolniRoky($this->app)]);

		$paramsRows->detectValues();

		$qry[] = array ('id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat', 'params' => $paramsRows, 'class' => 'switches');


		// -- errors
		$chbxErrors = [
			'withoutSubjects' => ['title' => 'Bez předmětů', 'id' => 'withoutSubjects'],
			'notFullYear' => ['title' => 'Jen část školního roku', 'id' => 'notFullYear'],
			'prematureQuit' => ['title' => 'Předčasné ukončení studia', 'id' => 'prematureQuit'],
			'lateBegin' => ['title' => 'Opožděný začátek studia', 'id' => 'lateBegin'],
			'withSale' => ['title' => 'Se slevou', 'id' => 'withSale'],
			'withoutBegin' => ['title' => 'Bez data nástupu do školy', 'id' => 'withoutBegin'],
		];
		$paramsErrors = new \E10\Params ($this->app());
		$paramsErrors->addParam ('checkboxes', 'query.errors', ['items' => $chbxErrors]);
		$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Problémy', 'params' => $paramsErrors];

		$panel->addContent(array ('type' => 'query', 'query' => $qry));
	}
} // class ViewStudium


/**
 * Class ViewStudiumCombo
 * @package E10Pro\Zus
 */

class ViewStudiumCombo extends ViewStudium
{
	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['studentFullName'];

		$listItem ['t2'] = $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item ['svpOddeleni']}.nazev");

		$listItem ['i2'] = $skolniRoky [$item['skolniRok']]['nazev'];
		$listItem ['i1'] = strval($item['cisloStudia']);

		return $listItem;
	}

	public function defaultQuery (&$q)
	{
		if ($this->queryParam('obor'))
		{
			array_push ($q, ' AND studium.[svpObor] = %i ', intval($this->queryParam('obor')));
		}

		if ((!$this->table->app()->hasRole ('scrtr')) && (!$this->table->app()->hasRole ('admin')))
		{
			array_push($q, " AND (",
					"[ucitel] = %i", $this->app()->userNdx(),
					' OR ',
					' EXISTS (SELECT ndx FROM e10pro_zus_studiumpre as sp WHERE studium.ndx = sp.studium AND sp.[ucitel] = %i)', $this->app()->userNdx(),
					')');
		}
	}

}


/**
 * Pohled na Studium z nagigace Studenta
 *
 */


class ViewStudentStudium extends ViewStudiumStudenta
{
  public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		if ($this->queryParam ('student'))
			$this->addAddParam ('student', $this->queryParam ('student'));

    parent::init();
	}
}


/**
 * Základní pohled na Studium ze Studenta
 *
 */

class ViewStudiumStudenta extends TableView
{
  public $posledniSkupina;

	public function init ()
	{
		$mq [] = array ('id' => 'aktualni', 'title' => 'Aktuální');
		$mq [] = array ('id' => 'archiv', 'title' => 'Archív');
		$mq [] = array ('id' => 'vse', 'title' => 'Vše');
		$mq [] = array ('id' => 'kos', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		//$this->setName ('Studium');
		$this->posledniSkupina = '';

  } // init

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT studium.*, persons.fullName as ucitelFullName FROM [e10pro_zus_studium] as studium LEFT JOIN e10_persons_persons AS persons ON studium.ucitel = persons.ndx WHERE 1';

		// -- fulltext
		if ($dotaz != '')
			array_push ($q, " AND [jmeno] LIKE %s", '%'.$dotaz.'%');

		// -- jen admin vidí cizí studia
		//if (!$this->table->app()->hasRole ('admin'))
		//	array_push ($q, " AND [ucitel] = %i", intval($this->table->app()->user()->data ('id')));

		// -- aktuální
		if ($mainQuery == 'aktualni' || $mainQuery == '')
			array_push ($q, " AND [stavHlavni] < %i AND [skolniRok] = %i", 4, \E10Pro\Zus\aktualniSkolniRok ());

		// -- archív
		if ($mainQuery == 'archiv')
			array_push ($q, " AND ([stavHlavni] = %i OR [skolniRok] < %i)", 5, \E10Pro\Zus\aktualniSkolniRok ());

		// koš
		if ($mainQuery == 'kos')
			array_push ($q, " AND [stavHlavni] = %i", 4);
		//else
		//	array_push ($q, " AND [stavHlavni] <> %i", 4);

		if ($this->queryParam ('student'))
			array_push ($q, " AND [student] = %i", intval($this->queryParam ('student')));


		// odfiltrovat smazané | update e10pro_zus_studium set stavHlavni = 4, stav = 9800 where smazano = 1
		//	array_push ($q, " AND [smazano] <> %i", 1);

		array_push ($q, ' ORDER BY [stavHlavni], persons.lastName, [cisloStudia], [skolniRok] DESC' . $this->sqlLimit ());
		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$stupne = $this->app()->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');

    //$itemPrint = utils::getPrintValues ($this->table, $item);

		$skupina = $this->app()->cfgItem ("e10pro.zus.obory.{$item ['svpObor']}.nazev");

		if ($this->posledniSkupina != $skupina)
		{
			$this->addGroupHeader ('Obor: ' . $skupina);
			$this->posledniSkupina = $skupina;
		}


		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = "e10pro-zus-studium";
		//$obory = $this->table->columnInfoEnum ('svp', 'cfgText');
		$listItem ['t1'] = $this->app()->cfgItem ("e10pro.zus.svp.{$item ['svp']}.pojmenovani")
                      .": " . $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item ['svpOddeleni']}.nazev");
		$listItem ['t2'] = "Učitel: " .$item ['ucitelFullName'];
		$listItem ['i1'] = $skolniRoky [$item['skolniRok']]['nazev'];

		//$typyVysvedceni = $this->table->columnInfoEnum ('typVysvedceni', 'cfgText');
		//$listItem ['i2'] = 'studium';//$typyVysvedceni [$item ['typVysvedceni']];

		if (isset ($rocniky [$item['rocnik']]) && isset ($stupne [$item['stupen']]))
			$listItem ['i2'] = $rocniky [$item['rocnik']]['nazev'];

		return $listItem;
	}
} // class ViewStudium



/**
 * Základní detail Studium
 *
 */

class ViewDetailStudium extends TableViewDetail
{
	var $addressesAll;
	var $addresses;

	public function createDetailContent ()
	{
		$this->loadDataAddresses();
		$contentContacts = $this->contentContacts();
		$this->addContent($contentContacts);

		$tablePersons = $this->table->app()->table ('e10.persons.persons');

		// -- ids
		$properties = $tablePersons->loadProperties ($this->item['student']);
		$ids = $properties[$this->item['student']]['ids'] ?? [];
		$bdate = \E10\base\searchArrayItem ($ids, 'pid', 'birthdate');
		if ($bdate)
		{
			$datumNarozeni = new \DateTime($bdate['text']);
			$ids [] = ['text' => ' Věk: '.zusutils::vekStudenta($this->table->app(), $datumNarozeni, $this->item['skolniRok'])];
		}

		if (isset ($properties[$this->item['student']]['ids']))
			$this->addContent(array ('type' => 'tags', 'tiles' => $ids, 'class' => 'contacts'));

		// -- skolne
		$this->addSkolne();
		// -- predmety
		$this->addPredmety();
		// -- rozvrh
		$this->addRozvrh();
		// -- kontrola
		$ks = new \e10pro\zus\libs\KontrolaStudia($this->app());
		$ks->setStudium($this->item['ndx']);
		$ks->run();

		if (count($ks->troubles))
		{
			$hr = ['#' => '#', 'msg' => 'Problém', ];
			$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $hr, 'table' => $ks->troubles,
					'title' => ['icon' => 'system/iconWarning', 'text' => 'Problémy', 'class' => 'h1 e10-error'], 'params' => ['__hideHeader' => 1]]);
		}
	}

	function loadDataAddresses()
	{
		$this->addresses = [];

    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $this->item['student']);
		array_push ($q, ' ORDER BY [contacts].[onTop], [contacts].[systemOrder]');
    $rows = $this->db()->query($q);
    foreach ($rows as $item)
    {
			if ($item['flagAddress'])
			{
				$ap = [];

				if ($item['adrSpecification'] != '')
					$ap[] = $item['adrSpecification'];
				if ($item['adrStreet'] != '')
					$ap[] = $item['adrStreet'];
				if ($item['adrCity'] != '')
					$ap[] = $item['adrCity'];
				if ($item['adrZipCode'] != '')
					$ap[] = $item['adrZipCode'];

				$country = World::country($this->app(), $item['adrCountry']);
				$ap[] = /*$country['f'].' '.*/$country['t'];
				$addressText = implode(', ', $ap);

				$address = [
					'icon' => 'system/iconHome',
					'c2' => []
				];
				$address['c2'][] = ['text' => $addressText, 'class' => ''];
			}
			else
			{
				$address = [
					'icon' => 'user/idCard',
					'c2' => []
				];
				//$address['c2'][] = ['text' => $addressText, 'class' => ''];
			}

			$address['isContact'] = $item['flagContact'];

			$address['c2'][] = ['text' => '', 'docAction' => 'edit', 'table' => 'e10.persons.personsContacts', 'pk' => $item['ndx'], 'class' => 'pull-right', 'icon' => 'system/actionOpen'];


      if ($item['flagMainAddress'])
        $address['c2'][] = ['text' => 'Sídlo', 'class' => 'label label-default'];
      if ($item['flagPostAddress'])
        $address['c2'][] = ['text' => 'Korespondenční', 'class' => 'label label-default'];
      if ($item['flagOffice'])
        $address['c2'][] = ['text' => 'Provozovna', 'class' => 'label label-default'];

      if ($item['flagContact'])
      {
        $address['c2'][] = ['text' => '', 'class' => 'break'];

        if ($item['contactName'] != '')
          $address['c2'][] = ['text' => $item['contactName'], 'class' => 'label label-default'];
        if ($item['contactRole'] != '')
          $address['c2'][] = ['text' => $item['contactRole'], 'class' => 'label label-default'];
        if ($item['contactEmail'] != '')
          $address['c2'][] = ['text' => $item['contactEmail'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
        if ($item['contactPhone'] != '')
          $address['c2'][] = ['text' => $item['contactPhone'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];
      }

			$this->addresses[$item['ndx']] = $address;
    }

		$pks = array_keys($this->addresses);
		if (count($pks))
		{
			$classification = UtilsBase::loadClassification ($this->table->app(), 'e10.persons.personsContacts', $pks);
			foreach ($classification as $pcNdx => $cls)
			{
				forEach ($cls as $clsfGroup)
					$this->addresses[$pcNdx]['c2'] = array_merge ($this->addresses[$pcNdx]['c2'], $clsfGroup);
			}

			$sendReports = UtilsBase::linkedSendReports($this->app(), 'e10.persons.personsContacts', $pks);
			foreach ($sendReports as $pcNdx => $sr)
			{
				if ($this->addresses[$pcNdx]['isContact'])
				{
					$this->addresses[$pcNdx]['c2'][] = ['text' => '', 'class' => 'e10-me break', 'icon' => 'system/iconPaperPlane'];
					$this->addresses[$pcNdx]['c2'] = array_merge ($this->addresses[$pcNdx]['c2'], $sr);
				}
			}
		}
	}

	public function contentContacts ()
	{
		$t = [];

		// -- contacts
		/*
		if ($this->contacts !== '')
		{
			$t [] = [
				'c1' => ['icon' => 'system/iconIdBadge', 'text' => ''],
				'c2' => $this->contacts,
				'_options' => ['cellTitles' => ['c1' => 'Kontaktní údaje']]
			];
		}
		*/

		// -- address
		if (count($this->addresses))
		{
			$cnt = 0;
			foreach ($this->addresses as $a)
			{
				$t [] = [
					'c1' => ['icon' => $a['icon'], 'text' => ''],
					'c2' => $a['c2'],
				];
				$cnt++;
				if ($cnt > 10)
				{
					$t [] = [
						'c1' => ['icon' => 'system/iconPlusSquare', 'text' => ''],
						'c2' => '... (zatím nefunguje)',
					];

					break;
				}
			}
		}

		$h = ['c1' => 'c1', 'c2' => 'c2'];
		return [
			'pane' => 'e10-pane e10-pane-top', 'type' => 'table', 'table' => $t, 'header' => $h,
			'params' => ['forceTableClass' => 'dcInfo fullWidth', 'hideHeader' => 1]
		];
	}

	public function addPredmety ()
	{
		$q =
			'SELECT predmety.nazev as predmet, ucitele.fullName as ucitel ' .
			' FROM [e10pro_zus_studiumpre] as studiumpre  '.
			' LEFT JOIN [e10_persons_persons] AS ucitele ON studiumpre.ucitel = ucitele.ndx'.
			' LEFT JOIN [e10pro_zus_predmety] AS predmety ON studiumpre.svpPredmet = predmety.ndx'.
			' WHERE studiumpre.studium = %i ORDER BY studiumpre.ndx';
		$rows = $this->db()->query($q, $this->item['ndx'])->fetchAll();

		$title = [['icon' => 'tables/e10pro.zus.predmety', 'text' => 'Předměty']];

		$vysvedceni = $this->db()->query('SELECT * FROM e10pro_zus_vysvedceni WHERE studium = %i', $this->item['ndx'])->fetch();
		if ($vysvedceni)
			$title[] = ['text' => 'Vysvědčení', 'docAction' => 'edit', 'table' => 'e10pro.zus.vysvedceni', 'pk'=> $vysvedceni['ndx'],
				'class' => 'pull-right', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-primary', 'icon' => 'system/actionOpen'];
		else
			$title[] = ['text' => 'Nové vysvědčení', 'docAction' => 'new', 'table' => 'e10pro.zus.vysvedceni', 'addParams' => '__studium='.$this->item['ndx'],
				'class' => 'pull-right', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-success', 'icon' => 'system/actionAdd'];

		if (count($rows))
		{
			$this->addContent ([
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => ['#' => '#', 'predmet' => 'Předměty', 'ucitel' => 'Učitel'],
				'table' => $rows, 'title' => $title, 'params' => ['hideHeader' => 1]
			]);
		}
		else
		{
			$this->addContent ([
				'pane' => 'e10-pane e10-pane-table e10-warning2', 'header' => ['predmet' => 'Předměty'],
				'table' => [['predmet' => 'není zadán žádný předmět']], 'title' => $title, 'params' => ['hideHeader' => 1, 'forceTableClass' => '']
			]);
		}
	}

	public function addSkolne ()
	{
		$item = $this->item;
		$skolne = [];

		// -- 1. pololeti
		$p1 = ['p' => '1. pol.', 'fv' => '-', 'stav' => '-'];
		if ($item ['skolVyPrvniPol'])
		{
			if ($item ['skolSlPrvniPol'])
				$p1['castka'] = utils::nf ($item ['skolnePrvniPol']).' - '.utils::nf ($item ['skolSlPrvniPol']).' = '.utils::nf ($item ['skolVyPrvniPol']);
			else
				$p1['castka'] = utils::nf ($item ['skolVyPrvniPol']);

			if ($item['skolniRok'] != '')
				$symbol2 = ($item['skolniRok'] - 2000) . ($item['skolniRok'] - 2000 + 1) . '1';
			else
				$symbol2 = '00';
			$qfv[] = 'SELECT * FROM e10doc_core_heads WHERE 1';
			array_push($qfv, ' AND [docState] = 4000');
			array_push($qfv, ' AND docType = %s', 'invno',
											 ' AND symbol1 = %s', $this->item['cisloStudia'], ' AND symbol2 = %s', $symbol2);
			$fvr = $this->db()->query($qfv)->fetch();

			if ($fvr)
			{
				$p1['fv'] = [
					['text' => $fvr['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $fvr['ndx']],
					['text' => ' z '.utils::datef ($fvr['dateAccounting'], '%d').' na '.utils::nf($fvr['toPay']).',-']
				];
			}
			$stav = zusutils::uhradySkolneho($this->app(), $item['student'], $item ['skolniRok'], $item['cisloStudia'], '1', 1);
			if ($stav)
				$p1['stav'] = $stav;
		}

		// -- 2. pololeti
		unset($qfv);
		$p2 = ['p' => '2. pol.', 'fv' => '-', 'stav' => '-'];
		if ($item ['skolVyDruhePol'])
		{
			if ($item ['skolSlDruhePol'])
				$p2['castka'] = utils::nf ($item ['skolneDruhePol']).' - '.utils::nf ($item ['skolSlDruhePol']).' = '.utils::nf ($item ['skolVyDruhePol']);
			else
				$p2['castka'] = utils::nf ($item ['skolVyDruhePol']);

			if ($item['skolniRok'] != '')
				$symbol2 = ($item['skolniRok'] - 2000) . ($item['skolniRok'] - 2000 + 1) . '2';
			else
				$symbol2 = '00';

			$qfv[] = 'SELECT * FROM e10doc_core_heads WHERE 1';
			array_push($qfv, ' AND [docState] = 4000');
			array_push($qfv, ' AND docType = %s', 'invno',
											 ' AND symbol1 = %s', $this->item['cisloStudia'], ' AND symbol2 = %s', $symbol2);
			$fvr = $this->db()->query($qfv)->fetch();

			if ($fvr)
			{
				$p2['fv'] = [
					['text' => $fvr['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $fvr['ndx']],
					['text' => ' z '.utils::datef ($fvr['dateAccounting'], '%d').' na '.utils::nf($fvr['toPay']).',-']
				];
			}
			$stav = zusutils::uhradySkolneho($this->app(), $item['student'], $item ['skolniRok'], $item['cisloStudia'], '2', 1);
			if ($stav)
				$p2['stav'] = $stav;
		}

		$skolne[] = $p1;
		$skolne[] = $p2;

		// -- půjčovné
		if ($item ['pujcovne'])
		{
			unset($qfv);
			$p3 = ['p' => 'půjčovné', 'fv' => '-', 'stav' => '-'];
			$p3['castka'] = utils::nf($item ['pujcovne']);

			$symbol2 = ($item['skolniRok'] - 2000) . ($item['skolniRok'] - 2000 + 1) . '3';
			$qfv[] = 'SELECT * FROM e10doc_core_heads WHERE 1';
			array_push($qfv, ' AND [docState] = 4000');
			array_push($qfv, ' AND docType = %s', 'invno',
					' AND symbol1 = %s', $this->item['cisloStudia'], ' AND symbol2 = %s', $symbol2);
			$fvr = $this->db()->query($qfv)->fetch();

			if ($fvr)
			{
				$p3['castka'] = utils::nf($fvr ['toPay']);
				$p3['fv'] = [
						['text' => $fvr['docNumber'], 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $fvr['ndx']],
						['text' => ' z ' . utils::datef($fvr['dateAccounting'], '%d') . ' na ' . utils::nf($fvr['toPay']) . ',-']
				];
			}
			$stav = zusutils::uhradySkolneho($this->app(), $item['student'], $item ['skolniRok'], $item['cisloStudia'], '3', 1);
			if ($stav)
				$p3['stav'] = $stav;
			$skolne[] = $p3;
		}

		$title = [['icon' => 'system/iconMoney', 'text' => 'Školné a půjčovné']];
		if ($item['bezDotace'])
			$title[] = ['icon' => 'system/iconWarning', 'text' => 'bez dotace', 'class' => 'pull-right'];
		$this->addContent ([
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => ['p' => 'Pol.', 'castka' => ' Částka', 'fv' => 'Faktura', 'stav' => 'Stav'],
			'table' => $skolne, 'title' => $title, 'params' => ['hideHeader' => 1]
		]);
	}

	public function addRozvrh ()
	{
		$tableRozvrh = $this->app()->table('e10pro.zus.vyukyrozvrh');
		$nazvyDnu = $tableRozvrh->columnInfoEnum ('den', 'cfgText');
		$rozvrh = [];
		$vyukyStudenta = $this->vyukyStudenta ($this->item['ndx']);

		$title = [['icon' => 'system/iconClock', 'text' => 'Rozvrh', 'class' => 'h2']];

		if (!count($vyukyStudenta))
			return;

		$today = utils::today();

		$q[] = 'SELECT rozvrh.*, persons.fullName as personFullName, predmety.nazev as predmet,';
		array_push($q, ' vyuky.typ as typVyuky, vyuky.rocnik as rocnik, vyuky.datumZahajeni, vyuky.datumUkonceni,');
		array_push($q, ' places.shortName as placeName, ucebny.shortName as ucebnaName');
		array_push($q, ' FROM e10pro_zus_vyukyrozvrh AS rozvrh');
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON rozvrh.ucitel = persons.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS places ON rozvrh.pobocka = places.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');

		array_push($q, ' WHERE rozvrh.vyuka IN %in', array_keys($vyukyStudenta));
		array_push($q, ' AND rozvrh.stavHlavni < %i', 4);
		array_push($q, ' AND vyuky.skolniRok = %s', zusutils::aktualniSkolniRok());
		array_push($q, ' ORDER BY rozvrh.den, rozvrh.zacatek');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$vk = $vyukyStudenta[$r['vyuka']];
			$ikonaPredmet = ($r['typVyuky'] === 0) ? 'user/group' : 'system/iconUser';

			$itm = [
				'den' => [
					['icon' => 'system/actionOpen', 'text' => '', 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk'=> $r['vyuka'],
						'type' => 'button', 'actionClass' => 'e10-off'],
					['text' => ' '.$nazvyDnu[$r['den']]]
				],
				'doba' => [['text' => $r['zacatek'].' - '.$r['konec'], 'class' => '']],
				'ucitel' => $r['personFullName'],
				'predmet' => ['icon' => $ikonaPredmet, 'text' => ($r['predmet']) ? $r['predmet'] : '--- BEZ PŘEDMĚTU ---'],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app(), $r['rocnik'], $r['typVyuky']),
				'pobocka' => $r['placeName'],
				'ucebna' => $r['ucebnaName']
			];

			if (!utils::dateIsBlank($r['datumUkonceni']) && $r['datumUkonceni'] < $today)
			{
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';

			}
			elseif (!utils::dateIsBlank($r['datumZahajeni']) && $r['datumZahajeni'] > $today)
			{
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';
			}

			if (isset($vk['platnostDo']) && !utils::dateIsBlank($vk['platnostDo']) && $vk['platnostDo'] < $today)
			{
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';
			}
			elseif (isset($vk['platnostOd']) && !utils::dateIsBlank($vk['platnostOd']) && $vk['platnostOd'] > $today)
			{
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';
			}

			if (isset($vk['platnostOd']) && !utils::dateIsBlank($vk['platnostOd']))
			{
				$itm['doba'][] = ['text' => 'od '.utils::datef($vk['platnostOd'], '%S'), 'class' => 'block e10-small'];
			}
			if (isset($vk['platnostDo']) && !utils::dateIsBlank($vk['platnostDo']))
			{
				$itm['doba'][] = ['text' => 'do '.utils::datef($vk['platnostDo'], '%S'), 'class' => 'block e10-small'];
			}

			$rozvrh[] = $itm;
		}

		if (count($rozvrh))
			$this->addContent([
				'pane' => 'e10-pane e10-pane-table',
				'header' => ['den' => '_Den', 'doba' => '_Čas', 'predmet' => '_Předmět', 'rocnik' => 'Ročník', 'ucitel' => 'Učitel', 'pobocka' => 'Pobočka', 'ucebna' => 'Učebna'],
				'table' => $rozvrh, 'title' => $title, 'params' => ['hideHeader' => 1]
			]);
		/*
		else
			$this->addContent([
				'pane' => 'e10-pane e10-pane-table e10-error',
				'header' => ['txt' => 'txt'],
				'table' => [['txt' => 'Rozvrh není naplánován']], 'title' => $title, 'params' => ['hideHeader' => 1, 'forceTableClass' => '']
			]);
		*/
	}

	public function vyukyStudenta ($studiumNdx)
	{
		$vyuky = [];

		// -- individuální
		$q[] = 'SELECT ndx FROM e10pro_zus_vyuky';
		array_push($q, 'WHERE typ = 1 AND studium = %i', $studiumNdx);
		$rows = $this->db()->query($q);
		foreach($rows as $r)
		{
			if (!isset($vyuky[$r['ndx']]))
				$vyuky[$r['ndx']] = $r->toArray();
		}
		// -- kolektivní
		unset ($q);
		$q[] = ' SELECT vyuka, platnostOd, platnostDo FROM e10pro_zus_vyukystudenti studenti';
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON studenti.vyuka = vyuky.ndx');
		array_push($q, ' WHERE vyuky.typ = 0 AND studenti.studium = %i', $studiumNdx);


		$rows = $this->db()->query($q);
		foreach($rows as $r)
		{
			if (!isset($vyuky[$r['vyuka']]))
				$vyuky[$r['vyuka']] = $r->toArray();
		}

		return $vyuky;
	}
}


/**
 * Class FormStudium
 * @package E10Pro\Zus
 */
class FormStudium extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);

		$ro = TRUE;
		if ($this->app()->hasRole('zusadm'))
			$ro = FALSE;

		$co = 0;
		if ($ro)
			$co = TableForm::coReadOnly;

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
		$this->openTabs ($tabs, TRUE);
			$this->openTab ();
				$this->addColumnInput ('ucitel', $co);
				$this->addColumnInput ('misto', $co);
        $this->layoutOpen (TableForm::ltHorizontal);
          $this->layoutOpen (TableForm::ltForm);
            $this->addColumnInput ('skolniRok', $co);
            $this->addColumnInput ('rocnik', $co);
						$this->addColumnInput ('svp', $co);
						$this->addColumnInput ('svpObor', $co);
						$this->addColumnInput ('svpOddeleni', $co);
						$this->addColumnInput ('urovenStudia', $co);
						$this->addColumnInput ('cisloStudia', $co);
            //$this->addColumnInput ('stupen');
          $this->layoutClose ('width50');

          $this->layoutOpen (TableForm::ltForm);
						$this->addColumnInput ('skolnePrvniPol', $co);
						$this->addColumnInput ('skolSlPrvniPol', $co);
						$this->addColumnInput ('skolVyPrvniPol', TableForm::coReadOnly);

						$this->addColumnInput ('skolneDruhePol', $co);
						$this->addColumnInput ('skolSlDruhePol', $co);
						$this->addColumnInput ('skolVyDruhePol', TableForm::coReadOnly);

						$this->addColumnInput ('pujcovne', $co);
						$this->addColumnInput ('bezDotace', $co);
          $this->layoutClose ();

        $this->layoutClose ();

				$this->addSeparator(TableForm::coH2);
				$this->addStatic('Předměty', TableForm::coH1);
        $this->layoutOpen (TableForm::ltHorizontal);
          $this->addList ('predmety');
        $this->layoutClose ();

			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ('datumNastupuDoSkoly', $co);
				$this->addColumnInput ('datumUkonceniSkoly', $co);
				$this->addColumnInput ('typVysvedceni', $co);
				$this->addColumnInput ('student', $co);
				$this->addColumnInput ('poradoveCislo', $co);

				$this->addColumnInput ('platce', $co);
			$this->closeTab ();

      $this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer ();
			$this->closeTab ();

		$this->closeTabs ();

		$this->closeForm ();
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case 'svpOddeleni': return $this->app()->cfgItem ("e10pro.zus.svp.{$this->recData ['svp']}.pojmenovani");
    }
    return parent::columnLabel ($colDef, $options);
  }

	public function checkBeforeSave (&$saveData)
	{
		parent::checkBeforeSave($saveData);
		zusutils::testValues ($saveData ['recData'], $this);
	}

	public function checkNewRec ()
	{
		parent::checkNewRec();
		zusutils::testValues ($this->recData, $this);
	}
}


/**
 * Widget studium studenta
 *
 */

class WidgetStudiumStudenta extends \E10\TableView
{
	public $posledniSkupina;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		if ($this->queryParam ('student'))
			$this->addAddParam ('student', $this->queryParam ('student'));
		$this->setName ('Studium');
		$this->posledniSkupina = '';
		parent::init();
	}

	public function renderRow ($item)
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');

		$itemPrint = utils::getPrintValues ($this->table, $item);

		$skupina = $itemPrint['obor'] . ' obor, oddělení ' . $itemPrint['oddeleni'];

		if ($this->posledniSkupina != $skupina)
		{
			$this->addGroupHeader ('Studium: ' . $skupina);
			$this->posledniSkupina = $skupina;
		}

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $skolniRoky [$item['skolniRok']]['nazev'];
		$listItem ['i1'] = $itemPrint ['rocnik'] .' ročník';
		$listItem ['i2'] = '';//$itemPrint ['hodnoceni1p'] .' / ' . $itemPrint ['hodnoceni2p'];
		if ($this->docState)
		{
			$docStateName = $this->table->getDocumentStateInfo ($this->docState ['states'], $item, 'name');
			$docStateIcon = $this->table->getDocumentStateInfo ($this->docState ['states'], $item, 'styleIcon');
			if ($docStateName)
				$listItem ['t2'] = $docStateName;
			$listItem ['i'] = $docStateIcon;
		}
		return $listItem;
	}


	public function selectRows ()
	{
		$q = "SELECT * FROM [e10pro_zus_studium] WHERE [studium] = %i ORDER BY [obor], [oddeleni], [rocnik] DESC, [ndx] DESC" . $this->sqlLimit();
		$this->runQuery ($q, $this->queryParam ('student'));
	}
} // class WidgetStudiumStudenta



/**
 * Widget výuka učitele
 *
 */

class WidgetVyukaUcitele extends \E10\TableView
{
	public $posledniSkupina;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		if ($this->queryParam ('teacher'))
			$this->addAddParam ('ucitel', $this->queryParam ('teacher'));
		$this->setName ('Výuka');
		$this->posledniSkupina = '';
		parent::init();
	}
/*
  public function createToolbar ()
	{
		return array ();
	} // createToolbar
*/
  public function renderRow ($item)
	{
		$skolniRoky = Application::cfgItem ('e10pro.zus.roky');

		$itemPrint = utils::getPrintValues ($this->table, $item);

		$skupina = $skolniRoky [$item['skolniRok']]['nazev'];

		if ($this->posledniSkupina != $skupina)
		{
			$this->addGroupHeader ('Školní rok: ' . $skupina);
			$this->posledniSkupina = $skupina;
		}

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['jmeno'];
		$listItem ['i1'] = $itemPrint ['rocnik'] .' ročník';
//		$listItem ['i2'] = $itemPrint ['hodnoceni1p'] .' / ' . $itemPrint ['hodnoceni2p'];

//		$listItem ['t2'] = $item ['datumNarozeni'];
//		$listItem ['i1'] = $skolniRoky [$item['skolniRok']];

		$typyVysvedceni = $this->table->columnInfoEnum ('typVysvedceni', 'cfgText');
		$listItem ['i2'] = $typyVysvedceni [$item ['typVysvedceni']];

		if ($this->documentStates)
		{
			$docStateName = $this->table->getDocumentStateInfo ($this->documentStates, $item, 'name');
			$docStateIcon = $this->table->getDocumentStateInfo ($this->documentStates, $item, 'styleIcon');
			if ($docStateName)
				$listItem ['t2'] = $docStateName;
			$listItem ['i'] = $docStateIcon;
		}
		return $listItem;
	}


	public function selectRows ()
	{
		$q = "SELECT studium.*, persons.fullName as personFullName FROM [e10pro_zus_studium] as studium LEFT JOIN e10_persons_persons AS persons ON studium.student = persons.ndx WHERE [ucitel] = %i ORDER BY [stavHlavni], [skolniRok] DESC, persons.lastName" . $this->sqlLimit();
		$this->runQuery ($q, $this->queryParam ('teacher'));
	}
} // class WidgetVyukaUcitele
