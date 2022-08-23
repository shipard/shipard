<?php

namespace e10pro\zus;
use \e10\utils;


/**
 * Class ModuleServices
 * @package e10pro\zus
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2020-12-31', 'sql' => "UPDATE e10pro_zus_hodiny SET stavHlavni = 3 WHERE stav = 4000 AND stavHlavni = 2"];

		$s [] = ['end' => '2021-02-28', 'sql' => "DELETE FROM e10pro_zus_vyukystudenti WHERE studium = 0 OR studium IS NULL"];
		$s [] = ['end' => '2021-02-28', 'sql' => "DELETE FROM e10pro_zus_hodinydochazka WHERE student = 0 OR student IS NULL"];

		$this->doSqlScripts ($s);

		//$this->upgradeSkupinoveDochazkyBezStudentu();
	}

	public function anonymizeVyuky ()
	{
		$q [] = 'SELECT vyuky.*, studenti.fullName as jmenoStudenta';
		array_push($q, ' FROM [e10pro_zus_vyuky] as vyuky ');
		array_push($q, ' LEFT JOIN e10_persons_persons AS studenti ON vyuky.student = studenti.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_studium AS studium ON vyuky.studium = studium.ndx');

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['typ'] == 1)
				$this->app->db()->query ('UPDATE [e10pro_zus_vyuky] SET nazev = %s', $r['jmenoStudenta'], ' WHERE ndx = ', $r['ndx']);
			else
				$this->app->db()->query ('UPDATE [e10pro_zus_vyuky] SET nazev = %s', mt_rand(1, 4).'. skupina '.strtoupper($this->app->faker->randomLetter()), ' WHERE ndx = ', $r['ndx']);
		}
	}

	public function anonymizeStudia ()
	{
		$q [] = 'SELECT studium.*, ucitel.fullName as ucitelFullName, student.fullName as studentFullName, student.lastName as studentLastName, student.company as studentCompany, student.gender as studentGender, places.fullName as placeName';
		$q [] = ' FROM [e10pro_zus_studium] as studium ';
		$q [] = ' LEFT JOIN e10_persons_persons AS ucitel ON studium.ucitel = ucitel.ndx ';
		$q [] = ' LEFT JOIN e10_persons_persons AS student ON studium.student = student.ndx ';
		$q [] = ' LEFT JOIN e10_base_places AS places ON studium.misto = places.ndx ';
		$q [] = ' WHERE 1';

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['student'] != 0)
			{
				$nazev = $r ['studentFullName'];
				$nazev .= ' ('.$r['cisloStudia'].')';
				$nazev .= ' / '.$this->app->cfgItem ("e10pro.zus.oddeleni.{$r ['svpOddeleni']}.nazev");
				$nazev .= ' / '.$this->app->cfgItem ("e10pro.zus.roky.{$r ['skolniRok']}.nazev");
				$this->app->db()->query ('UPDATE [e10pro_zus_studium] SET nazev = %s', $nazev, ' WHERE ndx = ', $r['ndx']);
			}
		}
	}

	public function anonymizePobocky ()
	{
		$q [] = 'SELECT places.* FROM [e10_base_places] AS places';
		array_push ($q, ' WHERE places.[placeType] = %s', 'lcloffc');

		$cities = [];

		$rows = $this->app->db()->query ($q);
		foreach ($rows as $r)
		{
			while (1)
			{
				$city = $this->app->faker->city;
				if (!in_array($city, $cities))
					break;
			}
			$cities[] = $city;

			$street = $this->app->faker->streetName;
			$fullName = $city.', '.$street;

			$this->app->db()->query ('UPDATE [e10_base_places] SET fullName = %s', $fullName, ', shortName = %s', $city, ' WHERE ndx = ', $r['ndx']);
		}
	}

	public function onAnonymize ()
	{
		$this->anonymizePobocky();
		$this->anonymizeStudia();
		$this->anonymizeVyuky();
	}

	protected function upgradeSkupinoveDochazkyBezStudentu()
	{
		$qd = [];
		array_push ($qd, 'SELECT dochazka.*, studia.student AS studiumStudent');
		array_push ($qd, ' FROM e10pro_zus_hodinydochazka AS dochazka');
		array_push ($qd, ' LEFT JOIN e10pro_zus_studium AS studia ON dochazka.studium = studia.ndx');
		array_push ($qd, ' WHERE dochazka.student = %i', 0);
		array_push ($qd, ' ORDER BY dochazka.ndx');
		$rowsDochazka = $this->db()->query($qd);
		foreach ($rowsDochazka as $rd)
		{
			if (!$rd['studiumStudent'])
			{
				echo "ERROR: chybne/neexistujici studium v kolektivni dochazce\n";
				continue;
			}
			$this->db()->query ('UPDATE e10pro_zus_hodinydochazka SET student = %i', $rd['studiumStudent'], ' WHERE [ndx] = %i', $rd['ndx']);
		}
	}

	protected function upgradeSkupinoveDochazky()
	{
		$this->upgradeSkupinoveDochazky_OnePart(1);
		$this->upgradeSkupinoveDochazky_OnePart(0);
	}

	protected function upgradeSkupinoveDochazky_OnePart($singleRows)
	{
		$q = [];
		array_push ($q, ' SELECT vyuky.nazev AS nazevVyuky, vyuky.skolniRok AS skolniRokVyuky, vyuky.ndx AS vyukaNdx, dochazka.hodina AS hodinaNdx,');
		array_push ($q, ' dochazka.student AS studentNdx, studenti.fullName as jmenoStudenta, COUNT(dochazka.student) AS cnt,');
		array_push ($q, ' hodiny.datum AS hodinaDatum');
		array_push ($q, ' FROM e10pro_zus_hodinydochazka AS dochazka');
		array_push ($q, ' LEFT JOIN e10pro_zus_hodiny AS hodiny ON dochazka.hodina = hodiny.ndx');
		array_push ($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS studenti ON dochazka.student = studenti.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND (dochazka.studium = 0 OR dochazka.studium IS NULL)');
		//array_push ($q, ' AND vyuky.skolniRok = %s', '2020');
		array_push ($q, ' GROUP BY hodina, dochazka.student');

		if ($singleRows)
			array_push ($q, ' HAVING cnt = 1');
		else
			array_push ($q, ' HAVING cnt > 1');
		//array_push ($q, ' limit %i', 2500);

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			//echo $r['nazevVyuky'] . " ". $r['skolniRokVyuky'].": " . $r['jmenoStudenta'] . "\n";
			if ($cnt % 1000 === 0)
				echo sprintf("%06d ", $cnt);
			if ($cnt && $cnt % 10000 === 0)
				echo "\n";

			// -- nacteni studii
			// studia z vyuky
			$studiaStudenta = [];
			$rowsStudia = $this->db()->query('SELECT vs.* FROM e10pro_zus_vyukystudenti AS vs ',
				' LEFT JOIN e10pro_zus_studium AS studia ON vs.studium = studia.ndx',
				' WHERE studia.student = %i', $r['studentNdx'], ' AND vs.vyuka = %i', $r['vyukaNdx'], ' ORDER BY vs.ndx');
			foreach ($rowsStudia as $rs)
				$studiaStudenta[] = $rs['studium'];
			// stara studia z archivu
			$rowsStudia = $this->db()->query('SELECT studia.* FROM e10pro_zus_studium AS studia ',
				' WHERE studia.student = %i', $r['studentNdx'], ' AND studia.skolniRok = %s', $r['skolniRokVyuky'], ' ORDER BY studia.stavHlavni, studia.ndx');
			foreach ($rowsStudia as $rs)
				if (!in_array($rs['ndx'], $studiaStudenta))
					$studiaStudenta[] = $rs['ndx'];


			//echo \dibi::$sql."\n";
			//echo " --> studia: ".json_encode($studiaStudenta)."";


			// -- update hodin
			$qd = [];
			array_push ($qd, 'SELECT * ');
			array_push ($qd, ' FROM e10pro_zus_hodinydochazka');
			array_push ($qd, ' WHERE student = %i', $r['studentNdx']);
			array_push ($qd, ' AND hodina = %i', $r['hodinaNdx']);
			array_push ($qd, ' ORDER BY ndx');
			$rowsDochazka = $this->db()->query($qd);
			$stc = 0;
			foreach ($rowsDochazka as $rd)
			{
				if (!isset($studiaStudenta[$stc]))
				{
					echo "\n--> ERROR ON ROW {$stc}: hodina ".json_encode($rd->toArray())."\n";
					echo " --> studia: ".json_encode($studiaStudenta)."\n";
					echo " -->" . $r['nazevVyuky'] . " ". $r['skolniRokVyuky'].": " . $r['jmenoStudenta']." - ".utils::datef($r['hodinaDatum']);
					echo "\n";
					continue;
				}
				//echo "   --> hodina: ".json_encode($rd->toArray())."\n";
				$this->db()->query ('UPDATE e10pro_zus_hodinydochazka SET studium = %i', $studiaStudenta[$stc], ' WHERE [ndx] = %i', $rd['ndx']);
				$stc++;
				//echo ".";
			}

			//echo "\n";

			$cnt++;
		}


		echo "\n------TOTAL: $cnt \n\n";
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'upgrade-skupinove-dochazky': return $this->upgradeSkupinoveDochazky();
			case 'send-entries-emails': return $this->sendEntriesEmails();
		}

		parent::onCliAction($actionId);
	}

	public function sendEntriesEmails()
	{
		$e = new \e10pro\zus\libs\SendEntriesEmails($this->app());
		$e->sendAll();
	}

	public function onCronEver ()
	{
		$this->sendEntriesEmails();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'ever': $this->onCronEver(); break;
		}
		return TRUE;
	}

}
