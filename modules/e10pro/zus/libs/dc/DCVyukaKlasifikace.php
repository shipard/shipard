<?php

namespace e10pro\zus\libs\dc;
use \Shipard\Utils\Utils;


/**
 * class DCVyukaKlasifikace
 */
class DCVyukaKlasifikace extends \Shipard\Base\DocumentCard
{
  var $znamkyHodnoceni;
  var $attHalfYearDate;
  var $attEndYearDate;
  var $academicYear;
  var $data = [];

  protected function loadData()
  {
		$this->academicYear = $this->app->cfgItem ('e10pro.zus.roky.'.$this->recData ['skolniRok']);

		$this->attHalfYearDate = utils::createDateTime($this->academicYear['V1']);
		if (isset($this->academicYear['KK1']))
			$this->attHalfYearDate = utils::createDateTime($this->academicYear['KK1']);
		$this->attEndYearDate = utils::createDateTime($this->academicYear['V2']);
		if (isset($this->academicYear['KK2']))
			$this->attEndYearDate = utils::createDateTime($this->academicYear['KK2']);


		$this->data ['individual'] = intval($this->recData ['typ']);
		$this->data ['skolniRok'] = $this->academicYear['nazev'];
		$this->data ['svpOddeleni'] = $this->app()->cfgItem ("e10pro.zus.svp.{$this->recData ['svp']}.pojmenovani");


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
		$this->znamkyHodnoceni = $this->app->cfgItem ('zus.znamkyHodnoceni');

    if ($this->recData ['typ'] === 0)
    { // kolektivni
      $this->loadDataCollective();
    }
    else
    { // individualni

      $this->loadDataIndividual();
    }
  }

  protected function loadDataIndividual()
  {
		$this->data ['hodiny'] = [];

    $sumGrades = 0;
    $cntGrades = 0;

    $hyId = 1;

		$q = [];
		$q[] = 'SELECT * FROM e10pro_zus_hodiny WHERE 1';
		array_push($q, ' AND vyuka = %i', $this->recData['ndx']);
		array_push($q, ' AND stav != %i', 9800);
		array_push($q, ' ORDER BY [datum], [zacatek]');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = [
					'datum' => ($r['datum']) ? $r['datum']->format ('j.n.') : '!!!',
					//'pritomnost' => /*$dochazkaPritomnost[$r['pritomnost']]['sc']*/$fullAttendanceShortcuts[$r['pritomnost']],
					'znamka' => $this->znamkyHodnoceni[$r['klasifikaceZnamka']]['sc'],
			];

      if (!intval($r['klasifikaceZnamka']))
        continue;

			$this->data ['hodiny'][] = $item;

      if ($hyId == 1 && $r['datum'] > $this->attHalfYearDate)
      {
        $sumItem = [
          'datum' => "Průměr za {$hyId}. pololetí:",
          'znamka' => $cntGrades ? round($sumGrades / $cntGrades) : '---',
          '_options' => ['class' => 'sumtotal', 'afterSeparator' => 'separator'],
        ];

        $this->data ['hodiny'][] = $sumItem;
        $sumGrades = 0;
        $cntGrades = 0;

        $hyId = 2;
      }

      if (intval($r['klasifikaceZnamka']))
      {
        $cntGrades++;
        $sumGrades += intval($r['klasifikaceZnamka']);
      }
    }

    $sumItem = [
      'datum' => "Průměr za {$hyId}. pololetí:",
      'znamka' => $cntGrades ? round($sumGrades / $cntGrades, 2) : '---',
      '_options' => ['class' => 'sumtotal'],
    ];

    $this->data ['hodiny'][] = $sumItem;
  }

  protected function loadDataCollective()
  {
    $hyId = 1;

    // -- students
		$q = [];
    array_push($q, 'SELECT dochazka.*, dochazka.studium AS studiumNdx, hodiny.datum AS datum, students.fullName AS studentName');
    array_push($q, ' FROM e10pro_zus_hodinydochazka AS dochazka');
    array_push($q, ' LEFT JOIN e10pro_zus_hodiny AS hodiny ON dochazka.hodina = hodiny.ndx');
    array_push($q, ' LEFT JOIN e10_persons_persons AS students ON dochazka.student = students.ndx');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND hodiny.vyuka = %i', $this->recData['ndx']);
		array_push($q, ' AND hodiny.stav != %i', 9800);
		array_push($q, ' ORDER BY students.lastName');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
      if ($hyId == 1 && $r['datum'] > $this->attHalfYearDate)
        $hyId = 2;
      else
        $hyId = 1;

      if (!isset($this->data ['headers'][$hyId]))
        $this->data ['headers'][$hyId] = ['name' => 'Jméno', 'avg' => 'pr.'];

      //$dateId = ($r['datum']) ? $r['datum']->format ('Ymd') : '!!!';
      $dateTitle = ($r['datum']) ? $r['datum']->format ('j.n') : '!!!';
      $studiumId = 'S'.$r['studiumNdx'];

      if (!isset($this->data ['hodiny'][$hyId][$studiumId]['name']))
        $this->data ['hodiny'][$hyId][$studiumId]['name'] = $r['studentName'];
    }

    // -- grades
		$q = [];
    $hyId = 1;
    array_push($q, 'SELECT dochazka.*, dochazka.studium AS studiumNdx, hodiny.datum AS datum, students.fullName AS studentName');
    array_push($q, ' FROM e10pro_zus_hodinydochazka AS dochazka');
    array_push($q, ' LEFT JOIN e10pro_zus_hodiny AS hodiny ON dochazka.hodina = hodiny.ndx');
    array_push($q, ' LEFT JOIN e10_persons_persons AS students ON dochazka.student = students.ndx');
    array_push($q, ' WHERE 1');
		array_push($q, ' AND hodiny.vyuka = %i', $this->recData['ndx']);
		array_push($q, ' AND hodiny.stav != %i', 9800);
		array_push($q, ' ORDER BY [hodiny].[datum], hodiny.[zacatek], students.lastName');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
      if (!intval($r['klasifikaceZnamka']))
        continue;

      if ($hyId == 1 && $r['datum'] > $this->attHalfYearDate)
        $hyId = 2;

      $dateId = ($r['datum']) ? $r['datum']->format ('Ymd') : '!!!';
      $dateTitle = ($r['datum']) ? $r['datum']->format ('j.n') : '!!!';
      $studiumId = 'S'.$r['studiumNdx'];


      if (!isset($this->data ['headers'][$hyId][$dateId]))
      {
        $this->data ['headers'][$hyId][$dateId] = '|'.$dateTitle;
      }

      $this->data ['hodiny'][$hyId][$studiumId][$dateId] = $this->znamkyHodnoceni[$r['klasifikaceZnamka']]['sc'];

      if (!isset($this->data ['avgs'][$hyId][$studiumId]))
      {
        $this->data ['avgs'][$hyId][$studiumId] = ['cnt' => 0, 'sum' => 0];
      }

      $this->data ['avgs'][$hyId][$studiumId]['cnt']++;
      $this->data ['avgs'][$hyId][$studiumId]['sum'] += intval($r['klasifikaceZnamka']);
    }

    foreach ($this->data ['avgs'] as $avgHyId => $hyAvgs)
    {
      foreach ($hyAvgs as $studiumId => $studiumAvg)
      {
        if ($studiumAvg['cnt'])
          $this->data ['hodiny'][$avgHyId][$studiumId]['avg'] = round($studiumAvg['sum'] / $studiumAvg['cnt'], 2);
      }
    }
  }

  public function createContent ()
	{
    $this->loadData();

    if ($this->recData ['typ'] === 0)
    { // kolektivni
      foreach ($this->data ['avgs'] as $avgHyId => $hyAvgs)
      {
        $this->addContent ('body', [
          'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => $avgHyId.'. pololetí',
          'header' => $this->data['headers'][$avgHyId], 'table' => $this->data['hodiny'][$avgHyId]
        ]);
      }
    }
    else
    { // individualni
      $h = ['datum' => 'Datum', 'znamka' => 'Známka'];
      $this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $this->data['hodiny']]);
    }
	}
}
