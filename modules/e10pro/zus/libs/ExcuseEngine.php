<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Utils\World;
use E10Pro\Zus\zusutils, \e10\str, \e10\Utility;
use \Shipard\Utils\Utils;


/**
 * class ExcuseEngine
 */
class ExcuseEngine extends Utility
{
  var $excuseNdx = 0;
  var $excuseRecData = NULL;
  var $studentNdx = 0;

  var $timeTable = NULL;
  var $affectedHours = [];

  public function setExcuse($excuseNdx)
  {
    $this->excuseNdx = $excuseNdx;
    $this->excuseRecData = $this->app()->loadItem($excuseNdx, 'e10pro.zus.omluvenky');
    $this->studentNdx = $this->excuseRecData['student'] ?? 0;
  }

  public function loadAffectedHours()
  {
    $this->loadTimeTable();

    $dd = Utils::createDateTime($this->excuseRecData['datumOd']);
    while (1)
    {
			$dow = intval($dd->format('N')) - 1;

      foreach ($this->timeTable as $ttd)
      {
        if ($ttd['dow'] !== $dow)
          continue;

				if ($this->excuseRecData['pouzitCasOdDo'])
				{
					if ($this->excuseRecData['casOd'] > $ttd['casDo'])
						continue;
					if ($this->excuseRecData['casDo'] < $ttd['casOd'])
						continue;
				}
        $ah = [
          'day' => Utils::datef($dd, '%n'),
          'date' => Utils::datef($dd, '%d'),
          'time' => $ttd['doba'],
          'teacher' => $ttd['ucitel'],
          'subject' => $ttd['predmet'],

          'teacherNdx' => $ttd['teacherNdx'],
          'etkNdx' => $ttd['etkNdx'],
          'ttNdx' => $ttd['ttNdx'],
          'cdate' => Utils::createDateTime($dd),
        ];

        $this->affectedHours [] = $ah;
      }

			$dd->add(new \DateInterval('P1D'));
      if ($dd > $this->excuseRecData['datumDo'])
        break;
    }
  }

  public function saveAffectedHours()
  {
    $this->db()->query('DELETE FROM [e10pro_zus_omluvenkyHodiny] WHERE [omluvenka] = %i', $this->excuseNdx);

    foreach ($this->affectedHours as $ah)
    {
      $newHour = [
        'omluvenka' => $this->excuseNdx,
        'datum' => $ah['cdate'],
        'vyuka' => $ah['etkNdx'],
        'rozvrh' => $ah['ttNdx'],
      ];

      $this->db()->query('INSERT INTO [e10pro_zus_omluvenkyHodiny] ', $newHour);
    }
  }

  public function loadTimeTable ()
	{
		$tableRozvrh = $this->app()->table('e10pro.zus.vyukyrozvrh');
		$nazvyDnu = $tableRozvrh->columnInfoEnum ('den', 'cfgText');
		$rozvrh = [];
		$vyukyStudenta = $this->loadETKs ($this->studentNdx);

		if (!count($vyukyStudenta))
			return;

		$today = utils::today();

		$q = [];
    array_push($q, 'SELECT rozvrh.*, ');
    array_push($q, ' persons.fullName as personFullName, predmety.nazev as predmet, vyuky.typ as typVyuky, ');
    array_push($q, ' vyuky.rocnik as rocnik, ');
    array_push($q, ' places.shortName as placeName, ucebny.shortName as ucebnaName, vyuky.datumZahajeni, vyuky.datumUkonceni');
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
        'dow' => $r['den'],
				'den' => [
					['icon' => 'system/actionOpen', 'text' => '', 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk'=> $r['vyuka'],
						'type' => 'button', 'actionClass' => 'e10-off'],
					['text' => ' '.$nazvyDnu[$r['den']]]
				],
				'doba' => $r['zacatek'].' - '.$r['konec'],
				'casOd' => $r['zacatek'], 'casDo' => $r['konec'],
				'ucitel' => $r['personFullName'],
				'predmet' => ['icon' => $ikonaPredmet, 'text' => $r['predmet']],
				'rocnik' => zusutils::rocnikVRozvrhu($this->app(), $r['rocnik'], $r['typVyuky']),
				'pobocka' => $r['placeName'],
				'ucebna' => $r['ucebnaName'],

        'teacherNdx' => $r['ucitel'],
        'etkNdx' => $r['vyuka'],
        'ttNdx' => $r['ndx'],
			];

			if (!utils::dateIsBlank($r['datumUkonceni']) && $r['datumUkonceni'] < $today)
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';
			elseif (!utils::dateIsBlank($r['datumZahajeni']) && $r['datumZahajeni'] > $today)
				$itm['_options']['class'] = 'e10-bg-t9 e10-off';

			$rozvrh[] = $itm;
		}


    $this->timeTable = $rozvrh;
	}

	public function loadETKs ($studentNdx)
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
}
