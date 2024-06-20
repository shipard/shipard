<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Utils\Utils;



/**
 * class ReportVykazZakuAHodin
 */
class ReportVykazZakuAHodin extends \e10doc\core\libs\reports\DocReportBase
{
	/** @var \e10\persons\TablePersons $tablePersons */
	var $tablePersons;

  var $teacherNdx = 0;
  var $schoolYearId = '';
  var $schoolYear = NULL;
  var $schoolYearBegin;
  var $schoolYearEnd;

  var $sums = [
    'h1' => [],
    'h2' => []
  ];

  var $srcData = [];

	function init ()
	{
		$this->reportId = 'reports.modern.e10pro.zus.vykazZakuAHodin';
		$this->reportTemplate = 'reports.modern.e10pro.zus.vykazZakuAHodin';

		parent::init();
	}

	public function loadData2 ()
	{

    $this->teacherNdx = $this->recData['ndx'];
    if ($this->schoolYearId === '')
      $this->schoolYearId = $this->app()->testGetParam('data-param-school-year');

    $this->schoolYear = $this->app->cfgItem ('e10pro.zus.roky.'.$this->schoolYearId);

    $this->schoolYear['dateH1'] = Utils::datef(Utils::createDateTime($this->schoolYear['V1']), '%d');
    $this->schoolYear['dateH2'] = Utils::datef(Utils::createDateTime($this->schoolYear['V2']), '%d');

    $this->schoolYearBegin = Utils::createDateTime($this->schoolYear['zacatek']);
    $this->schoolYearEnd = Utils::createDateTime($this->schoolYear['konec']);

		parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->tablePersons = $this->app()->table('e10.persons.persons');

		parent::loadData();



		$this->data ['teacher'] = $this->app()->loadItem($this->recData['ndx'], 'e10.persons.persons');
    $this->data ['schoolYearId'] = $this->schoolYearId;
    $this->data ['schoolYear'] = $this->schoolYear;

    $this->loadData_Hodiny();
    $this->createContent_Data ();
    //$this->createContent_Studia();
    $this->data ['sums'] = $this->sums;
	}

  function loadData_Hodiny ()
	{
    $dt = [];

		$q = [];
		array_push($q, 'SELECT hodiny.*, ');
		array_push($q, ' vyuky.studium AS studiumNdx, vyuky.nazev AS vyukaNazev,');
		array_push($q, ' vyuky.svpPredmet AS predmet, predmety.nazev AS predmetNazev');
		array_push($q, ' FROM [e10pro_zus_hodiny] AS hodiny');
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON vyuky.studium = studia.ndx');
    array_push($q, ' LEFT JOIN e10pro_zus_predmety AS predmety ON vyuky.svpPredmet = predmety.ndx');
		array_push($q, ' WHERE 1');
    array_push($q, ' AND vyuky.stav != %i', 9800);
    array_push($q, ' AND hodiny.stav != %i', 9800);
		array_push($q, ' AND vyuky.skolniRok = %s', $this->schoolYearId);
    array_push($q, ' AND hodiny.ucitel = %s', $this->teacherNdx);
    array_push($q, ' ORDER BY vyuky.[typ] DESC, vyuky.nazev, vyuky.ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $dow = 0;
      $monthId = 'M9';
      $pairId = '';
      $vyukaNdx = $r['vyuka'];

      $pairId .= $r['svpPredmet'].'_';

      //$pairId .= $r['vyuka'].'_';
      if (!Utils::dateIsBlank($r['datum']))
      {
        $dow = intval($r['datum']->format('N'));
        $monthId = 'M'.intval($r['datum']->format('m'));
        $pairId .= $r['datum']->format('Y-m-d').'_';
        $pairId .= $dow.'_';
      }
      else
        $pairId .= 'X'.'_';


      $pairId .= utils::timeToMinutes($r['zacatek']);

      if (!isset($dt[$pairId]))
      {
        $dt[$pairId] = [
          'vyuky' => [],
          'cntMonthly' => [],
          'cntTotal' => 0,
          'monthId' => $monthId,
          'dow' => $dow,
          'zacatek' => utils::timeToMinutes($r['zacatek']),
          'predmet' => $r['predmet'],
          'predmetNazev' => $r['predmetNazev'],
        ];
      }

      if (!isset($dt[$pairId]['cntMonthly'][$monthId]))
        $dt[$pairId]['cntMonthly'][$monthId] = 0;

      $dt[$pairId]['cntMonthly'][$monthId]++;

      if (!isset($dt[$pairId]['vyuky'][$vyukaNdx]))
      {
        $dt[$pairId]['vyuky'][$vyukaNdx] = [
          'title' => $r['vyukaNazev'],
        ];
      }
    }

    // sumIds
    foreach ($dt as $pairId => $oneRow)
    {
      //$dt[$pairId]['sumId'] = $oneRow['predmet'].'_'.implode('-', array_keys($oneRow['vyuky']));
      $dt[$pairId]['sumId'] = $oneRow['predmet'].'_'.$oneRow['dow'].'_'.$oneRow['zacatek'];
    }


    $this->srcData = $dt;
  }

  function createContent_Data ()
	{
    $t = [];
    foreach ($this->srcData as $pairId => $oneRow)
    {
      $sumId = $oneRow['sumId'];

      $cntv = count($oneRow['vyuky']);
      if (!$cntv)
        $cntv = 1;
      if (!isset($t[$sumId]))
      {
        $t[$sumId] = ['title' => []];
        foreach ($oneRow['vyuky'] as $vyukaNdx => $vyuka)
        {
          $t[$sumId]['title'][] = ['text' => $vyuka['title'], 'class' => 'block'];
        }
      }

      $t[$sumId]['predmet'] = $oneRow['predmetNazev'];

      foreach ($oneRow['cntMonthly'] as $monthId => $mc)
      {
        if (!isset($t[$sumId][$monthId]))
          $t[$sumId][$monthId] = 0;

        $t[$sumId][$monthId]++;// += intval($mc / $cntv);
      }
    }

    $h = [
      '#' => '#', 'title' => 'Student',
      'M9' => '9',
      'M10' => '10',
      'M11' => '11',
      'M12' => '12',
      'M1' => '1',
      'M2' => '2',
      'M3' => '3',
      'M4' => '4',
      'M5' => '5',
      'M6' => '6',

      'predmet' => 'Předmět',
    ];

		$this->data['test'] = [
      ['type' => 'table', 'header' => $h, 'table' => $t, 'params' =>  ['tableClass' => 'seznamStudii']]
    ];

  }

  function createContent_Studia ()
	{
    $resScs = [0 => '-', 1 => 'V', 2 => 'P', 3 => 'N'];
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
            ' WHERE studium.stavHlavni != 4';

		array_push ($q, " AND studium.[skolniRok] = %s", $this->schoolYearId);
    array_push ($q, " AND studium.[ucitel] = %i", $this->teacherNdx);
    array_push ($q, " ORDER BY students.fullName, studium.cisloStudia");

    $rows = $this->app->db()->query ($q);

		$data = array ();

		forEach ($rows as $r)
		{
      $item = [
				'student' => ['text'=> $r['studentFullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r['student']],
				'obor' => $r['oborId'],
				'oddeleni' => $r['oddeleniNazev'],
				'docNumber' => array ('text'=> $r['cisloStudia'], 'docAction' => 'edit', 'table' => 'e10pro.zus.studium', 'pk'=> $r['ndx']),
				'rocnik' => $rocniky[$r['rocnik']]['zkratka'],
				'pobocka' => $r['pobockaShortName'],
				'ucitel' => array ('text'=> $r['teacherFullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r['ucitel'])
			];

      if (!Utils::dateIsBlank($r['datumNastupuDoSkoly']))
      {
        if ($r['datumNastupuDoSkoly'] > $this->schoolYearBegin)
          $item['od'] = Utils::datef($r['datumNastupuDoSkoly'], '%d');
      }
      if (!Utils::dateIsBlank($r['datumUkonceniSkoly']))
      {
        if ($r['datumUkonceniSkoly'] < $this->schoolYearEnd)
          $item['do'] = Utils::datef($r['datumUkonceniSkoly'], '%d');
      }

      $v = $this->db()->query('SELECT * FROM [e10pro_zus_vysvedceni] WHERE [skolniRok] = %s', $this->schoolYearId,
                              ' AND [studium] = %i', $r['ndx'])->fetch();

      if ($v)
      {
        if ($v['typVysvedceni'] == 2)
        { // PS
          if (!isset($this->sums['h1']['ps']))
            $this->sums['h1']['ps'] = 1;
          else
            $this->sums['h1']['ps']++;

          if (!isset($this->sums['h2']['ps']))
            $this->sums['h2']['ps'] = 1;
          else
            $this->sums['h2']['ps']++;

          $item['h1'] = 'PS';
          $item['h2'] = 'PS';
        }
        else
        {
          if (!isset($this->sums['h1']['v'.$v['hodnoceni1p']]))
            $this->sums['h1']['v'.$v['hodnoceni1p']] = 1;
          else
            $this->sums['h1']['v'.$v['hodnoceni1p']]++;

          if (!isset($this->sums['h2']['v'.$v['hodnoceni2p']]))
            $this->sums['h2']['v'.$v['hodnoceni2p']] = 1;
          else
            $this->sums['h2']['v'.$v['hodnoceni2p']]++;


          $item['h1'] = $resScs [$v['hodnoceni1p']];
          $item['h2'] = $resScs [$v['hodnoceni2p']];
        }
      }
      else
      {
        $item['h1'] = '--';
        $item['h2'] = '--';
      }

      if (!isset($this->sums['h1']['total']))
        $this->sums['h1']['total'] = 1;
      else
        $this->sums['h1']['total']++;

      if (!isset($this->sums['h2']['total']))
        $this->sums['h2']['total'] = 1;
      else
        $this->sums['h2']['total']++;


      $data [] = $item;
    }

    foreach ($this->sums as $hid => $sums)
    {
      if (isset($sums['ps']) || isset($sums['v0']))
        $this->sums[$hid]['nc'] = ($sums['ps'] ?? 0) + ($sums['v0'] ?? 0);
    }

		$h = [
      '#' => '#', 'student' => 'Student', 'docNumber' => ' Studium č.',
      'obor' => 'Obor', 'oddeleni' => 'Studijní zaměření',
			'rocnik' => ' Ročník',
      'od' => 'Od', 'do' => 'Do',
      'h1' => '|1p', 'h2' => '|2p'
    ];

		$this->data['studia'] = [
      ['type' => 'table', 'header' => $h, 'table' => $data, 'params' =>  ['tableClass' => 'seznamStudii']]
    ];
  }
}
