<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';


use e10\utils, e10\Utility, e10\uiutils, e10pro\zus\zusutils, e10\str;
use function E10\sortByOneKey;


/**
 * Class ReportKatalog
 * @package e10pro\zus
 */
class ReportKatalog extends \e10doc\core\libs\reports\DocReportBase
{
	var $vysvedceni = [];
	var $stupneStudia = [];

	function init ()
	{
		$this->reportId = 'e10pro.zus.katalog';
		$this->reportTemplate = 'reports.modern.e10pro.zus.katalog';
		$this->paperOrientation = 'landscape';
	}

	public function loadData ()
	{
    parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->data ['svpOddeleni'] = $this->app()->cfgItem ("e10pro.zus.svp.{$this->recData ['svp']}.pojmenovani");

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

		$this->data ['stObcan'] = "Česká republika";

		if (!utils::dateIsBlank($this->recData['datumNastupuDoSkoly']))
			$this->data['nastup1'] = ['datum' => utils::datef($this->recData['datumNastupuDoSkoly'])];

		if (!utils::dateIsBlank($this->recData['datumUkonceniSkoly']))
			$this->data['ukonceniStudia'] = utils::datef($this->recData['datumUkonceniSkoly'], '%d');

		$this->nacistVysvedceni();

		$this->data ['stupen'] = implode(', ', $this->stupneStudia);//$this->app()->cfgItem ("e10pro.zus.stupne.{$this->recData ['stupen']}.nazev");
	}

	function nacistVysvedceni()
	{
		$skolniRoky = $this->app()->cfgItem ('e10pro.zus.roky');
		$stupne = $this->app()->cfgItem ('e10pro.zus.stupne');
		$rocniky = $this->app()->cfgItem ('e10pro.zus.rocniky');
		$hodnoceni = $this->app()->cfgItem ('zus.hodnoceni');

		$h = ['nazev' => 'název'];

		$this->vysvedceni['skolniRok'] = ['order' => 'AA01', 'nazev' => 'Školní rok'];
		$this->vysvedceni['rocnik'] = ['order' => 'AA02', 'nazev' => 'Ročník'];
		$this->vysvedceni['pololeti'] = ['order' => 'AA03', 'nazev' => 'Pololetí'];

		$this->vysvedceni['zameskaneHodiny'] = ['order' => 'ZZ01', 'nazev' => 'Zameškané hodiny'];
		$this->vysvedceni['zameskaneHodinyNeomluvene'] = ['order' => 'ZZ02', 'nazev' => 'Z nich neomluvené'];
		$this->vysvedceni['celkoveHodnoceni'] = ['order' => 'ZZ03', 'nazev' => 'Celkové hodnocení'];
		$this->vysvedceni['datumVysvedceni'] = ['order' => 'ZZ04', 'nazev' => 'Vysvědčení vydáno dne'];
		$this->vysvedceni['podpisUcitele'] = ['order' => 'ZZ05', 'nazev' => 'Podpis učitele'];
		$this->vysvedceni['podpisReditele'] = ['order' => 'ZZ06', 'nazev' => 'Podpis ředitele'];

		$q = [];
		array_push($q, 'SELECT vysvedceni.*,');
		array_push($q, ' studium.datumNastupuDoSkoly,');
		array_push($q, ' ucitele.lastName AS ucitelPrijmeni, ucitele.firstName AS ucitelJmeno, ucitele.fullName AS ucitelFullName');
		array_push($q, ' FROM [e10pro_zus_vysvedceni] AS [vysvedceni]');
		array_push($q, ' LEFT JOIN [e10pro_zus_studium] AS [studium] ON vysvedceni.studium = studium.ndx');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [ucitele] ON vysvedceni.ucitel = ucitele.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND vysvedceni.[student] = %i', $this->recData['student']);
		array_push($q, ' AND vysvedceni.[stav] != %i', 9800);
		//array_push($q, ' AND vysvedceni.[typVysvedceni] != %i', 2);
		//array_push($q, ' AND vysvedceni.[svp] = %i', $this->recData['svp']);
		//array_push($q, ' AND vysvedceni.[svpObor] = %i', $this->recData['svpObor']);
		//array_push($q, ' AND vysvedceni.[svpOddeleni] = %i', $this->recData['svpOddeleni']);
		array_push($q, ' AND studium.[cisloStudia] = %i', $this->recData['cisloStudia']);
		array_push($q, ' ORDER BY vysvedceni.skolniRok');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$skolniRok = $skolniRoky[$r['skolniRok']];
			$rocnik = $rocniky[$r['rocnik']];

			$stupen = $this->app()->cfgItem ("e10pro.zus.stupne.{$r['stupen']}.nazev");
			if (!in_array($stupen, $this->stupneStudia))
				$this->stupneStudia[] = $stupen;
			if (!isset($this->data['nastup1']['text']))
				$this->data['nastup1']['text'] = $rocnik['nazev'];

			if ($r['typVysvedceni'] == 2)
				continue;

			$skolniRokId = 'SR'.$r['skolniRok'];

			$this->vysvedceni['skolniRok'][$skolniRokId.'_1'] = $skolniRok['nazev'];
			$this->vysvedceni['skolniRok'][$skolniRokId.'_2'] = $skolniRok['nazev'];

			$this->vysvedceni['skolniRok']['_options']['colSpan'][$skolniRokId.'_1'] = 2;

			$this->vysvedceni['rocnik'][$skolniRokId.'_1'] = $rocnik['zkratka'];
			$this->vysvedceni['rocnik']['_options']['colSpan'][$skolniRokId.'_1'] = 2;

			$this->vysvedceni['pololeti'][$skolniRokId.'_1'] = 'I.';
			$this->vysvedceni['pololeti'][$skolniRokId.'_2'] = 'II.';
			$this->vysvedceni['pololeti']['_options'] = ['afterSeparator' => 'separator'];

			$this->vysvedceni['zameskaneHodiny']['_options'] = ['beforeSeparator' => 'separator'];

			$teacherName = $r['ucitelPrijmeni'].' '.str::upToLen($r['ucitelJmeno'], 1).'.';
			$directorName = 'Mikl L.';

			if ($r['hodnoceni1p'])
			{
				$this->vysvedceni['celkoveHodnoceni'][$skolniRokId . '_1'] = $hodnoceni[$r['hodnoceni1p']]['zkr'];
				$this->vysvedceni['datumVysvedceni'][$skolniRokId.'_1'] = utils::datef($skolniRok['V1'], '%s');
				$this->vysvedceni['podpisUcitele'][$skolniRokId.'_1'] = $teacherName;
				$this->vysvedceni['podpisReditele'][$skolniRokId.'_1'] = $directorName;
				$this->vysvedceni['zameskaneHodinyNeomluvene'][$skolniRokId.'_1'] = $r['zamHodinyNeo1p'];
				$this->vysvedceni['zameskaneHodiny'][$skolniRokId.'_1'] = $r['zamHodinyOml1p'] + $r['zamHodinyNeo1p'];
			}
			if ($r['hodnoceni2p'])
			{
				$this->vysvedceni['celkoveHodnoceni'][$skolniRokId . '_2'] = $hodnoceni[$r['hodnoceni2p']]['zkr'];
				$this->vysvedceni['datumVysvedceni'][$skolniRokId.'_2'] = utils::datef($skolniRok['V2'], '%s');
				$this->vysvedceni['podpisUcitele'][$skolniRokId.'_2'] = $teacherName;
				$this->vysvedceni['podpisReditele'][$skolniRokId.'_2'] = $directorName;
				$this->vysvedceni['zameskaneHodiny'][$skolniRokId.'_2'] = $r['zamHodinyOml2p'] + $r['zamHodinyNeo2p'];
				$this->vysvedceni['zameskaneHodinyNeomluvene'][$skolniRokId.'_2'] = $r['zamHodinyNeo2p'];
			}

			if (!isset($h[$skolniRokId.'_1']))
				$h[$skolniRokId.'_1'] = '|'.$skolniRokId.'_1';
			if (!isset($h[$skolniRokId.'_2']))
				$h[$skolniRokId.'_2'] = '|'.$skolniRokId.'_2';

			$this->nacistVysvedceni_Klasifikace($r['ndx'], $skolniRokId);
		}


		$prehledOProspechu = [
			'type' => 'table', 'table' => sortByOneKey($this->vysvedceni, 'order', TRUE), 'header' => $h, '_title' => 'test',
			'params' => ['tableClass' => 'e10-print-small default', 'hideHeader' => 1]
		];

		$this->data['prehledOProspechu'] = [$prehledOProspechu];
	}

	function nacistVysvedceni_Klasifikace($vysvedceni, $skolniRokId)
	{
		$q = [];
		array_push($q, 'SELECT znamky.*, predmety.pos as poradiPredmetu ');
		array_push($q, ' FROM [e10pro_zus_znamky] AS znamky');
		array_push($q, ' LEFT JOIN [e10pro_zus_predmety] AS predmety ON znamky.svpPredmet = predmety.ndx');
		array_push($q, ' WHERE [vysvedceni] = %i', $vysvedceni);
		array_push($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$predmetId = 'PR'.$r['svpPredmet'];
			$predmetNazev = 		$this->app()->cfgItem ("e10pro.zus.predmety.{$r['svpPredmet']}.nazev");

			if (!isset($this->vysvedceni[$predmetId]))
			{
				$this->vysvedceni[$predmetId]['nazev'] = $predmetNazev;
				$this->vysvedceni[$predmetId]['order'] = 'PP'.sprintf('%07d', $r['poradiPredmetu']).'-'.$predmetNazev;
			}

			if ($r['znamka1p'] != 0)
				$this->vysvedceni[$predmetId][$skolniRokId . '_1'] = $r['znamka1p'];

			if ($r['znamka2p'] != 0)
				$this->vysvedceni[$predmetId][$skolniRokId . '_2'] = $r['znamka2p'];

		}
	}
}
