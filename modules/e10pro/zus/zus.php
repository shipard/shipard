<?php

namespace E10Pro\Zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/tables/places.php';

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Viewer\TableViewDetail, \Shipard\Viewer\TableViewPanel;
use \E10\utils, \E10\Wizard;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;

/**
 * funkce
 *
 */

function monthName2 ($month_int)
{
	$month_int = (int)$month_int;
	$months = array("","leden", "únor", "březen", "duben", "květen", "červen", "červenec", "srpen", "září", "říjen", "listopad", "prosinec");
	return $months[$month_int];
}


function aktualniSkolniRok ()
{
	return zusutils::aktualniSkolniRok();
}


/**
 * Class zusutils
 * @package E10Pro\Zus
 */
class zusutils
{
	static function pobocky ($app, $enableAll = TRUE, $enableAllText = 'Vše')
	{
		$pobocky = $app->db()->query('SELECT ndx, fullName FROM [e10_base_places] WHERE docStateMain < 4 AND placeType = %s ORDER BY [fullName]', 'lcloffc')->fetchPairs ('ndx', 'fullName');
		if ($enableAll)
			return ['0' => $enableAllText] + $pobocky;
		return $pobocky;
	}

	static function ucitele ($app, $enableAll = TRUE)
	{
		$group = 'e10pro-zus-groups-teachers';
		$groupNdx = 0;
		$groupsMap = $app->cfgItem ('e10.persons.groupsToSG', FALSE);
		if ($groupsMap && isset ($groupsMap [$group]))
			$groupNdx = $groupsMap [$group];

		$ucitele = [];
		if ($enableAll)
			$ucitele['0'] = 'Vše';

		$rows = $app->db()->query ('SELECT teachers.ndx, teachers.lastName, teachers.firstName FROM [e10_persons_personsgroups] AS pgroups ',
				' LEFT JOIN e10_persons_persons AS teachers ON pgroups.person = teachers.ndx ',
        ' WHERE pgroups.group = %i AND teachers.docStateMain < 4 ORDER BY teachers.[lastName]', $groupNdx);

		foreach ($rows as $r)
			$ucitele[$r['ndx']] = $r['lastName'].' '.$r['firstName'];

		return $ucitele;
	}

	static function uciteleNaPobocce ($app, $officeNdx, $enableAll = TRUE)
	{
		$ucitele = [];
		if ($enableAll)
			$ucitele['0'] = 'Vše';

		$q[] = 'SELECT * FROM [e10_persons_persons] AS persons WHERE 1';
		array_push($q, ' AND [persons].docStateMain < %i', 4);
		array_push ($q, ' AND EXISTS (');
		array_push ($q, 'SELECT e10pro_zus_vyukyrozvrh.ucitel FROM e10pro_zus_vyukyrozvrh');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky ON e10pro_zus_vyukyrozvrh.vyuka = e10pro_zus_vyuky.ndx');
		array_push ($q, ' WHERE persons.ndx = e10pro_zus_vyukyrozvrh.ucitel ');
		array_push ($q, ' AND e10pro_zus_vyuky.skolniRok = %s', zusutils::aktualniSkolniRok($app));
		if ($officeNdx)
			array_push ($q, ' AND e10pro_zus_vyukyrozvrh.pobocka = %i', $officeNdx);
		array_push ($q, ')');
		array_push($q, ' ORDER BY persons.lastName, persons.firstName');

		$rows = $app->db()->query ($q);

		foreach ($rows as $r)
		{
			$ucitele[$r['ndx']] = $r['lastName'] . ' ' . $r['firstName'];
		}
		return $ucitele;
	}

	static function obory($app, $enableAll = TRUE, $textAll = 'Vše')
	{
		$enum = [];
		if ($enableAll)
			$enum[0] = $textAll;
		$obory = $app->cfgItem('e10pro.zus.obory');
		foreach ($obory as $oborNdx => $obor)
		{
			$enum[$oborNdx] = $obor['nazev'];
		}

		return $enum;
	}

	static function predmety($app, $enableAll = TRUE)
	{
		$enum = [];
		if ($enableAll)
			$enum[0] = 'Vše';
		$predmety = $app->cfgItem('e10pro.zus.predmety');
		foreach ($predmety as $predmetNdx => $predmet)
		{
			$enum[$predmetNdx] = $predmet['nazev'];
		}

		return $enum;
	}

	static function rocniky($app)
	{
		$enum = [];
		$enum[0] = 'Vše';
		$rocniky = $app->cfgItem('e10pro.zus.rocniky');
		foreach ($rocniky as $rocnikNdx => $rocnik)
		{
			$enum[$rocnikNdx] = $rocnik['nazev'];
		}

		return $enum;
	}

	static function aktivniSkolniRoky ($app)
	{
		$skolniroky = $app->db()->query ('SELECT YEAR(roky.[datumZacatek]) as ndx, roky.nazev as name FROM [e10pro_zus_roky] AS roky '.
			'WHERE roky.docStateMain < 4 ORDER BY roky.[datumZacatek]')->fetchPairs ('ndx', 'name');
		return $skolniroky;
	}

	static function skolniRoky ($app)
	{
		$skolniroky = $app->db()->query ('SELECT YEAR(roky.[datumZacatek]) as ndx, roky.nazev as name FROM [e10pro_zus_roky] AS roky '.
				'WHERE roky.docStateMain != 4 ORDER BY roky.[datumZacatek]')->fetchPairs ('ndx', 'name');
		return ['0' => 'Vše'] + $skolniroky;
	}

	static function aktualniSkolniRok ($app = NULL)
	{
		/*$d = getdate ();
		$m = $d ['mon'];
		$y = $d ['year'];*/

		$d = Utils::today('', $app);
		$m = intval($d->format('m'));
		$y = intval($d->format('Y'));
		if ($m <= 6)
			return $y - 1;
		return $y;
	}

	static function rocnikVRozvrhu ($app, $rocnik, $typVyuky, $key = 'nazev')
	{
		$rocniky = $app->cfgItem ('e10pro.zus.rocniky');
		$res = '';
		if ($typVyuky == 1)
			$res = $rocniky [$rocnik][$key];
		return $res;
	}

	static function uhradySkolneho ($app, $student, $skolniRok, $cisloStudia, $rozliseniPlatby, $style) {
		$uhrady = [];
		if ($skolniRok == '')
			return $uhrady;

		$q[] = 'SELECT heads.dateAccounting as date, journal.docHead as docHead, heads.docType as docType, heads.docNumber, SUM(journal.request) as predpis, SUM(journal.payment) as vyrovnani';
		array_push($q, ' FROM e10doc_balance_journal AS journal');
		array_push($q, '	LEFT JOIN e10doc_core_heads as heads ON journal.docHead = heads.ndx');
		array_push($q, ' WHERE journal.side = 1 AND journal.type = 1000 AND journal.symbol1 = %s', $cisloStudia);
		array_push ($q, " AND journal.symbol2 like %s", strval($skolniRok-2000) . strval($skolniRok-1999) . $rozliseniPlatby . "%");
		array_push($q, ' GROUP BY 1, 2, 3, 4');
		array_push($q, ' HAVING SUM(journal.payment) <> 0');
		array_push($q, ' ORDER BY 1, 2, 3, 4');

		$czk = '';
		if ($style == 1)
			$czk = ',-';

		$celkemUhrazeno = 0;
		$rows = $app->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($celkemUhrazeno != 0)
				$uhrady[] = '+ ';
			$docItem = ['icon' => $app->table('e10doc.core.heads')->tableIcon ($r), 'text' => utils::nf($r['vyrovnani']).$czk,
				'class' => 'label label-info', //''tag tag-contact',
				'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['docHead']];

			if ($style == 1)
				$docItem['prefix'] = utils::datef ($r['date']);
			else
				$docItem['title'] = utils::datef ($r['date'], '%d').' - doklad č. '.$r['docNumber'];

			$uhrady[] = $docItem;
			$celkemUhrazeno += $r['vyrovnani'];
		}
		if ($style == 0)
		{
			if ($celkemUhrazeno != 0)
				$uhrady[] = ['='.utils::nf($celkemUhrazeno)];
		}
		return $uhrady;
	}

	static function saldoSkolneho ($app, $student, $skolniRok, $cisloStudia, $rozliseniPlatby, $style)
	{
		$docTypes = $app->cfgItem ('e10.docs.types');

		$q[] = 'SELECT heads.dateAccounting as date, journal.docHead as docHead, journal.side, heads.docType as docType,';
		array_push($q, ' heads.docNumber, heads.ndx as headNdx, heads.dateDue as docDateDue, journal.symbol2 as symbol2, journal.request as predpis, journal.payment as vyrovnani');
		array_push($q, ' FROM e10doc_balance_journal AS journal');
		array_push($q, ' LEFT JOIN e10doc_core_heads as heads ON journal.docHead = heads.ndx');
		array_push($q, ' WHERE journal.type = 1000 AND journal.symbol1 = %s', $cisloStudia);
		array_push($q, ' AND journal.symbol2 like %s', strval($skolniRok-2000) . strval($skolniRok-1999) . $rozliseniPlatby . "%");
		array_push($q, ' AND heads.initState = 0');
		array_push($q, ' ORDER BY 1, 2, 3, 4, 5');

		$czk = '';
		if ($style == 1)
			$czk = ',-';

		$celkemKUhrade = 0;
		$celkemUhrazeno = 0;
		$totals = [
			'HY1' => ['request' => 0.0, 'payment' => 0.0],
			'HY2' => ['request' => 0.0, 'payment' => 0.0],
			'HY3' => ['request' => 0.0, 'payment' => 0.0],
		];
		$uhrady = [];
		$predpisy = [];
		$heads = [];
		$minDate = utils::today();
		$docDateDue = NULL;
		$rows = $app->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['side'] == 0)
			{
				$hyId = 'HY'.substr($r['symbol2'], -1, 1);
				$totals[$hyId]['request'] += $r['predpis'];

				$docItem = [
					'ndx' => $r['headNdx'],
					'icon' => $docTypes[$r['docType']]['icon'],
					'text' => $r['docNumber'],
					'class' => 'label label-default', //''tag tag-contact',
					'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['docHead']];

				if ($style == 1)
					$docItem['prefix'] = utils::datef($r['date']);
				else
					$docItem['title'] = utils::datef ($r['date'], '%d').' - doklad č. '.$r['docNumber'].' - částka '.utils::nf($r['predpis']) . $czk;

				$predpisy[] = $docItem;
				$celkemKUhrade += $r['predpis'];
			}
			else
			{
				$hyId = 'HY'.substr($r['symbol2'], -1, 1);
				$totals[$hyId]['payment'] += $r['vyrovnani'];

				$docItem = [
					'ndx' => $r['headNdx'],
					'icon' => $docTypes[$r['docType']]['icon'],
					'text' => $r['docNumber'],
					'class' => 'label label-info', //''tag tag-contact',
					'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['docHead']];

				if ($style == 1)
					$docItem['prefix'] = utils::datef ($r['date']);
				else
					$docItem['title'] = utils::datef ($r['date'], '%d').' - doklad č. '.$r['docNumber'].' - částka '.utils::nf($r['predpis']) . $czk;

				$uhrady[] = $docItem;
				$celkemUhrazeno += $r['vyrovnani'];
			}
			$heads[] = $r['headNdx'];
			if ($r['date'] < $minDate)
				$minDate = $r['date'];
			$docDateDue = $r['docDateDue'];
		}
		if ($style == 0)
		{
			if ($celkemKUhrade != 0)
				$predpisy[] = ['= '.utils::nf($celkemKUhrade)];
			if ($celkemUhrazeno != 0)
				$uhrady[] = ['='.utils::nf($celkemUhrazeno)];
		}

		$res = [
				'docPredpisy' => $predpisy, 'celkKUhrade' => $celkemKUhrade, 'docUhrady' => $uhrady, 'celkUhrazeno' => $celkemUhrazeno,
				'heads' => $heads, 'minDate' => $minDate, 'docDateDue' => $docDateDue, 'totals' => $totals
		];

		return $res;
	}

	static function testValues (&$recData, $form)
	{
		if (!isset($recData ['svp']))
			$recData ['svp'] = 0;
		if (!isset($recData ['svpObor']))
			$recData ['svpObor'] = 0;
		if (!isset($recData ['svpOddeleni']))
			$recData ['svpOddeleni'] = 0;

		$povolenaSvp = $form->table->columnInfoEnum ('svp', 'cfgText', $form);
		if (!isset ($povolenaSvp[$recData ['svp']]))
			$recData ['svp'] = key($povolenaSvp);

		$povoleneObory = $form->table->columnInfoEnum ('svpObor', 'cfgText', $form);
		if (!isset ($povoleneObory[$recData ['svpObor']]))
			$recData ['svpObor'] = key($povoleneObory);

		$povolenaOdeleni = $form->table->columnInfoEnum ('svpOddeleni', 'cfgText', $form);
		if (!isset ($povolenaOdeleni[$recData ['svpOddeleni']]))
			$recData ['svpOddeleni'] = key($povolenaOdeleni);
	}

	static function columnInfoEnumTest ($columnId, $cfgItem, $form)
	{
		if ($columnId == 'svpObor')
		{
			if (!$form)
				return TRUE;

			if (!isset($cfgItem ['svp']) || $cfgItem ['svp'] === 0)
				return TRUE;
			if ($form->recData ['svp'] != $cfgItem ['svp'])
				return FALSE;

			return TRUE;
		}

		if ($columnId == 'svpOddeleni')
		{
			if (!$form)
				return TRUE;

			if ($form->recData ['svp'] != $cfgItem ['svp'] && $cfgItem ['svp'] != 0)
				return FALSE;

			if ($form->recData ['svpObor'] != $cfgItem ['obor'] && $cfgItem ['obor'] != 0)
				return FALSE;

			if (isset($form->recData ['urovenStudia']) && $form->recData ['urovenStudia'] != $cfgItem ['urovenStudia'] && $cfgItem ['urovenStudia'] != 0)
				return FALSE;

			return TRUE;
		}

		return NULL;
	}

	static function ucebniPlan ($app)
	{
		$up = [];

		$q[] = 'SELECT SUM([rows].hours) as hours, heads.year as rocnik, heads.svpObor as obor, heads.eduprogram as svp, heads.svpOddeleni as oddeleni, [rows].subject as predmet';
		array_push($q, ' FROM e10pro_zus_teachplanrows as [rows]');
		array_push($q, ' LEFT JOIN e10pro_zus_teachplanheads as heads ON [rows].plan = heads.ndx');
		array_push($q, ' GROUP BY 2, 3, 4, 5, 6');

		$rows = $app->db()->query ($q);
		foreach ($rows as $r)
		{
			$id = $r['predmet'].'-'.$r['rocnik'].'-'.$r['svp'].'-'.$r['obor'].'-'.$r['oddeleni'];
			$up[$id] = $r['hours'] * 45;
		}

		return $up;
	}

	static function vekStudenta ($app, $datumNarozeni, $skolniRok)
	{
		$skolniRok = $app->cfgItem('e10pro.zus.roky.' . $skolniRok);
		$zacatekSkolnihoRoku = new \DateTime($skolniRok['zacatek']);
		$vek = $datumNarozeni->diff($zacatekSkolnihoRoku)->y;
		$res = $vek . ' ';
		if ($vek == 1)
			$res .= 'rok';
		else
			if ($vek < 5)
				$res .= 'roky';
			else
				$res .= 'let';
		return $res;
	}
}


/**
 * Class ViewStudents
 * @package E10Pro\Zus
 */
class ViewStudents extends \e10\persons\ViewPersonsBase
{
	public static $defaultIconSet = array ('x-company', 'x-boy', 'x-girl');
	var $studia = [];

	public function init ()
	{
		$this->setMainGroup ('e10pro-zus-groups-students');

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'archive', 'title' => 'Archív'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		parent::init();

		if ($this->app->hasRole('zusadm') || $this->app->hasRole('scrtr'))
			$this->setPanels (TableView::sptQuery);
	}

	public function icon ($recData, $iconSet = NULL)
	{
		return parent::icon ($recData, self::$defaultIconSet);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = array();

		$paramsRows = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsRows->addParam ('switch', 'query.ucitel', ['title' => 'Učitel', 'switch' => zusutils::ucitele($this->app)]);
		$paramsRows->addParam ('switch', 'query.pobocka', ['title' => 'Pobočka', 'switch' => zusutils::pobocky($this->app)]);
		$paramsRows->addParam ('switch', 'query.obor', ['title' => 'Obor', 'place' => 'panel', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
				'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->addParam ('switch', 'query.predmet', ['title' => 'Předmět', 'place' => 'panel', 'cfg' => 'e10pro.zus.predmety', 'titleKey' => 'nazev',
				'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->detectValues();
		$qry[] = ['id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat', 'params' => $paramsRows];

		// -- others
		$chbxOthers = [
			'withoutPID' => ['title' => 'Bez rodného čísla', 'id' => 'withoutPID'],
			'withoutEmail' => ['title' => 'Bez e-mailu', 'id' => 'withoutEmail'],
			'badContacts' => ['title' => 'Vadné kontakty', 'id' => 'badContacts'],
			'withoutContacts' => ['title' => 'Bez kontaktů', 'id' => 'withoutContacts'],
			'withoutMainAddress' => ['title' => 'Bez bydliště', 'id' => 'withoutMainAddress'],
			'badAddress' => ['title' => 'Vadné adresy', 'id' => 'badAddress'],
			'withoutStudium' => ['title' => 'Bez studia', 'id' => 'withoutStudium'],
		];
		$paramsOthers = new \E10\Params ($this->app());
		$paramsOthers->addParam ('checkboxes', 'query.others', ['items' => $chbxOthers]);
		$qry[] = ['id' => 'errors', 'style' => 'params', 'title' => 'Ostatní', 'params' => $paramsOthers];


		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	function decorateRow (&$item)
	{
		if (isset ($this->properties [$item ['pk']]['groups']))
			$item ['i2'] = $this->properties [$item ['pk']]['groups'];

		if (isset ($this->properties [$item ['pk']]['ids']))
			$item ['t2'] = $this->properties [$item ['pk']]['ids'];

		$bdate = \E10\base\searchArrayItem ($this->properties [$item ['pk']]['ids'], 'pid', 'birthdate');
		if ($bdate ['valueDate'])
			$item ['t2'][] = ['text' => zusutils::vekStudenta($this->app, $bdate ['valueDate'], zusutils::aktualniSkolniRok()), 'class' => 'label label-default'];

		if (isset ($this->studia[$item ['pk']]))
			$item ['i1'] = $this->studia[$item ['pk']];
		else
			unset($item ['i1']);
	}

	public function qryPanel (array &$q)
	{
		$qv = $this->queryValues();
		if (isset($qv['ucitel']['']) && $qv['ucitel'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (',
					'SELECT student FROM e10pro_zus_studium WHERE persons.ndx = e10pro_zus_studium.student ',
					'AND e10pro_zus_studium.ucitel = %i', $qv['ucitel'][''],
					')');
		}

		if (isset($qv['pobocka']['']) && $qv['pobocka'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (',
					'SELECT student FROM e10pro_zus_studium WHERE persons.ndx = e10pro_zus_studium.student ',
					'AND e10pro_zus_studium.misto = %i', $qv['pobocka'][''],
					')');
		}

		if (isset($qv['obor']['']) && $qv['obor'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (',
					'SELECT student FROM e10pro_zus_studium WHERE persons.ndx = e10pro_zus_studium.student ',
					'AND e10pro_zus_studium.svpObor = %i', $qv['obor'][''],
					')');
		}

		if (isset($qv['predmet']['']) && $qv['predmet'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (',
					'SELECT studium.student FROM e10pro_zus_studium AS studium',
					'LEFT JOIN e10pro_zus_studiumpre ON e10pro_zus_studiumpre.studium = studium.ndx',
					'WHERE persons.ndx = studium.student ',
					'AND e10pro_zus_studiumpre.svpPredmet = %i', $qv['predmet'][''],
					')');
		}

		// -- others
		if (isset ($qv['others']['withoutEmail']))
		{
			array_push ($q, ' AND NOT EXISTS (SELECT ndx FROM e10_base_properties ',
				'WHERE persons.ndx = e10_base_properties.recid AND tableid = %s', 'e10.persons.persons',
				' AND [group] = %s', 'contacts', ' AND [property] = %s', 'email',
				')');
		}
		if (isset ($qv['others']['withoutPID']))
		{
			array_push ($q, ' AND NOT EXISTS (SELECT ndx FROM e10_base_properties ',
				'WHERE persons.ndx = e10_base_properties.recid AND tableid = %s', 'e10.persons.persons',
				' AND [group] = %s', 'ids', ' AND [property] = %s', 'pid',
				')');
		}

		if (isset ($qv['others']['withoutStudium']))
		{
			$skolniRok = zusutils::aktualniSkolniRok();
			array_push ($q, ' AND NOT EXISTS (',
					'SELECT student FROM e10pro_zus_studium WHERE persons.ndx = e10pro_zus_studium.student ',
					' AND e10pro_zus_studium.skolniRok = %s', $skolniRok,
					' AND e10pro_zus_studium.stavHlavni != %i', 4,
					')');
		}

		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
		if ($testNewPersons)
		{
			if (isset ($qv['others']['withoutContacts']))
			{
				array_push ($q, ' AND persons.ndx IN ');
				array_push ($q, ' (select * FROM (');
				array_push ($q, ' SELECT person FROM e10_persons_personsContacts WHERE docState = 4000 GROUP BY person HAVING count(*) < 2');
				array_push ($q, ' ) AS [persWithoutContacts] )');
			}

			if (isset ($qv['others']['badContacts']))
			{
				array_push ($q, ' AND persons.ndx IN ');
				array_push ($q, ' (select * FROM (');
				array_push ($q, ' SELECT person FROM e10_persons_personsContacts WHERE flagContact = 1 ',
														'AND docState = 4000 AND contactEmail = %s', '', ' AND contactPhone = %s', '',
														'GROUP BY person HAVING count(*) > 0');
				array_push ($q, ' ) AS [persBadContacts] )');
			}

			if (isset ($qv['others']['badAddress']))
			{
				array_push ($q, ' AND persons.ndx IN ');
				array_push ($q, ' (select * FROM (');
				array_push ($q, ' SELECT person FROM e10_persons_personsContacts WHERE flagAddress = 1 AND flagMainAddress = 1 ',
														'AND docState = 4000 AND adrStreet = %s', '', ' AND adrCity = %s', '',
														'GROUP BY person HAVING count(*) > 0');
				array_push ($q, ' ) AS [persBadAddress] )');
			}

			$withoutMainAddress = isset ($qv['others']['withoutMainAddress']);
			if ($withoutMainAddress)
			{
				array_push ($q, ' AND NOT EXISTS (SELECT ndx FROM e10_persons_personsContacts WHERE persons.ndx = person ');
				array_push ($q, ' AND e10_persons_personsContacts.flagAddress = 1 AND e10_persons_personsContacts.flagMainAddress = 1');
				array_push ($q, ')');
			}
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;
		parent::selectRows2();

		// -- in classes
		$qc[] = 'SELECT studium.[student] as studentNdx, studium.[cisloStudia] as cisloStudia FROM e10pro_zus_studium as studium';
		//array_push($qc, ' LEFT JOIN school_core_classes as c ON cs.[class] = c.ndx');
		array_push($qc, ' WHERE studium.skolniRok = %i', zusutils::aktualniSkolniRok());
		array_push ($qc, " AND [stavHlavni] < %i", 4);
		array_push($qc, ' AND studium.student IN %in', $this->pks);
		array_push($qc, ' ORDER BY studium.cisloStudia');

		$rows = $this->table->db()->query ($qc);
		forEach ($rows as $r)
			$this->studia [$r ['studentNdx']][] = ['text' => $r['cisloStudia'], 'class' => 'label label-info id', 'icon' => 'personBirthDate'];
	}

	public function createToolbar ()
	{
		// Nebude vidět tlačítko Přidat, ale Wizard na generování bude fungovat
		$t = parent::createToolbar();

		/*
		$t [] = [
				'type' => 'action', 'action' => 'addwizard',
				'text' => 'Rodina', 'icon' => 'icon-plus-circle',
				'data-table' => 'e10.persons.persons', 'data-class' => 'lib.school.AddFamilyWizard',
				'data-addparams' => 'studentGroup=e10pro-zus-groups-students&parentGroup=e10pro-zus-groups-parents'

			/ *
			'type' => 'action', 'action' => 'addwizard',
			'data-table' => 'e10pro.zus.vysvedceni','data-class' => 'e10pro.zus.SchoolReportsPrintWizard',
			'text' => 'Hromadný tisk', 'icon' => 'icon-print'
			* /
		];
		*/
		return $t;
	}

	public function createToolbar_addWizard (&$toolbar, $addWizard)
	{
	}
} // class ViewStudents


/**
 * Class ViewDetailStudent
 * @package E10Pro\Zus
 */
class ViewDetailStudent extends \E10\Persons\ViewDetailPersons
{
	var $addressesAll;
	var $addresses;

	public function createDetailContent ()
	{
		$this->loadDataAddresses();
		$contentContacts = $this->contentContacts();
		$this->addContent($contentContacts);

		$this->studia ();
		$this->rozvrh ();
	}

	public function studia ()
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$stupne = $this->app()->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');
		$tableStudia = $this->app()->table('e10pro.zus.studium');

		$q [] = 'SELECT studium.*, persons.fullName as ucitelFullName, places.fullName as placeName FROM [e10pro_zus_studium] as studium ';
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON studium.ucitel = persons.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS places ON studium.misto = places.ndx ');
		array_push($q, ' WHERE student = %i', $this->item['ndx']);
		array_push($q, ' AND skolniRok >= %s', zusutils::aktualniSkolniRok());
		array_push($q, ' AND stav != 9800');
		array_push($q, ' ORDER BY skolniRok DESC, ndx DESC');

		$rows = $this->db()->query ($q);

		$studia = [];

		foreach ($rows as $item)
		{
			$s = ['info' => []];

			$docState = $tableStudia->getDocumentState ($item);
			$docStateStyle = $tableStudia->getDocumentStateInfo ($docState ['states'], $item, 'styleClass');

			$st = ['class' => 'title '.$docStateStyle, 'value' => [
					['icon' => 'personBirthDate', 'class' => 'h1', 'text' => $this->app()->cfgItem ("e10pro.zus.oddeleni.{$item ['svpOddeleni']}.nazev")],
					['text' => $item['cisloStudia'], 'docAction' => 'edit', 'table' => 'e10pro.zus.studium', 'pk'=> $item['ndx'],
					 'class' => 'pull-right', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-primary', 'icon' => 'system/actionOpen'],
					['class' => 'h2 pull-right','text' => $skolniRoky [$item['skolniRok']]['nazev']],
					['class' => 'block clearfix', 'text' => '']
				]
			];

			$svpList = $this->app()->cfgItem ("e10pro.zus.svp");
			if (count ($svpList) > 1)
				$st['value'][] = ['class' => '',
													'text' => $this->app()->cfgItem ("e10pro.zus.svp.{$item ['svp']}.nazev") . ', obor '.$this->app()->cfgItem ("e10pro.zus.obory.{$item ['svpObor']}.nazev"),
				];

			$st['value'][] = ['text' => $rocniky [$item['rocnik']]['nazev'],
												'class' => 'pull-right clear'];

			$st['value'][] = ['class' => 'block', 'text' => ''];
			$st['value'][] = ['icon' => 'iconTeachers', 'text' => $item ['ucitelFullName'], 'class' => ''];
			if ($item ['placeName'])
				$st['value'][] = ['icon' => 'system/iconMapMarker', 'text' => $item ['placeName']];

			$s['info'][] = $st;

			$s['info'][] = $this->predmetyStudia($item['ndx']);
			$s['info'][] = $this->skolneStudia($item);

			$studia[] = $s;
		}

		$this->addContent(['type' => 'tiles', 'tiles' => $studia, 'class' => 'panes']);
	}

	public function predmetyStudia ($studiumNdx)
	{
		$rows = [];
		$q =
			'SELECT predmety.nazev as predmet, ucitele.fullName as ucitel ' .
			' FROM [e10pro_zus_studiumpre] as studiumpre  '.
			' LEFT JOIN [e10_persons_persons] AS ucitele ON studiumpre.ucitel = ucitele.ndx'.
			' LEFT JOIN [e10pro_zus_predmety] AS predmety ON studiumpre.svpPredmet = predmety.ndx'.
			' WHERE studiumpre.studium = %i ORDER BY studiumpre.ndx';
		foreach ($this->db()->query($q, $studiumNdx) as $r)
			$rows[] = $r->toArray();

		$title = [['icon' => 'tables/e10pro.zus.predmety', 'text' => 'Předměty', 'class' => 'h1']];

		$vysvedceni = $this->db()->query('SELECT * FROM e10pro_zus_vysvedceni WHERE studium = %i', $studiumNdx)->fetch();
		if ($vysvedceni)
			$title[] = ['text' => 'Vysvědčení', 'docAction' => 'edit', 'table' => 'e10pro.zus.vysvedceni', 'pk'=> $vysvedceni['ndx'],
				'class' => 'pull-right', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-primary', 'icon' => 'system/actionOpen'];
		else
			$title[] = ['text' => 'Nové vysvědčení', 'docAction' => 'new', 'table' => 'e10pro.zus.vysvedceni', 'addParams' => '__studium='.$studiumNdx,
				'class' => 'pull-right', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-success', 'icon' => 'system/actionAdd'];

		if (count($rows))
		{
			return ['class' => 'info width50', 'header' => ['#' => '#', 'predmet' => '_Předměty', 'ucitel' => '_Učitel'],
				'table' => $rows, 'title' => $title, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'default fullWidth']];
		}

		return ['class' => 'info width50 e10-error', 'header' => ['predmet' => 'Předměty'],
			'table' => [['predmet' => 'není zadán žádný předmět']], 'title' => $title, 'params' => ['hideHeader' => 1, 'forceTableClass' => '']];
	}

	public function skolneStudia ($item)
	{
		$skolne = [];

		// -- 1. pololeti
		$p1 = ['p' => '1. pol.', 'fv' => '-', 'stav' => '-'];
		if ($item ['skolVyPrvniPol'])
		{
			if ($item ['skolSlPrvniPol'])
				$p1['castka'] = utils::nf ($item ['skolnePrvniPol']).' - '.utils::nf ($item ['skolSlPrvniPol']).' = '.utils::nf ($item ['skolVyPrvniPol']);
			else
				$p1['castka'] = utils::nf ($item ['skolVyPrvniPol']);

			$symbol2 = ($item['skolniRok'] - 2000) . ($item['skolniRok'] - 2000 + 1) . '1';
			$qfv[] = 'SELECT * FROM e10doc_core_heads WHERE 1';
			array_push($qfv, ' AND [docState] = 4000');
			array_push($qfv, ' AND docType = %s', 'invno', ' AND symbol1 = %s', $item['cisloStudia'], ' AND symbol2 = %s', $symbol2);
			$fvr = $this->db()->query($qfv)->fetch();

			if ($fvr)
			{
				$p1['fv'] = ['icon' => 'e10-docs-invoices-out', 'text' => $fvr['docNumber'],
										 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $fvr['ndx'],
										 'title' => 'FV ze dne '.utils::datef ($fvr['dateAccounting'], '%d').' na '.utils::nf($fvr['toPay']).',-'];
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

			$symbol2 = ($item['skolniRok'] - 2000) . ($item['skolniRok'] - 2000 + 1) . '2';
			$qfv[] = 'SELECT * FROM e10doc_core_heads WHERE 1';
			array_push($qfv, ' AND [docState] = 4000');
			array_push($qfv, ' AND docType = %s', 'invno',
											 ' AND symbol1 = %s', $item['cisloStudia'], ' AND symbol2 = %s', $symbol2);
			$fvr = $this->db()->query($qfv)->fetch();

			if ($fvr)
			{
				$p2['fv'] = ['icon' => 'e10-docs-invoices-out', 'text' => $fvr['docNumber'],
										 'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $fvr['ndx'],
										 'title' => 'FV ze dne '.utils::datef ($fvr['dateAccounting'], '%d').' na '.utils::nf($fvr['toPay']).',-'];
			}
			$stav = zusutils::uhradySkolneho($this->app(), $item['student'], $item ['skolniRok'], $item['cisloStudia'], '2', 1);
			if ($stav)
				$p2['stav'] = $stav;
		}

		// -- půjčovné
		unset($qfv);
		$p3 = ['p' => 'půjč.', 'fv' => '-', 'stav' => '-'];

		$symbol2 = ($item['skolniRok'] - 2000) . ($item['skolniRok'] - 2000 + 1) . '3';
		$qfv[] = 'SELECT * FROM e10doc_core_heads WHERE 1';
		array_push($qfv, ' AND [docState] = 4000');
		array_push($qfv, ' AND docType = %s', 'invno',
			' AND symbol1 = %s', $item['cisloStudia'], ' AND symbol2 = %s', $symbol2);
		$fvr = $this->db()->query($qfv)->fetch();

		if ($fvr)
		{
			$p3['castka'] = utils::nf ($fvr ['toPay']);
			$p3['fv'] = ['icon' => 'e10-docs-invoices-out', 'text' => $fvr['docNumber'],
				'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk'=> $fvr['ndx'],
				'title' => 'FV ze dne '.utils::datef ($fvr['dateAccounting'], '%d').' na '.utils::nf($fvr['toPay']).',-'];
		}
		$stav = zusutils::uhradySkolneho($this->app(), $item['student'], $item ['skolniRok'], $item['cisloStudia'], '3', 1);
		if ($stav)
			$p3['stav'] = $stav;

		// -- kontrola salda

		$skolne[] = $p1;
		$skolne[] = $p2;
		if ($p3['fv'] != '-' || $p3['stav'] != '-')
			$skolne[] = $p3;


		$title = [['icon' => 'system/iconMoney', 'text' => 'Školné a půjčovné', 'class' => 'h1']];
		if ($item['bezDotace'])
			$title[] = ['icon' => 'system/iconWarning', 'text' => 'bez dotace', 'class' => 'pull-right label label-info'];

		return [
			'class' => 'info width50 padd5',
			'header' => ['p' => 'Pol.', 'castka' => ' Částka', 'fv' => 'Faktura', 'stav' => 'Stav'],
			'table' => $skolne, 'title' => $title, 'params' => ['hideHeader' => 1]
		];
	}

	public function rozvrh ()
	{
		$tableRozvrh = $this->app()->table('e10pro.zus.vyukyrozvrh');
		$nazvyDnu = $tableRozvrh->columnInfoEnum ('den', 'cfgText');
		$rozvrh = [];
		$vyukyStudenta = $this->vyukyStudenta ($this->item['ndx']);

		$title = [['icon' => 'icon-clock-o', 'text' => 'Rozvrh', 'class' => 'h2']];

		if (!count($vyukyStudenta))
			return;

		$today = utils::today();

		$q[] = 'SELECT rozvrh.*, persons.fullName as personFullName, predmety.nazev as predmet, vyuky.typ as typVyuky, vyuky.rocnik as rocnik, '.
						'places.shortName as placeName, ucebny.shortName as ucebnaName, vyuky.datumZahajeni, vyuky.datumUkonceni';
		array_push($q, ' FROM e10pro_zus_vyukyrozvrh AS rozvrh');
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON rozvrh.vyuka = vyuky.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON rozvrh.ucitel = persons.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON rozvrh.predmet = predmety.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS places ON rozvrh.pobocka = places.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS ucebny ON rozvrh.ucebna = ucebny.ndx');

		array_push($q, ' WHERE rozvrh.vyuka IN %in', $vyukyStudenta);
		array_push($q, ' AND vyuky.skolniRok = %s', zusutils::aktualniSkolniRok());
		array_push($q, ' ORDER BY rozvrh.den, rozvrh.zacatek');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ikonaPredmet = ($r['typVyuky'] === 0) ? 'icon-group' : 'icon-user';

			$itm = [
				'den' => [
					['icon' => 'system/actionOpen', 'text' => '', 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk'=> $r['vyuka'],
						'type' => 'button', 'actionClass' => 'e10-off'],
					['text' => ' '.$nazvyDnu[$r['den']]]
				],
				'doba' => $r['zacatek'].' - '.$r['konec'],
				'ucitel' => $r['personFullName'],
				'predmet' => ['icon' => $ikonaPredmet, 'text' => $r['predmet']],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app(), $r['rocnik'], $r['typVyuky']),
				'pobocka' => $r['placeName'],
				'ucebna' => $r['ucebnaName']
			];

			if (!utils::dateIsBlank($r['datumUkonceni']) && $r['datumUkonceni'] < $today)
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';
			elseif (!utils::dateIsBlank($r['datumZahajeni']) && $r['datumZahajeni'] > $today)
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';

			$rozvrh[] = $itm;
		}

		if (count($rozvrh))
			$this->addContent([
				'pane' => 'e10-pane e10-pane-table',
				'header' => ['den' => 'Den', 'doba' => 'Čas', 'predmet' => 'Předmět', 'rocnik' => 'Ročník', 'ucitel' => 'Učitel', 'pobocka' => 'Pobočka', 'ucebna' => 'Učebna'],
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

	public function vyukyStudenta ($studentNdx)
	{
		$vyuky = [];

		// -- individuální
		$q[] = 'SELECT ndx FROM e10pro_zus_vyuky';
		array_push($q, 'WHERE typ = 1 AND student = %i', $studentNdx);
		$rows = $this->db()->query($q);
		foreach($rows as $r)
			if (!in_array($r['ndx'], $vyuky))
				$vyuky[] = $r['ndx'];

		// -- kolektivní
		unset ($q);
		$q[] = ' SELECT vyuka FROM e10pro_zus_vyukystudenti studenti';
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON studenti.vyuka = vyuky.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON studenti.studium = studia.ndx',
									 ' WHERE vyuky.typ = 0 AND studia.student = %i', $studentNdx);
		$rows = $this->db()->query($q);
		foreach($rows as $r)
			if (!in_array($r['vyuka'], $vyuky))
				$vyuky[] = $r['vyuka'];

		return $vyuky;
	}

	function loadDataAddresses()
	{
		$this->addresses = [];

    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $this->item['ndx']);
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

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		if ($this->app()->hasRole('zusadm'))
		{
			$toolbar [] = ['type' => 'document', 'action' => 'new', 'data-table' => 'e10pro.zus.studium',
				'data-addParams' => '__student=' . $this->item['ndx'], 'text' => 'Nové studium'];
		}

		return $toolbar;
	} // createToolbar
}


/**
 * Class ViewDetailStudentVysvedceni
 * @package E10Pro\Zus
 */
class ViewDetailStudentVysvedceni extends TableViewDetail
{
	public function createDetailCode ()
	{
		return $this->setViewer ('e10pro.zus.vysvedceni', 'e10pro.zus.WidgetVysvedceniStudenta2', array ('student' => $this->item ['ndx']));
	}
}


/**
 * Class ViewTeachers
 * @package E10Pro\Zus
 */
class ViewTeachers extends \E10\Persons\ViewPersons
{
	var $predmety;

	public function init ()
	{
		$this->setMainGroup ('e10pro-zus-groups-teachers');
		parent::init();
	}

	function decorateRow (&$item)
	{
		if (isset ($this->properties [$item ['pk']]['groups']))
			$item ['i2'] = $this->properties [$item ['pk']]['groups'];

		if (isset ($this->properties [$item ['pk']]['contacts']))
			$item ['t2'] = array_slice ($this->properties [$item ['pk']]['contacts'], 0, 2, TRUE);

		if (isset ($this->predmety[$item ['pk']]))
			$item ['t3'] = $this->predmety[$item ['pk']];

		unset($item['i1']);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		parent::selectRows2();

		// -- predmety
		$qp[] = 'SELECT links.ndx, links.linkId as linkId, links.srcRecId as srcRecId, predmety.nazev as nazevPredmetu FROM e10_base_doclinks as links ';
		array_push($qp, ' LEFT JOIN e10pro_zus_predmety as predmety ON links.dstRecId = predmety.ndx ');
		array_push($qp, ' WHERE dstTableId = %s', 'e10pro.zus.predmety', ' AND srcTableId = %s', 'e10.persons.persons', ' AND links.srcRecId IN %in', $this->pks);

		$rows = $this->table->db()->query ($qp);

		forEach ($rows as $r)
			$this->predmety [$r ['srcRecId']][] = ['text' => $r['nazevPredmetu'], 'class' => 'label label-info', 'icon' => 'tables/e10pro.zus.predmety'];
	}

	public function qryPanel (array &$q)
	{
		$qv = $this->queryValues();

		if (isset($qv['pobocka']['']) && $qv['pobocka'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (',
					'SELECT ucitel FROM e10pro_zus_studium WHERE persons.ndx = e10pro_zus_studium.ucitel ',
					'AND e10pro_zus_studium.misto = %i', $qv['pobocka'][''],
					')');
		}

		if (isset($qv['obor']['']) && $qv['obor'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (',
					'SELECT ucitel FROM e10pro_zus_studium WHERE persons.ndx = e10pro_zus_studium.ucitel ',
					'AND e10pro_zus_studium.svpObor = %i', $qv['obor'][''],
					')');
		}

		if (isset($qv['predmet']['']) && $qv['predmet'][''] != 0)
		{
			array_push ($q, ' AND EXISTS (',
					'SELECT studium.ucitel FROM e10pro_zus_studium AS studium',
					'LEFT JOIN e10pro_zus_studiumpre ON e10pro_zus_studiumpre.studium = studium.ndx',
					'WHERE persons.ndx = studium.ucitel ',
					'AND e10pro_zus_studiumpre.svpPredmet = %i', $qv['predmet'][''],
					')');
		}
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = array();

		$paramsRows = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsRows->addParam ('switch', 'query.pobocka', ['title' => 'Pobočka', 'switch' => zusutils::pobocky($this->app)]);
		$paramsRows->addParam ('switch', 'query.obor', ['title' => 'Obor', 'place' => 'panel', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
				'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->addParam ('switch', 'query.predmet', ['title' => 'Předmět', 'place' => 'panel', 'cfg' => 'e10pro.zus.predmety', 'titleKey' => 'nazev',
				'enableAll' => ['0' => ['title' => 'Vše']]]);
		$paramsRows->detectValues();
		$qry[] = ['id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat', 'params' => $paramsRows];

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewDetailTeachers
 * @package E10Pro\Zus
 */
class ViewDetailTeachers extends \E10\Persons\ViewDetailPersons
{
	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->table()->icon ($recData);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$ndx = $recData ['ndx'];
		$properties = $this->table->loadProperties ($ndx);
		$classification = \E10\Base\loadClassification ($this->app(), $this->table->tableId(), $ndx);

		$contactInfo = array ();
		if (isset ($properties [$ndx]['contacts']))
			$contactInfo = $properties [$ndx]['contacts'];

		if (count($contactInfo) !== 0)
			$hdr ['info'][] = array ('class' => 'info', 'value' => $contactInfo);

		$hdr ['info'][] = ['class' => 'title', 'value' => [['text' => $recData ['fullName']], ['text' => '#'.$recData ['id'], 'class' => 'pull-right id']]];

		$secLine = array();
		if (isset ($properties [$ndx]['groups']))
		{
			$secLine = $properties [$ndx]['groups'];
			$secLine[0]['icon'] = 'e10-persons-groups';
		}
		if (isset ($classification [$ndx]['places']))
			$secLine = array_merge ($secLine, $classification [$ndx]['places']);
		if (count($secLine) !== 0)
			$hdr ['info'][] = array ('class' => 'info', 'value' => $secLine);

		$image = UtilsBase::getAttachmentDefaultImage ($this->app(), $this->tableId(), $recData ['ndx']);
		if (isset($image ['smallImage']))
		{
			$hdr ['image'] = $image ['smallImage'];
			unset ($hdr ['icon']);
		}

		return $hdr;
	}

	public function createHeaderCode ()
	{
		$h = $this->createHeader ($this->item, 0);
		return $this->defaultHedearCode ($h);
	}

	public function createDetailCode ()
	{
		$c = '';
		$v = $this->table->app()->table ('e10pro.zus.vysvedceni')->getTableView ('e10pro.zus.WidgetVysvedceniUcitele', array ('teacher' => $this->item ['ndx']));
		//$v->inlineDetailClass = 'e10pro.zus.InlineDetailVysvedceni';

		$v->renderViewerData ('');
		$c .= $v->createViewerCode ('', TRUE);

		$this->objectData ['htmlCodeToolbarViewer'] = $v->createToolbarCode ();
		$this->objectData ['detailViewerId'] = $v->vid;

		return $c;
	}
} // class ViewDetailTeachers


/**
 * Základní detail Informací o studiu studenta
 *
 */

class ViewDetailStudentStudium extends TableViewDetail
{
	public function createDetailCode ()
	{
		return $this->setViewer ('e10pro.zus.studium', 'e10pro.zus.ViewStudentStudium', array ('student' => $this->item ['ndx']));
	}
}


/**
 * Class reportStudium
 * @package E10Pro\Zus
 */
class reportStudium extends \E10\GlobalReport
{
  function init ()
	{
		// -- toolbar
		$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);
		$this->addParam ('switch', 'pobocka', ['title' => 'Pobočka', 'switch' => zusutils::pobocky($this->app)]);
		$this->addParam ('switch', 'oddeleni', ['title' => 'Oddělení', 'cfg' => 'e10pro.zus.oddeleni', 'titleKey' => 'nazev',
																						'enableAll' => ['0' => ['title' => 'Vše']]]);
		// -- panel
		$this->addParam ('switch', 'ucitel', ['title' => 'Učitel', 'place' => 'panel', 'switch' => zusutils::ucitele($this->app)]);
		$this->addParam ('switch', 'obor', ['title' => 'Obor', 'place' => 'panel', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
																					'enableAll' => ['0' => ['title' => 'Vše']]]);
		$this->addParam ('switch', 'stupen', ['title' => 'Stupeň', 'place' => 'panel', 'cfg' => 'e10pro.zus.stupne', 'titleKey' => 'nazev',
																					'enableAll' => ['0' => ['title' => 'Vše']]]);
		$this->addParam ('switch', 'rocnik', ['title' => 'Ročník', 'place' => 'panel', 'cfg' => 'e10pro.zus.rocniky', 'titleKey' => 'nazev',
																					'enableAll' => ['0' => ['title' => 'Vše']]]);
		$this->addParam ('switch', 'predmet', ['title' => 'Předmět', 'place' => 'panel', 'cfg' => 'e10pro.zus.predmety', 'titleKey' => 'nazev',
																					'enableAll' => ['0' => ['title' => 'Vše']]]);
		$this->addParam ('switch', 'typV', ['title' => 'Typ vysv.', 'place' => 'panel', 'cfg' => 'zus.typyVysvedceni',
																				'enableAll' => ['99' => ['title' => 'Vše']]]);

		$this->addParam ('switch', 'gender', ['title' => 'Pohlaví', 'place' => 'panel', 'cfg' => 'e10.persons.gender',
																					'enableAll' => ['99' => ['title' => 'Vše']]]);
		$this->addParam ('switch', 'kontrola', ['title' => 'Kontrola', 'place' => 'panel',
																					'switch' => [
																												'0' => 'Žádná',
																												'100' => 'Studium bez předmětu',
																												'101' => 'Pohlaví nenastaveno'
																											]]);

		parent::init();

		$this->setInfo('icon', 'tables/e10pro.zus.studium');
		$this->setInfo('title', 'Přehled studií');
	}

  function createContent ()
	{
		$genderIcons = ['∅', '♂', '♀'];
		$rocniky = $this->app->cfgItem ('e10pro.zus.rocniky');

    $q [] = 'SELECT studium.*, students.fullName as studentFullName, students.gender as studentGender, teachers.fullName as teacherFullName,'.
    				' places.shortName as pobockaShortName, places.fullName as pobockaFullName,'.
            ' oddeleni.nazev as oddeleniNazev, svp.id as svpShortName, obory.nazev as oborNazev, obory.id as oborId'.
            ' FROM [e10pro_zus_studium] as studium'.
            ' LEFT JOIN e10_persons_persons AS students ON studium.student = students.ndx'.
            ' LEFT JOIN e10_persons_persons AS teachers ON studium.ucitel = teachers.ndx'.
            ' LEFT JOIN e10_base_places AS places ON studium.misto = places.ndx'.
            ' LEFT JOIN e10pro_zus_oddeleni AS oddeleni ON studium.svpOddeleni = oddeleni.ndx'.
            ' LEFT JOIN e10pro_zus_obory AS obory ON studium.svpObor = obory.ndx'.
            ' LEFT JOIN e10pro_zus_svp AS svp ON studium.svp = svp.ndx'.
            ' WHERE studium.stavHlavni = 1';

		array_push ($q, " AND studium.[skolniRok] = %s", $this->reportParams ['skolniRok']['value']);

		if ($this->reportParams ['stupen']['value'] != 0)
			array_push ($q, " AND studium.[stupen] = %i", $this->reportParams ['stupen']['value']);

    if ($this->reportParams ['pobocka']['value'] != 0)
      array_push ($q, " AND studium.[misto] = %i", $this->reportParams ['pobocka']['value']);

    if ($this->reportParams ['ucitel']['value'] != 0)
      array_push ($q, " AND studium.[ucitel] = %i", $this->reportParams ['ucitel']['value']);

		if ($this->reportParams ['obor']['value'] != 0)
			array_push ($q, " AND studium.[svpObor] = %i", $this->reportParams ['obor']['value']);

    if ($this->reportParams ['oddeleni']['value'] != 0)
      array_push ($q, " AND studium.[svpOddeleni] = %i", $this->reportParams ['oddeleni']['value']);

    if ($this->reportParams ['typV']['value'] != 99)
      array_push ($q, " AND studium.[typVysvedceni] = %i", $this->reportParams ['typV']['value']);

    if ($this->reportParams ['rocnik']['value'] != 0)
      array_push ($q, " AND studium.[rocnik] = %i", $this->reportParams ['rocnik']['value']);

		if ($this->reportParams ['predmet']['value'] != 0)
			array_push ($q, " AND EXISTS (SELECT * FROM [e10pro_zus_studiumpre] as studiumpre WHERE (studiumpre.[studium] = studium.[ndx] AND studiumpre.[svpPredmet] = %i))", $this->reportParams ['predmet']['value']);

		if ($this->reportParams ['gender']['value'] != 99)
			array_push ($q, " AND students.[gender] = %i", $this->reportParams ['gender']['value']);

		if ($this->reportParams ['kontrola']['value'] == 100)
			array_push ($q, " AND NOT EXISTS (SELECT * FROM [e10pro_zus_studiumpre] as studiumpre WHERE (studiumpre.[studium] = studium.[ndx]))");
		if ($this->reportParams ['kontrola']['value'] == 101)
			array_push ($q, " AND students.[gender] = %i", 0);

    array_push ($q, " ORDER BY students.fullName, studium.cisloStudia");

    $rows = $this->app->db()->query ($q);

		$data = array ();

		forEach ($rows as $r)
		{
      $data[] = [
				'student' => ['icontxt' => $genderIcons[$r['studentGender']], 'text'=> $r['studentFullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r['student']],
				'obor' => $r['oborId'],
				'oddeleni' => $r['oddeleniNazev'],
				'docNumber' => array ('text'=> $r['cisloStudia'], 'docAction' => 'edit', 'table' => 'e10pro.zus.studium', 'pk'=> $r['ndx']),
				'rocnik' => $rocniky[$r['rocnik']]['zkratka'],
				'pobocka' => $r['pobockaShortName'],
				'ucitel' => array ('text'=> $r['teacherFullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r['ucitel'])
			];
    }

		$h = ['#' => '#', 'student' => 'Student', 'docNumber' => ' Studium č.', 'obor' => 'Obor', 'oddeleni' => 'Studijní zaměření',
					'rocnik' => ' Ročník', 'pobocka' => 'Pobočka', 'ucitel' => 'Učitel'];

		// -- params
		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		if ($this->reportParams ['pobocka']['value'] != 0)
		{
			$this->setInfo('param', $this->reportParams ['pobocka']['title'], $this->reportParams ['pobocka']['activeTitle']);
			unset ($h['pobocka']);
		}

		if ($this->reportParams ['oddeleni']['value'] != 0)
		{
			$this->setInfo('param', $this->reportParams ['oddeleni']['title'], $this->reportParams ['oddeleni']['activeTitle']);
			unset ($h['oddeleni']);
		}

		if ($this->reportParams ['predmet']['value'] != 0)
		{
			$this->setInfo('param', $this->reportParams ['predmet']['title'], $this->reportParams ['predmet']['activeTitle']);
			unset ($h['predmet']);
		}

		if ($this->reportParams ['ucitel']['value'] != 0)
		{
			$this->setInfo('param', $this->reportParams ['ucitel']['title'], $this->reportParams ['ucitel']['activeTitle']);
			unset ($h['ucitel']);
		}

		if ($this->reportParams ['obor']['value'] != 0)
		{
			$this->setInfo('param', $this->reportParams ['obor']['title'], $this->reportParams ['obor']['activeTitle']);
			unset ($h['obor']);
		}

		if ($this->reportParams ['stupen']['value'] != 0)
			$this->setInfo('param', $this->reportParams ['stupen']['title'], $this->reportParams ['stupen']['activeTitle']);
		if ($this->reportParams ['rocnik']['value'] != 0)
			$this->setInfo('param', $this->reportParams ['rocnik']['title'], $this->reportParams ['rocnik']['activeTitle']);
		if ($this->reportParams ['stupen']['value'] != 0 && $this->reportParams ['rocnik']['value'] != 0)
			unset ($h['rocnik']);

		if ($this->reportParams ['typV']['value'] != 99)
			$this->setInfo('param', $this->reportParams ['typV']['title'], $this->reportParams ['typV']['activeTitle']);

		if ($this->reportParams ['gender']['value'] != 99)
			$this->setInfo('param', $this->reportParams ['gender']['title'], $this->reportParams ['gender']['activeTitle']);

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);
  }
} // class reportStudium


/**
 * Class reportTeachPlan
 * @package E10Pro\Zus
 */
class reportTeachPlan extends \E10\GlobalReport
{
	function init ()
	{
		$this->addParam ('switch', 'eduprogram', ['title' => 'Vzdělávací program', 'cfg' => 'e10pro.zus.svp', 'titleKey' => 'nazev']);
		$this->addParam ('switch', 'obor', ['title' => 'Obor', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);
		$this->addParam ('switch', 'oddeleni', ['title' => 'Oddělení', 'cfg' => 'e10pro.zus.oddeleni', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);

		parent::init();

		$this->setInfo('icon', 'reportStudyPlan');
		$this->setInfo('title', 'Učební plán');

		$this->setInfo('param', $this->reportParams ['eduprogram']['title'], $this->reportParams ['eduprogram']['activeTitle']);
	}

	function createContent ()
	{
		$q [] = 'SELECT [rows].*, subjects.nazev as subjectFullName,';
		$q [] = ' heads.year as yearNdx, years.zkratka as yearShortcut,';
		$q [] = ' heads.svpOddeleni as oddNdx, oddeleni.nazev as oddNazev, oddeleni.pos as oddPos, oddeleni.id as oddId';
		array_push($q, ' FROM e10pro_zus_teachplanrows as [rows]');
		array_push($q, ' LEFT JOIN e10pro_zus_teachplanheads as heads ON [rows].plan = heads.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_predmety as subjects ON [rows].subject = subjects.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_oddeleni AS oddeleni ON heads.svpOddeleni = oddeleni.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_rocniky as years ON heads.year = years.ndx');
		array_push($q, ' WHERE heads.eduprogram = %i', $this->reportParams ['eduprogram']['value']);
		array_push($q, ' AND heads.docState = %i', 4000);

		if ($this->reportParams ['obor']['value'] != 0)
			array_push ($q, " AND heads.[svpObor] = %i", $this->reportParams ['obor']['value']);
		if ($this->reportParams ['oddeleni']['value'] != 0)
			array_push ($q, " AND heads.[svpOddeleni] = %i", $this->reportParams ['oddeleni']['value']);

		array_push($q, ' ORDER BY [rows].povinnost, oddeleni.[pos], subjects.[pos], years.[poradi], ndx');

		$rows = $this->app->db()->query ($q);

		$columns = [];
		$years = [];
		$oddeleni = [];
		$data = [];
		$povinnost = $this->app()->cfgItem ('zus.povinnostPredmetu');

		forEach ($rows as $r)
		{
			$ido = $r ['oddNdx'];
			if (!isset($oddeleni [$ido]))
			{
				$oddeleni [$ido] = $r['oddId'] . "  " .  $r['oddNazev'];
				$columns [$ido] = ['subjectName' => 'Předmět', 'subjectShortcut' => ''];
			}
			$subjectId = 'P' . $r['povinnost'] . 'S'.$r['subject'];
			if (!isset ($data [$ido][$subjectId]))
				$data [$ido][$subjectId] = ['subjectName' => $r['subjectFullName'], 'subjectShortcut' => $povinnost[$r['povinnost']]['nazev'], '_options' => ['cellClasses' => ['subjectName' => 'width30']]];

			$yearId = 'Y'.$r['yearNdx'];

			$data [$ido][$subjectId][$yearId] = $r['hours'];
			if (!isset ($columns [$ido][$yearId]))
			{
				$columns [$ido][$yearId] = '+'.$r['yearShortcut'];
				$years [$ido][$yearId] = $r['yearShortcut'];
			}
		}

		forEach ($oddeleni as $ido => $o)
		{
			$firstYearId = key($years[$ido]);
			$h1 = [
				'subjectName' => 'Předmět', $firstYearId => 'Ročník'
				, '_options' => ['colSpan' => ['subjectName' => 2, $firstYearId => count($years[$ido])], 'rowSpan' => ['subjectName' => 2], 'cellClasses' => [$firstYearId => 'center', 'subjectName' => 'width30']]
			];
			$h2 = [
					'subjectName' => 'Předmět'
					, '_options' => ['colSpan' => ['subjectName' => 2]]
				] + $years[$ido];

			$this->addContent(['type' => 'table', 'header' => $columns[$ido], 'table' => $data[$ido], 'params' => ['header' => [$h1, $h2]], 'title' => $oddeleni[$ido]]);
		}
	}
} // class reportTeachPlan


/**
 * Class reportSkolne
 * @package E10Pro\Zus
 */
 // class reportSkolne


/**
 * Class reportVykazZus
 * @package E10Pro\Zus
 */
class reportVykazZus extends \E10\GlobalReport
{
	function init()
	{
		// -- toolbar
		$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		parent::init();

		$this->setInfo('icon', 'system/iconFile');
		$this->setInfo('title', 'VÝKAZ o základní umělecké škole');
	}

	function createContent ()
	{
		$this->buffer = array();
		$this->prepareData(0);
		$this->prepareData(1);

		$this->h0a = ['a' => '', 'b' => 'Čislo řádku', 'c' => ' Počet celkem', 'd' => ' z toho dívky', 'e' => 'ze sl. 2 v oboru',
			'_options' => ['colSpan' => ['e' => 5], 'rowSpan' => ['a' => 3, 'b' => 3, 'c' => 3, 'd' => 3],
										'cellClasses' => ['b' => 'center', 'c' => 'center', 'd' => 'center', 'e' => 'center']]
		];

		$this->h0b = ['a' => '', 'b' => '', 'c' => '', 'd' => '', 'e' => ' tanečním', 'f' => ' výtvarném', 'g' => ' literárně dramat.', 'h' => 'hudebním s výukou',
			'_options' => ['colSpan' => ['h' => 2], 'rowSpan' => ['e' => 2, 'f' => 2, 'g' => 2],
										'cellClasses' => ['e' => 'center', 'f' => 'center', 'g' => 'center', 'h' => 'center']]
		];

		$this->h1 = ['a' => '', 'b' => '', 'c' => ' ', 'd' => ' ', 'e' => ' ',
			'f' => ' ', 'g' => ' ', 'h' => ' individuální skupinovou', 'i' => ' kolektivní',
			'_options' => ['cellClasses' => ['a' => 'center', 'b' => 'center', 'c' => 'center', 'd' => 'center',
				'e' => 'center', 'f' => 'center', 'g' => 'center', 'h' => 'center', 'i' => 'center']
			]
		];

		$this->h2 = ['a' => 'a', 'b' => 'b', 'c' => '2', 'd' => '3', 'e' => '4',
			'f' => '5', 'g' => '6', 'h' => '7', 'i' => '8',
			'_options' => ['cellClasses' => ['a' => 'center', 'b' => 'center', 'c' => 'center', 'd' => 'center',
										'e' => 'center', 'f' => 'center', 'g' => 'center', 'h' => 'center', 'i' => 'center']
			]
		];

		$stupne = $this->app->cfgItem ('e10pro.zus.stupne');
		$this->stupneIds = array ();
		foreach ($stupne  as $s)
			$this->stupneIds[] = $s['id'];

		$this->createContent_StudentiCelkem($this->stupneIds[2]);
		$this->createContent_StudentiZakladni (1, $this->stupneIds[0], 201, 'II. Žáci přípravného a základního studia - I. stupeň');
		$this->createContent_StudentiZakladni (2, $this->stupneIds[1], 401, 'IV. Žáci přípravného a základního studia - II. stupeň');
		if (isset($this->stupneIds[2]))
			$this->createContent_StudentiZakladni (3, $this->stupneIds[2], 601, 'VI. Studium pro dospělé - SPD');
		unset($this->buffer);

		// Hudební nástroje...
		$this->buffer = array();
		$this->prepareData_HudebniNastroje_UP();
		$this->prepareData_HudebniNastroje_SVP();
		$this->createContent_HudebniNastroje();
		unset($this->buffer);
	}

	function prepareData ($key)
	{
		$q [] = 'SELECT COUNT(*) as pocet, studium.stupen as stupen, obory.id as oborId, students.gender as studentGender, studium.rocnik as rocnik'.
			' FROM [e10pro_zus_studium] as studium'.
			' LEFT JOIN e10_persons_persons AS students ON studium.student = students.ndx'.
			' LEFT JOIN e10pro_zus_obory AS obory ON studium.svpObor = obory.ndx';

		array_push ($q, " WHERE studium.stavHlavni = 1");

		if ($key == 0)
			array_push ($q, " AND studium.[skolniRok] = %s", $this->reportParams ['skolniRok']['value']);
		if ($key == 1)
			array_push ($q, " AND studium.typVysvedceni = 1 AND studium.[skolniRok] = %s", strval($this->reportParams ['skolniRok']['value']-1));

		array_push ($q, " GROUP BY studium.stupen, students.gender, obory.id, studium.rocnik");

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
			$newrow = array('stupen' => $r['stupen'], 'obor' => $r['oborId'], 'pohlavi' => $r['studentGender'],
											'rocnik' => $r['rocnik'], 'pocet' => $r['pocet']);
			$this->buffer[$key][] = $newrow;
		}
	}

	function prepareData_HudebniNastroje_UP ()
	{
		$stupne = $this->app->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app->cfgItem ('e10pro.zus.rocniky');

		$q [] = 'SELECT COUNT(*) as pocet, studium.stupen, studium.rocnik, oddeleni.nazev as oddeleniNazev, obory.id as oborId,'.
			' predmet.nazev as predmetNazev'.
			' FROM [e10pro_zus_studiumpre] as studiumpre'.
			' LEFT JOIN e10pro_zus_studium AS studium ON studiumpre.studium = studium.ndx'.
			' LEFT JOIN e10pro_zus_predmety AS predmet ON studiumpre.svpPredmet = predmet.ndx'.
			' LEFT JOIN e10pro_zus_oddeleni AS oddeleni ON studium.svpOddeleni = oddeleni.ndx'.
			' LEFT JOIN e10pro_zus_obory AS obory ON studium.svpObor = obory.ndx';

		array_push ($q, " WHERE studium.stavHlavni = 1 AND obory.id = %s", 'HO');

		array_push ($q, " AND studium.[skolniRok] = %s", $this->reportParams ['skolniRok']['value']);

		array_push ($q, " AND studium.svp = 1 AND predmet.typVyuky = 1");

		array_push ($q, " GROUP BY oddeleni.nazev, predmet.nazev, studium.stupen, studium.rocnik, studium.rocnik");
		array_push ($q, " ORDER BY predmet.nazev");

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
			$roc = $rocniky[$r['rocnik']];
			if (!isset($this->buffer[$r['predmetNazev']]))
			{
				$newrow = array('stupen' => $r['stupen'], 'nastroj' => $r['predmetNazev'],
					'rocnik' => $r['rocnik'], 'PP' => 0, '1S' => 0, '2S' => 0, 'RO' => 0, 'DO' => 0);
				$this->buffer[$r['predmetNazev']] = $newrow;
			}

			if ($roc['typVysvedceni'] == 2)
			{
				$this->buffer[$r['predmetNazev']]['PP'] += $r['pocet'];
			}
			else
			{
				if ($roc['stupen'] == $this->stupneIds[0])
					$this->buffer[$r['predmetNazev']]['1S'] += $r['pocet'];
				if ($roc['stupen'] == $this->stupneIds[1])
					$this->buffer[$r['predmetNazev']]['2S'] += $r['pocet'];
				if ($roc['stupen'] == $this->stupneIds[2])
					$this->buffer[$r['predmetNazev']]['DO'] += $r['pocet'];
			}
		}
	}

	function prepareData_HudebniNastroje_SVP ()
	{
		$rocniky = $this->app->cfgItem ('e10pro.zus.rocniky');

		$q [] = 'SELECT COUNT(*) as pocet, studium.stupen, studium.rocnik, oddeleni.nazev as oddeleniNazev, obory.id as oborId'.
			' FROM [e10pro_zus_studium] as studium'.
			' LEFT JOIN e10pro_zus_oddeleni AS oddeleni ON studium.svpOddeleni = oddeleni.ndx'.
			' LEFT JOIN e10pro_zus_obory AS obory ON studium.svpObor = obory.ndx';

		array_push ($q, " WHERE studium.stavHlavni = 1 AND obory.id = %s", 'HO');

		array_push ($q, " AND studium.[skolniRok] = %s", $this->reportParams ['skolniRok']['value']);

		array_push ($q, " AND studium.svp = 2");

		array_push ($q, " GROUP BY oddeleni.nazev, studium.stupen, studium.rocnik, studium.rocnik");
		array_push ($q, " ORDER BY oddeleni.nazev");

		$rows = $this->app->db()->query ($q);

		forEach ($rows as $r)
		{
			$roc = $rocniky[$r['rocnik']];
			if (!isset($this->buffer[$r['oddeleniNazev']]))
			{
				$newrow = array('stupen' => $r['stupen'], 'nastroj' => $r['oddeleniNazev'],
					'rocnik' => $r['rocnik'], 'PP' => 0, '1S' => 0, '2S' => 0, 'RO' => 0, 'DO' => 0);
				$this->buffer[$r['oddeleniNazev']] = $newrow;
			}

			if ($roc['typVysvedceni'] == 2)
			{
				$this->buffer[$r['oddeleniNazev']]['PP'] += $r['pocet'];
			}
			else
			{
				if ($roc['stupen'] == $this->stupneIds[0])
					$this->buffer[$r['oddeleniNazev']]['1S'] += $r['pocet'];
				if ($roc['stupen'] == $this->stupneIds[1])
					$this->buffer[$r['oddeleniNazev']]['2S'] += $r['pocet'];
				if ($roc['stupen'] == $this->stupneIds[2])
					$this->buffer[$r['oddeleniNazev']]['DO'] += $r['pocet'];
			}
		}
	}

	function createContent_StudentiCelkem ($stupenSPD)
	{
		$pocetZaku = array();
		$pocetZaku[0] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);
		$pocetZaku[1] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);

		forEach ($this->buffer[0] as $r)
		{
			if ((isset($stupenSPD)) && ($r['stupen'] == $stupenSPD))
				continue;
			$pocetZaku [0]['XX'] += $r['pocet'];
			$pocetZaku [0][$r['obor']] += $r['pocet'];
			if ($r['pohlavi'] == 2)
			{
				$pocetZaku [1]['XX'] += $r['pocet'];
				$pocetZaku [1][$r['obor']] += $r['pocet'];
			}
		}

		$newitem = ['a' => 'Žáci celkem', 'b' => '0101', 'c' => $pocetZaku[0]['XX'], 'd' => 'X', 'e' => $pocetZaku[0]['TA'],
			'f' => $pocetZaku[0]['VO'], 'g' => $pocetZaku[0]['LDO'], 'h' => $pocetZaku[0]['HO'], 'i' => '',
			'_options' => ['cellClasses' => ['b' => 'center']]
		];
		$data[] = $newitem;

		$newitem = ['a' => 'z toho dívky', 'b' => '0102', 'c' => $pocetZaku[1]['XX'], 'd' => 'X', 'e' => $pocetZaku[1]['TA'],
			'f' => $pocetZaku[1]['VO'], 'g' => $pocetZaku[1]['LDO'], 'h' => $pocetZaku[1]['HO'], 'i' => '',
			'_options' => ['cellClasses' => ['b' => 'center']]
		];
		$data[] = $newitem;

		$newitem = ['a' => 'cizinci', 'b' => '0102a', 'c' => '', 'd' => '', 'e' => '',
			'f' => '', 'g' => '', 'h' => '', 'i' => '',
			'_options' => ['cellClasses' => ['b' => 'center']]
		];
		$data[] = $newitem;

		$newitem = ['a' => 'z toho ze zemí EU', 'b' => '0102b', 'c' => '', 'd' => '', 'e' => '',
			'f' => '', 'g' => '', 'h' => '', 'i' => '',
			'_options' => ['cellClasses' => ['b' => 'center']]
		];
		$data[] = $newitem;

		// absolventi...
		unset($pocetZaku[0]);
		unset($pocetZaku[1]);

		$pocetZaku[0] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);
		$pocetZaku[1] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);

		forEach ($this->buffer[1] as $r)
		{
			if ((isset($stupenSPD)) && ($r['stupen'] == $stupenSPD))
				continue;
			$pocetZaku [0]['XX'] += $r['pocet'];
			$pocetZaku [0][$r['obor']] += $r['pocet'];
			if ($r['pohlavi'] == 2)
			{
				$pocetZaku [1]['XX'] += $r['pocet'];
				$pocetZaku [1][$r['obor']] += $r['pocet'];
			}
		}
		$newitem = ['a' => 'Absolventi za minulý školní rok', 'b' => '0103', 'c' => $pocetZaku[0]['XX'], 'd' => $pocetZaku[1]['XX'],
			'e' => $pocetZaku[0]['TA'], 'f' => $pocetZaku[0]['VO'], 'g' => $pocetZaku[0]['LDO'], 'h' => $pocetZaku[0]['HO'], 'i' => '',
			'_options' => ['cellClasses' => ['b' => 'center']]
		];
		$data[] = $newitem;

		$this->addContent (['type' => 'table', 'header' => $this->h1, 'table' => $data, 'params' => ['header' => [$this->h0a, $this->h0b, $this->h1, $this->h2]],
			'title' => 'I. Žáci na základních uměleckých školách celkem']);
	}

	function createContent_StudentiZakladni ($stupen, $idStupen, $cisloRadku, $title)
	{
		$rocniky = $this->app->cfgItem ('e10pro.zus.rocniky');

		$pocetZaku = array();
		$celkem = array("XX" => 0, "XZ" => 0,  'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);

		$data = array();
		$i = 0;
		foreach ($rocniky as $rok)
		{
			if ($idStupen != $rok['stupen'])
				continue;
			$idr = $rok['id'];
			$pocetZaku[$idr][0] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);
			$pocetZaku[$idr][1] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);

			forEach ($this->buffer[0] as $r)
			{
				if ($r['rocnik'] != $idr)
					continue;
				$pocetZaku [$idr][0]['XX'] += $r['pocet'];
				$pocetZaku [$idr][0][$r['obor']] += $r['pocet'];
				if ($r['pohlavi'] == 2)
				{
					$pocetZaku [$idr][1]['XX'] += $r['pocet'];
					$pocetZaku [$idr][1][$r['obor']] += $r['pocet'];
				}
			}

			$newitem = ['a' => $rok['nazev'], 'b' => '0' . strval($cisloRadku + $i++), 'c' => $pocetZaku[$idr][0]['XX'],
				'd' => $pocetZaku[$idr][1]['XX'], 'e' => $pocetZaku[$idr][0]['TA'], 'f' => $pocetZaku[$idr][0]['VO'],
				'g' => $pocetZaku[$idr][0]['LDO'], 'h' => $pocetZaku[$idr][0]['HO'], 'i' => '',
				'_options' => ['cellClasses' => ['b' => 'center']]
			];
			$data[] = $newitem;

			$celkem['XX'] += $pocetZaku[$idr][0]['XX'];
			$celkem['XZ'] += $pocetZaku[$idr][1]['XX'];
			$celkem['TA'] += $pocetZaku[$idr][0]['TA'];
			$celkem['VO'] += $pocetZaku[$idr][0]['VO'];
			$celkem['LDO'] += $pocetZaku[$idr][0]['LDO'];
			$celkem['HO'] += $pocetZaku[$idr][0]['HO'];
		}

		$newitem = ['a' => 'Celkem (ř. 0' . strval($cisloRadku) . ' až 0'. strval($cisloRadku + $i - 1) . ')',
			'b' => '0' . strval($cisloRadku + $i++), 'c' => $celkem['XX'], 'd' => $celkem['XZ'], 'e' => $celkem['TA'],
			'f' => $celkem['VO'], 'g' => $celkem['LDO'], 'h' => $celkem['HO'], 'i' => '',
			'_options' => ['cellClasses' => ['b' => 'center']]
		];
		$data[] = $newitem;

		// absolventi...
		unset($pocetZaku);
		$pocetZaku = array();
		$pocetZaku[0] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);
		$pocetZaku[1] = array("XX" => 0, 'TA' => 0, 'VO' => 0, 'LDO' => 0, 'HO' => 0);

		forEach ($this->buffer[1] as $r)
		{
			if ($idStupen != $r['stupen'])
				continue;
				$pocetZaku [0]['XX'] += $r['pocet'];
				$pocetZaku [0][$r['obor']] += $r['pocet'];
				if ($r['pohlavi'] == 2) {
					$pocetZaku [1]['XX'] += $r['pocet'];
					$pocetZaku [1][$r['obor']] += $r['pocet'];
				}
		}

		$newitem = ['a' => 'Absolventi za minulý školní rok', 'b' => '0'. strval($cisloRadku + $i), 'c' => $pocetZaku[0]['XX'],
			'd' => $pocetZaku[1]['XX'], 'e' => $pocetZaku[0]['TA'], 'f' => $pocetZaku[0]['VO'], 'g' => $pocetZaku[0]['LDO'],
			'h' => $pocetZaku[0]['HO'], 'i' => '',
			'_options' => ['cellClasses' => ['b' => 'center']]
		];
		$data[] = $newitem;

		$this->addContent(['type' => 'table', 'header' => $this->h1, 'table' => $data, 'params' => ['header' => [$this->h0a, $this->h0b, $this->h1, $this->h2]],
			'title' => $title]);
	}

	function createContent_HudebniNastroje ()
	{
		$hn1 = ['a' => 'Hudební nástroj', 'b' => '', 'c' => 'Číslo řádku', 'd' => 'Počet žáků ve studiu', 'h' => ' Počet studujících dospělých',
			'_options' => ['colSpan' => ['a' => 2, 'd' => 4], 'rowSpan' => ['a' => 2, 'b' => 2,  'c' => 3, 'h' => 3],
				'cellClasses' => ['c' => 'center', 'd' => 'center', 'h' => 'center']]
		];

		$hn2 = ['d' => ' přípravném', 'e' => 'základním', 'f' => '',
			'g' => ' rozšířeném', 'h' => '',
			'_options' => ['colSpan' => ['e' => 2], 'rowSpan' => ['d' => 2, 'g' => 2],
				'cellClasses' => ['d' => 'center', 'e' => 'center', 'g' => 'center']]
		];

		$hn3 = ['a' => 'kód', 'b' => 'název', 'c' => '', 'd' => ' ', 'e' => ' I. stupeň',
			'f' => ' II. stupeň', 'g' => ' ', 'h' => ' ',
			'_options' => ['cellClasses' => ['e' => 'center', 'f' => 'center']]
		];

		$hn4 = ['a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => '2', 'e' => '3',
			'f' => '4', 'g' => '5', 'h' => '7',
			'_options' => ['cellClasses' => ['a' => 'center', 'b' => 'center', 'c' => 'center', 'd' => 'center',
				'e' => 'center', 'f' => 'center', 'g' => 'center', 'h' => 'center']
			]
		];

		$cisloradku = 1001;
		$celkem = array ('PP' => 0, '1S' => 0, '2S' => 0, 'RO' => 0, 'DO' => 0);
		$data = array();
		forEach ($this->buffer as $r)
		{
			$newitem = ['a' => '', 'b' => $r['nastroj'], 'c' => strval($cisloradku++),
				'd' => $r['PP'], 'e' => $r['1S'], 'f' => $r['2S'], 'g' => $r['RO'], 'h' => $r['DO'],
				'_options' => ['cellClasses' => ['c' => 'center']]
			];
			$data[] = $newitem;
			$celkem['PP'] += $r['PP'];
			$celkem['1S'] += $r['1S'];
			$celkem['2S'] += $r['2S'];
			$celkem['RO'] += $r['RO'];
			$celkem['DO'] += $r['DO'];
		}

		$newitem = ['a' => '', 'b' => 'Celkem', 'c' => strval($cisloradku), 'd' => $celkem['PP'], 'e' => $celkem['1S'],
			'f' => $celkem['2S'], 'g' => $celkem['RO'], 'h' => $celkem['DO'],
			'_options' => ['cellClasses' => ['c' => 'center']]
		];
		$data[] = $newitem;

		$this->addContent (['type' => 'table', 'header' => $hn3, 'table' => $data, 'params' => ['header' => [$hn1, $hn2, $hn3, $hn4]],
			'title' => 'X. Žáci hudebního oboru podle hudebních nástrojů']);
	}
}	// class reportVykazZus


/**
 * GenerovaniVysvedceniEngine
 *
 */
class GenerovaniVysvedceniEngine extends \E10\Utility
{
	var $schoolYear;
	var $teacher;

	function tvorbaVysvedceni ()
	{
		$q [] = 'SELECT studium.*, student.fullName as studentFullName  FROM [e10pro_zus_studium] AS studium';
		$q [] = ' LEFT JOIN e10_persons_persons AS student ON studium.student = student.ndx ';
		$q [] = ' WHERE 1';
		array_push ($q, ' AND [smazano] = %i', 0);
		array_push ($q, ' AND [stavHlavni] < %i', 4);
		array_push ($q, ' AND [skolniRok] = %s', $this->schoolYear);
		if ($this->teacher > 0)
			array_push($q, ' AND studium.[ucitel] = %i', $this->teacher);
		array_push ($q, ' ORDER BY [ndx]');


		$rows = $this->db()->query ($q)->fetchAll ();
		forEach ($rows as $row)
		{
			$qs[] = "SELECT * FROM [e10pro_zus_vysvedceni] WHERE ";
			array_push($qs, '[student] = %i', $row['student'],
				' AND [stavHlavni] < %i', 4,
				' AND [skolniRok] = %s', $row['skolniRok'],
				' AND [rocnik] = %i', $row['rocnik'],
				' AND [svpOddeleni] = %i', $row['svpOddeleni'],
				' AND [studium] = %i', $row['ndx']
			);
			//array_push($qs, ' ORDER BY [ndx]');
			$rv = $this->db()->query ($qs)->fetch();
			unset ($qs);

			if ($rv)
				continue; // vysvědčení už existuje...

			$rc = '';
			$dn = '';

			$this->db()->query ("insert into e10pro_zus_vysvedceni", array ('studium' => $row ['ndx'],
				'student' => $row ['student'], 'ucitel' => $row ['ucitel'],
				'jmeno' => $row ['studentFullName'],
				'typVysvedceni' => $row ['typVysvedceni'], 'skolniRok' => $row ['skolniRok'], 'poradoveCislo' => $row ['cisloStudia'],
				'svp' => $row ['svp'], 'svpObor' => $row ['svpObor'], 'svpOddeleni' => $row ['svpOddeleni'],
				'rocnik' => $row ['rocnik'], 'stupen' => $row ['stupen'], 'urovenStudia' => $row ['urovenStudia'],
				'stavHlavni' => 0, 'stav' => 1000,
				'rodneCislo' => $rc, 'datumNarozeni' => $dn, 'statniObcanstvi' => 'cz'
			));
			$vysvedceniNdx = $this->db()->getInsertId ();

			$rows2 = $this->db()->query ("SELECT *  FROM [e10pro_zus_studiumpre] where [studium] = %i ORDER BY [ndx]", $row ['ndx'])->fetchAll ();
			forEach ($rows2 as $row2)
			{
				$this->db()->query ("insert into e10pro_zus_znamky", array ('vysvedceni' => $vysvedceniNdx, 'svpPredmet' => $row2 ['svpPredmet'], 'znamka1p' => 0, 'znamka2p' => 0));
			}
		}
	} // tvorbaVysvedceni

	function setParams($schY, $t)
	{
		$this->schoolYear = $schY;
		$this->teacher = $t;
	}

	function run()
	{
		$this->tvorbaVysvedceni();
	}
} // class GenerovaniVysvedceniEngine

/**
 * GenerovaniVysvedceniWizard
 *
 */
class GenerovaniVysvedceniWizard extends Wizard
{
	public function doStep()
	{
		if ($this->pageNumber == 1) {
			$this->doIt();
		}
	}

	public function renderForm()
	{
		switch ($this->pageNumber) {
			case 0:
				$this->renderFormWelcome();
				break;
			case 1:
				$this->renderFormDone();
				break;
		}
	}

	public function renderFormWelcome()
	{
		$this->recData['focusedDocNdx'] = $this->focusedPK;

		$this->setFlag('formStyle', 'e10-formStyleSimple');

		$this->openForm();
		$this->addInput('focusedDocNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		// školní rok
		$this->recData ['schoolYear'] = zusutils::aktualniSkolniRok();
		$this->addInputEnum2 ('schoolYear', 'Školní rok:', zusutils::aktivniSkolniRoky($this->app), self::INPUT_STYLE_OPTION);
		// učitel
		$inputOptions = 0;
		if (!$this->app()->hasRole ('zusadm') && !$this->app()->hasRole ('all'))
		{
			$this->recData ['teacher'] = intval($this->app()->user()->data('id'));
			$inputOptions |= TableForm::coReadOnly;
		}
		else
			$this->recData ['teacher'] = 0;

		$this->addInputEnum2 ('teacher', 'Učitel:', zusutils::ucitele($this->app), self::INPUT_STYLE_OPTION, $inputOptions);
		$this->closeForm();
	}

	public function doIt()
	{
		$eng = new GenerovaniVysvedceniEngine ($this->app());
		$eng->setParams($this->recData['schoolYear'], $this->recData ['teacher']);
		$eng->run();

		$this->stepResult ['close'] = 1;
	}
} // class GenerovaniVysvedceniWizard


/**
 * GenerovaniFakturSkolneEngine
 *
 */
class GenerovaniFakturSkolneEngine extends \E10\Utility
{
	var $schoolYear;
	var $teacher;
	var $pololeti;

	var $invHead = [];
	var $invRows = [];

	var $periodBegin;
	var $periodEnd;
	var $dateIssue;
	var $dateDue;
	var $aktSkolniRok;

	function createHead ($row, $pololeti)
	{
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->invHead = ['docType' => 'invno'];
		$tableDocs->checkNewRec($this->invHead);

		$this->invHead ['docState'] = 4000;
		$this->invHead ['docStateMain'] = 2;
		$this->invHead ['docKind'] = 2;

		$this->invHead ['docType'] = 'invno';
		$this->invHead ['dbCounter'] = $this->app->cfgItem('options.e10-pro-zus.dbCounterInvoicesFeeSchool');
		$this->invHead ['datePeriodBegin'] = $this->periodBegin;
		$this->invHead ['datePeriodEnd'] = $this->periodEnd;

		if ($row['platce'])
			$this->invHead ['person'] = $row['platce'];
		else
			$this->invHead ['person'] = $row['student'];
		$this->invHead ['symbol1'] = $row['cisloStudia'];
		$this->invHead ['centre'] = $row['pobocka'];

		$oddeleni = $this->app->cfgItem ("e10pro.zus.oddeleni.{$row ['svpOddeleni']}.nazev");

		$nextYear = $this->aktSkolniRok;
		$nextYear++;

		$specSymb = ($nextYear - 2001) * 1000 + ($nextYear - 2000) * 10 + $pololeti;
		$this->invHead ['symbol2'] = $specSymb;

		$this->invHead ['title'] = 'Školné ' . $pololeti . '.pololetí ' . $this->aktSkolniRok . '/'. $nextYear . ' - odd. ' . $oddeleni;
		if ($row['platce'])
		{
			$personRecData = $this->app()->loadItem($row['student'], 'e10.persons.persons');
			if ($personRecData)
				$this->invHead ['title'] .= ' ('.$personRecData['fullName'].')';
		}

		$this->invHead ['dateIssue'] = $this->dateIssue;
		$this->invHead ['dateTax'] = $this->periodBegin;
		$this->invHead ['dateDue'] = $this->dateDue;
		$this->invHead ['dateAccounting'] = $this->periodBegin;

		$this->invHead ['paymentMethod'] = '0';
		$this->invHead ['roundMethod'] = intval($this->app->cfgItem ('options.e10doc-sale.roundInvoice', 0));
		$this->invHead ['author'] = intval($this->app->cfgItem ('options.e10doc-sale.author', 0));

		$this->invRows = [];
	}

	function createRow ($row, $pololeti)
	{
		$tableRows = new \E10Doc\Core\TableRows ($this->app);
		$r = array ();
		$tableRows->checkNewRec($r);

		$oddeleni = $this->app->cfgItem ("e10pro.zus.oddeleni.{$row ['svpOddeleni']}.nazev");

		$nextYear = $this->schoolYear;
		$nextYear++;

		$r['item'] = $this->app->cfgItem('options.e10-pro-zus.itemInvoicesFeeSchool', 0);
		$r['text'] =  'Školné ' . $pololeti . '.pololetí ' . $this->aktSkolniRok . '/' . $nextYear . ' - odd. ' . $oddeleni;
		$r['quantity'] = 1;
		$r['operation'] = 1010001;

		switch ($pololeti)
		{
			case 1:
				$r['priceItem'] = $row['skolVyPrvniPol'];
				break;
			case 2:
				$r['priceItem'] = $row['skolVyDruhePol'];
				break;
		}

		$this->invRows[] = $r;
	} // createRow

	function setPeriod ($today, $pololeti)
	{
		$todayYear = intval($today->format ('Y'));
		$todayMonth = intval($today->format ('m'));
		$this->dateIssue = Utils::today();
		if ($pololeti == 1 && $todayMonth === 7)
		{
			$dd = sprintf('%04d-%02d-%02d', $todayYear, 8, 26);
			$this->dateDue = Utils::createDateTime($dd);
		}
		else
		{
			$this->dateDue = Utils::today();
			$this->dateDue->add (new \DateInterval('P30D'));
		}
		switch ($pololeti)
		{
			case 1:
				//$this->dateDue = sprintf ("%04d-08-01", $todayYear);
				$beginDateStr = sprintf ("%04d-09-01", $todayYear);
				$endDateStr = sprintf ("%04d-01-31", $todayYear+1);
				break;
			case 2:
				//$this->dateDue = sprintf ("%04d-03-31", $todayYear);
				$beginDateStr = sprintf ("%04d-02-01", $todayYear);
				$endDateStr = sprintf ("%04d-06-30", $todayYear);
				break;
		}

		$this->periodBegin = new \DateTime ($beginDateStr);
		$this->periodEnd = new \DateTime ($endDateStr);
	} // setPeriod

	function tvorbaFakturProPololeti ($today, $pololeti)
	{
		$this->setPeriod ($today, $pololeti);

		$qr [] = 'SELECT studium.*, persons.fullName as studentName FROM [e10pro_zus_studium] as studium';
		$qr [] = ' LEFT JOIN e10_persons_persons AS persons ON studium.student = persons.ndx';
		$qr [] = ' WHERE studium.[stavHlavni] = 1';
		array_push($qr, ' AND (studium.datumNastupuDoSkoly IS NULL OR studium.datumNastupuDoSkoly < %d)', $this->periodEnd);
		array_push($qr, ' AND (studium.datumUkonceniSkoly IS NULL OR studium.datumUkonceniSkoly > %d)', $this->periodBegin);
		array_push($qr, ' AND studium.[skolniRok] = %i', $this->aktSkolniRok);
		if ($this->teacher > 0)
			array_push($qr, ' AND studium.[ucitel] = %i', $this->teacher);
		array_push($qr, ' ORDER BY studentName, studium.[cisloStudia]');

		$rows = $this->db()->query ($qr);
		$cntDoc = 0;
		forEach ($rows as $r)
		{
			if ($this->fakturaExistuje($r, $pololeti))
				continue;
			$this->createHead ($r, $pololeti);
			$this->createRow ($r, $pololeti);
			$this->ulozitFakturu();
			$cntDoc++;
		}
	} // tvorbaFakturProPololeti

	function fakturaExistuje ($row, $pololeti)
	{
		$nextYear = $this->aktSkolniRok;
		$nextYear++;
		$specSymb = ($nextYear - 2001) * 1000 + ($nextYear - 2000) * 10 + $pololeti;

		$dbCounterNdx = intval($this->app->cfgItem('options.e10-pro-zus.dbCounterInvoicesFeeSchool'));

		$q [] = 'SELECT COUNT(*) as cnt FROM e10doc_core_heads';
		array_push($q, ' WHERE docType = %s AND docState <= 4000', 'invno');
    array_push($q, ' AND dbCounter = %i AND symbol1 = %s', $dbCounterNdx, $row['cisloStudia']);
		array_push($q, ' AND symbol2 = %s', $specSymb);
		//array_push($q, ' AND person = %i', $row['student']);
		array_push($q, 'ORDER BY [ndx]');

		$r = $this->db()->query ($q)->fetch();

		if ($r['cnt'] > 0)
			return TRUE;

		return FALSE;
	} // fakturaExistuje


	function ulozitFakturu ()
	{
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$tableRows = new \E10Doc\Core\TableRows ($this->app);

		$docNdx = $tableDocs->dbInsertRec ($this->invHead);
		$this->invHead['ndx'] = $docNdx;

		$f = $tableDocs->getTableForm ('edit', $docNdx);

		forEach ($this->invRows as $r)
		{
			$r['document'] = $docNdx;
			$tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$tableDocs->dbUpdateRec ($f->recData);

		$f->checkAfterSave();
		$tableDocs->checkDocumentState ($f->recData);
		$tableDocs->dbUpdateRec ($f->recData);
		$tableDocs->checkAfterSave2 ($f->recData);
		$tableDocs->docsLog($f->recData['ndx']);
	} // ulozitFakturu

	function setParams($schY, $t, $pol)
	{
		$this->schoolYear = $schY;
		$this->teacher = $t;
		$this->pololeti = $pol;

		$this->aktSkolniRok = $this->schoolYear;
	}

	function run()
	{
		$today = new \DateTime();
		$this->tvorbaFakturProPololeti ($today, $this->pololeti);
	}
} // class GenerovaniFakturSkolneEngine

/**
 * GenerovaniFakturSkolneWizard
 *
 */
class GenerovaniFakturSkolneWizard extends Wizard
{
	public function doStep()
	{
		if ($this->pageNumber == 1) {
			$this->doIt();
		}
	}

	public function renderForm()
	{
		switch ($this->pageNumber) {
			case 0:
				$this->renderFormWelcome();
				break;
			case 1:
				$this->renderFormDone();
				break;
		}
	}

	public function renderFormWelcome()
	{
		$this->recData['focusedDocNdx'] = $this->focusedPK;

		$this->setFlag('formStyle', 'e10-formStyleSimple');

		$this->openForm();
		$this->addInput('focusedDocNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		// školní rok
		$this->recData ['schoolYear'] = zusutils::aktualniSkolniRok();
		$this->addInputEnum2 ('schoolYear', 'Školní rok:', zusutils::aktivniSkolniRoky($this->app), self::INPUT_STYLE_OPTION);
		// učitel
		$inputOptions = 0;
		if ((!$this->app()->hasRole ('scrtr')) && (!$this->app()->hasRole ('admin')))
		{
			$this->recData ['teacher'] = intval($this->app()->user()->data ('id'));
			$inputOptions |= TableForm::coReadOnly;
		}
		else
			$this->recData ['teacher'] = 0;
		$this->addInputEnum2 ('teacher', 'Učitel:', zusutils::ucitele($this->app), self::INPUT_STYLE_OPTION, $inputOptions);
		// pololetí
		$pololeti = ['1' => 'první', '2' => 'druhé'];
		$today = new \DateTime();
		//$todayYear = intval($today->format ('Y'));
		$todayMonth = intval($today->format ('m'));
		if (($todayMonth > 6) && ($todayMonth <= 12))
			$this->recData['pololeti'] = '1';
		else
			$this->recData['pololeti'] = '2';
		$this->addInputEnum2 ('pololeti', 'Pololetí:', $pololeti, TableForm::INPUT_STYLE_OPTION);
		$this->closeForm();
	}

	public function doIt()
	{
		$skolniRok = $this->app->cfgItem ('e10pro.zus.roky.'.$this->recData ['schoolYear']);

		$eng = new GenerovaniFakturSkolneEngine ($this->app());
		$eng->setParams($this->recData['schoolYear'], $this->recData['teacher'], $this->recData['pololeti']);
		$eng->run();

		$this->stepResult ['close'] = 1;
	}
} // class GenerovaniFakturSkolneWizard


/**
 * Class GenerovaniFakturPujcovneEngine
 * @package E10Pro\Zus
 */
class GenerovaniFakturPujcovneEngine extends \E10\Utility
{
	var $schoolYear;
	var $teacher;
	var $dbCounterNdx = 0;

	var $invHead = array ();
	var $invRows = array ();

	var $periodBegin;
	var $periodEnd;
	var $dateDue;
	var $aktSkolniRok;

	function createHead ($row)
	{
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->invHead = array ('docType' => 'invno');
		$tableDocs->checkNewRec($this->invHead);

		$this->invHead ['docState'] = 4000;
		$this->invHead ['docStateMain'] = 2;
		//$this->invHead ['docKind'] = 2;

		$this->invHead ['docType'] = 'invno';
		$this->invHead ['dbCounter'] = $this->dbCounterNdx;
		$this->invHead ['datePeriodBegin'] = $this->periodBegin;
		$this->invHead ['datePeriodEnd'] = $this->periodEnd;

//		$this->invHead ['person'] = $row['student'];
		if ($row['platce'])
			$this->invHead ['person'] = $row['platce'];
		else
			$this->invHead ['person'] = $row['student'];

		$this->invHead ['symbol1'] = $row['cisloStudia'];
		$this->invHead ['centre'] = $row['pobocka'];

		$oddeleni = $this->app->cfgItem ("e10pro.zus.oddeleni.{$row ['svpOddeleni']}.nazev");

		$nextYear = $this->aktSkolniRok;
		$nextYear++;

		$specSymb = ($nextYear - 2001) * 1000 + ($nextYear - 2000) * 10 + 3;
		$this->invHead ['symbol2'] = $specSymb;

		$this->invHead ['title'] = 'Půjčovné ' . $this->aktSkolniRok . '/'. $nextYear . ' - odd. ' . $oddeleni;
		if ($row['platce'])
		{
			$personRecData = $this->app()->loadItem($row['student'], 'e10.persons.persons');
			if ($personRecData)
				$this->invHead ['title'] .= ' ('.$personRecData['fullName'].')';
		}

		$this->invHead ['dateIssue'] = Utils::today();//$this->periodBegin;
		$this->invHead ['dateTax'] = $this->periodBegin;
		$this->invHead ['dateDue'] = $this->dateDue;
		$this->invHead ['dateAccounting'] = $this->periodBegin;

		$this->invHead ['paymentMethod'] = '0';
		$this->invHead ['roundMethod'] = intval($this->app->cfgItem ('options.e10doc-sale.roundInvoice', 0));
		$this->invHead ['author'] = $this->app()->userNdx();

		$this->invRows = [];
	}

	function createRow ($row)
	{
		$tableRows = new \E10Doc\Core\TableRows ($this->app);
		$r = array ();
		$tableRows->checkNewRec($r);

		$oddeleni = $this->app->cfgItem ("e10pro.zus.oddeleni.{$row ['svpOddeleni']}.nazev");

		$nextYear = zusutils::aktualniSkolniRok ();
		$nextYear++;

		$r['item'] = $this->app->cfgItem('options.e10-pro-zus.itemInvoicesFeeLending', 0);
		$r['text'] =  'Půjčovné ' . $this->aktSkolniRok . '/' . $nextYear . ' - odd. ' . $oddeleni;
		$r['quantity'] = 1;
		$r['operation'] = 1010001;

		$r['priceItem'] = $row['pujcovne'];

		$this->invRows[] = $r;
	}

	function setPeriod ($today)
	{
		$todayYear = intval($today->format ('Y'));

		$this->dateDue = Utils::today();
		$this->dateDue->add (new \DateInterval('P30D'));


		//$this->dateDue = sprintf ("%04d-10-31", $todayYear);
		$beginDateStr = sprintf ("%04d-09-01", $todayYear);
		$endDateStr = sprintf ("%04d-08-31", $todayYear + 1);

		$this->periodBegin = new \DateTime ($beginDateStr);
		$this->periodEnd = new \DateTime ($endDateStr);
	}

	function tvorbaFaktur ($today)
	{
		$this->setPeriod ($today);

		$qr [] = 'SELECT studium.*, persons.fullName as studentName FROM [e10pro_zus_studium] as studium';
		$qr [] = ' LEFT JOIN e10_persons_persons AS persons ON studium.student = persons.ndx';
		$qr [] = ' WHERE studium.[stavHlavni] = 1';
		array_push ($qr, ' AND studium.pujcovne > %i', 1);
		array_push($qr, ' AND studium.[skolniRok] = %i', $this->aktSkolniRok);
		if ($this->teacher > 0)
			array_push($qr, ' AND studium.[ucitel] = %i', $this->teacher);
		array_push($qr, ' ORDER BY studentName, studium.[cisloStudia]');

		$rows = $this->db()->query ($qr);

		$cntDoc = 0;
		forEach ($rows as $r)
		{
			if ($this->fakturaExistuje($r))
				continue;
			$this->createHead ($r);
			$this->createRow ($r);
			$this->ulozitFakturu();
			$cntDoc++;
		}
	}

	function fakturaExistuje ($row)
	{
		$q [] = 'SELECT COUNT(*) as cnt FROM e10doc_core_heads';
		array_push($q, ' WHERE docType = %s AND docState <= 4000', 'invno');
		array_push($q, ' AND dbCounter = %i AND symbol1 = %s AND person = %i', $this->dbCounterNdx, $row['cisloStudia'], $row['student']);
		array_push($q, ' AND (datePeriodBegin IS NOT NULL AND datePeriodBegin <= %d)', $this->periodBegin);
		array_push($q, ' AND (datePeriodEnd IS NOT NULL AND datePeriodEnd >= %d)', $this->periodEnd);
		array_push($q, 'ORDER BY [ndx]');

		$r = $this->db()->query ($q)->fetch();

		if ($r['cnt'] > 0)
			return TRUE;

		return FALSE;
	}

	function ulozitFakturu ()
	{
		$tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$tableRows = new \E10Doc\Core\TableRows ($this->app);

		$docNdx = $tableDocs->dbInsertRec ($this->invHead);
		$this->invHead['ndx'] = $docNdx;

		$f = $tableDocs->getTableForm ('edit', $docNdx);

		forEach ($this->invRows as $r)
		{
			$r['document'] = $docNdx;
			$tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$tableDocs->dbUpdateRec ($f->recData);

		$f->checkAfterSave();
		$tableDocs->checkDocumentState ($f->recData);
		$tableDocs->dbUpdateRec ($f->recData);
		$tableDocs->checkAfterSave2 ($f->recData);

		$tableDocs->docsLog ($docNdx);
	}

	function setParams($schY, $t)
	{
		$this->schoolYear = $schY;
		$this->teacher = $t;

		$this->aktSkolniRok = $this->schoolYear;
	}

	function run()
	{
		$today = new \DateTime();
		$this->dbCounterNdx = $this->app->cfgItem('options.e10-pro-zus.dbCounterInvoicesFeeLending');
		$this->tvorbaFaktur ($today);
	}
}


/**
 * Class GenerovaniFakturPujcovneWizard
 * @package E10Pro\Zus
 */
class GenerovaniFakturPujcovneWizard extends Wizard
{
	public function doStep()
	{
		if ($this->pageNumber == 1) {
			$this->doIt();
		}
	}

	public function renderForm()
	{
		switch ($this->pageNumber) {
			case 0:
				$this->renderFormWelcome();
				break;
			case 1:
				$this->renderFormDone();
				break;
		}
	}

	public function renderFormWelcome()
	{
		$this->recData['focusedDocNdx'] = $this->focusedPK;

		$this->setFlag('formStyle', 'e10-formStyleSimple');

		$this->openForm();
			$this->addInput('focusedDocNdx', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
			// školní rok
			$this->recData ['schoolYear'] = zusutils::aktualniSkolniRok();
			$this->addInputEnum2 ('schoolYear', 'Školní rok:', zusutils::aktivniSkolniRoky($this->app), self::INPUT_STYLE_OPTION);
			// učitel
			$inputOptions = 0;
			if ((!$this->app()->hasRole ('scrtr')) && (!$this->app()->hasRole ('admin')))
			{
				$this->recData ['teacher'] = intval($this->app()->user()->data ('id'));
				$inputOptions |= TableForm::coReadOnly;
			}
			else
				$this->recData ['teacher'] = 0;
			$this->addInputEnum2 ('teacher', 'Učitel:', zusutils::ucitele($this->app), self::INPUT_STYLE_OPTION, $inputOptions);
		$this->closeForm();
	}

	public function doIt()
	{
		$eng = new GenerovaniFakturPujcovneEngine ($this->app());
		$eng->setParams($this->recData['schoolYear'], $this->recData['teacher']);
		$eng->run();

		$this->stepResult ['close'] = 1;
	}
}


/**
 * Class DoplneniVyukEngine
 * @package E10Pro\Zus
 */
class DoplneniVyukEngine extends \E10\Utility
{
	var $skolniRok;
	var $deleteVyuky;
	var $passwordForDelete;

	public function setParams ($skolniRok, $delV, $passwd)
	{
		$this->skolniRok = $skolniRok;
		$this->deleteVyuky = $delV;
		$this->passwordForDelete = $passwd;
	}

	public function run()
	{
		$qs[] = 'SELECT predmety.nazev as nazevPredmetu, studiumpredmety.svpPredmet as predmet,';
		$qs[] = ' studiumpredmety.ucitel as ucitelPredmetu,';
		$qs[] = ' studium.*, student.fullName as studentName,';
		$qs[] = ' obory.typVyuky as oborTypVyuky';
		array_push ($qs,
			' FROM e10pro_zus_studium as studium',
			' LEFT JOIN e10_persons_persons AS student ON studium.student = student.ndx',
			' LEFT JOIN e10pro_zus_studiumpre AS studiumpredmety on studium.ndx = studiumpredmety.studium',
			' LEFT JOIN e10pro_zus_obory AS obory on studium.svpObor = obory.ndx',
			' LEFT JOIN e10pro_zus_predmety AS predmety ON studiumpredmety.svpPredmet = predmety.ndx');
		array_push ($qs, ' WHERE student != 0 AND predmety.typVyuky = 1 AND obory.typVyuky = 1 AND stav = 1200 AND skolniRok = %s',
			$this->skolniRok);

		$studia = $this->app->db()->query ($qs);
		foreach ($studia as $s)
		{
			$exist = $this->app->db()->query(
				'SELECT * FROM e10pro_zus_vyuky WHERE studium = %i AND skolniRok = %s', $s['ndx'],  $this->skolniRok)->fetch();
			if ($exist)
				continue;

			if (isset($s['ucitelPredmetu']) && $s['ucitelPredmetu'])
				$ucitel =  $s['ucitelPredmetu'];
			else
				$ucitel =  $s['ucitel'];

			$novaVyuka = [
				'typ' => 1, 'student' => $s['student'], 'studium' => $s['ndx'], 'nazev' => $s['studentName'],
				'skolniRok' => $s['skolniRok'], 'ucitel' => $ucitel, 'misto' => $s['misto'], 'rocnik' => $s['rocnik'],
				'svp' => $s['svp'], 'svpObor' => $s['svpObor'], 'svpOddeleni' => $s['svpOddeleni'], 'svpPredmet' => $s['predmet'],
				'stav' => 1000, 'stavHlavni' => 0
			];

			$this->db()->query ("INSERT INTO [e10pro_zus_vyuky] ", $novaVyuka);
		}
	}
} // class DoplneniVyukEngine

/**
 * DoplneniVyukWizard
 *
 */
class DoplneniVyukWizard extends Wizard
{
	public function doStep()
	{
		if ($this->pageNumber == 1) {
			$this->doIt();
		}
	}

	public function renderForm()
	{
		switch ($this->pageNumber) {
			case 0:
				$this->renderFormWelcome();
				break;
			case 1:
				$this->renderFormDone();
				break;
		}
	}

	public function renderFormWelcome()
	{
		$this->recData['focusedDocNdx'] = $this->focusedPK;

		$this->setFlag('formStyle', 'e10-formStyleSimple');

		$this->openForm();
			$this->recData ['schoolYear'] = zusutils::aktualniSkolniRok();
			$this->addInputEnum2 ('schoolYear', 'Školní rok:', zusutils::aktivniSkolniRoky($this->app), self::INPUT_STYLE_OPTION);

			$this->layoutOpen(TableForm::ltGrid);
				$this->addCheckBox('deleteVyukyItems', 'Smazat před doplněním všechny výuky a rozvrhy', 1, TableForm::coColW6);
				$this->addInput ('passwordDeleteVyuky', 'Heslo:', self::INPUT_STYLE_STRING, TableForm::coColW5, 20);
			$this->layoutClose();
		$this->closeForm();
	}

	public function doIt()
	{
		$eng = new DoplneniVyukEngine($this->app());
		$eng->setParams($this->recData['schoolYear'], $this->recData['deleteVyukyItems'], $this->recData['passwordDeleteVyuky']);
		$eng->run ();

		$this->stepResult ['close'] = 1;
	}
} // class DoplneniVyukWizard


/**
 * Class ViewPlacesComboUcebny
 * @package E10Pro\Zus
 */
class ViewPlacesComboUcebny extends \e10\base\ViewPlacesComboRooms
{
	public function defaultQuery (&$q)
	{
		if ($this->queryParam('pobocka'))
		{
			array_push ($q, ' AND places.[placeParent] = %s ', $this->queryParam('pobocka'));
		}
	}
}
