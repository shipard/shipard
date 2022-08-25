<?php

namespace e10pro\zus;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use e10\GlobalReport, e10\utils, e10pro\zus\zusutils;


/**
 * Class ReportBeginEndStudies
 * @package e10pro\zus
 */
class ReportBeginEndStudies extends GlobalReport
{
	var $list = [];
	var $dateBegin = NULL;
	var $dateEnd = NULL;

	function init()
	{
		// -- toolbar
		$this->addParam ('calendarMonth', 'calendarMonth', ['flags' => ['quarters', 'halfs']]);
		$this->addParam ('switch', 'eduprogram', ['title' => 'Vzdělávací program', 'cfg' => 'e10pro.zus.svp', 'titleKey' => 'nazev']);
		$this->addParam ('switch', 'obor', ['title' => 'Obor', 'cfg' => 'e10pro.zus.obory', 'titleKey' => 'nazev',
			'enableAll' => ['0' => ['title' => 'Vše']]]);

		// -- panel
		$this->addParam ('switch', 'ucitel', ['title' => 'Učitel', 'place' => 'panel', 'switch' => \E10Pro\Zus\zusutils::ucitele($this->app)]);
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


		parent::init();

		$this->setInfo('icon', 'tables/e10pro.zus.studium');
		$this->setInfo('title', 'Zahájená a ukončená studia ');
	}

	function createContent ()
	{
		parent::createContent();

		$this->dateBegin = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);
		$this->dateEnd = utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);

		$this->loadList();

		$this->setInfo('param', 'Období: ', utils::datef($this->dateBegin, '%d').' - '.utils::datef($this->dateEnd, '%d'));

		$h = [
			'#' => '#', 'student' => 'Student', 'studium' => ' Studium',
			'obor' => 'Obor', 'oddeleni' => 'Studijní zaměření',
			'rocnik' => ' Ročník', 'pobocka' => 'Pobočka', 'ucitel' => 'Učitel',
			'datumNastupu' => ' Nástup', 'datumUkonceni' => ' Ukončení',
		];

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

		$this->addContent(['type' => 'table', 'header' => $h, 'table' => $this->list, 'main' => TRUE]);
	}

	protected function loadList()
	{
		$genderIcons = ['∅', '♂', '♀'];
		$rocniky = $this->app->cfgItem ('e10pro.zus.rocniky');

		$q = [];
		array_push($q, 'SELECT studium.*, ');
		array_push($q, ' ucitel.fullName as ucitelFullName, student.fullName as studentFullName, student.lastName as studentLastName,');
		array_push($q, ' student.company as studentCompany, student.gender as studentGender, places.fullName as placeName,');
		array_push($q, ' oddeleni.nazev as oddeleniNazev, svp.id as svpShortName, obory.nazev as oborNazev, obory.id as oborId');
		array_push($q, ' FROM [e10pro_zus_studium] as studium ');
		array_push($q, ' LEFT JOIN e10_persons_persons AS ucitel ON studium.ucitel = ucitel.ndx ');
		array_push($q, ' LEFT JOIN e10_persons_persons AS student ON studium.student = student.ndx ');
		array_push($q, ' LEFT JOIN e10_base_places AS places ON studium.misto = places.ndx ');
		array_push($q, ' LEFT JOIN e10pro_zus_oddeleni AS oddeleni ON studium.svpOddeleni = oddeleni.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_obory AS obory ON studium.svpObor = obory.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_svp AS svp ON studium.svp = svp.ndx');

		array_push($q, ' WHERE 1');

		if ($this->reportParams ['obor']['value'] != 0)
			array_push ($q, " AND studium.[svpObor] = %i", $this->reportParams ['obor']['value']);

		array_push($q, 'AND (');
		array_push ($q, '(datumUkonceniSkoly IS NOT NULL AND datumUkonceniSkoly >= %d', $this->dateBegin, ' AND datumUkonceniSkoly <= %d', $this->dateEnd, ')');
		array_push($q, ' OR ');
		array_push ($q, '(datumNastupuDoSkoly IS NOT NULL AND datumNastupuDoSkoly >= %d', $this->dateBegin, ' AND datumNastupuDoSkoly <= %d', $this->dateEnd, ')');
		array_push($q, ')');

		if ($this->reportParams ['stupen']['value'] != 0)
			array_push ($q, " AND studium.[stupen] = %i", $this->reportParams ['stupen']['value']);

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
			array_push ($q, " AND student.[gender] = %i", $this->reportParams ['gender']['value']);

		array_push($q, ' ORDER BY student.lastName, student.firstName, studium.ndx');


		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
				'studium' => ['text' => ' '.$r['cisloStudia'], 'docAction' => 'edit', 'pk' => $r['ndx'], 'table' => 'e10pro.zus.studium'],
				'student' => $r['studentFullName'],
				'obor' => $r['oborId'],
				'oddeleni' => $r['oddeleniNazev'],
				'rocnik' => $rocniky[$r['rocnik']]['zkratka'],
				'pobocka' => $r['placeName'],
				'ucitel' => ['text'=> $r['ucitelFullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r['ucitel']]
			];

			if (!utils::dateIsBlank($r['datumNastupuDoSkoly']))
				$item['datumNastupu'] = $r['datumNastupuDoSkoly'];

			if (!utils::dateIsBlank($r['datumUkonceniSkoly']))
				$item['datumUkonceni'] = $r['datumUkonceniSkoly'];

			$this->list[] = $item;
		}
	}
}
