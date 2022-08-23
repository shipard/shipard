#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once 'e10-modules/e10/server/php/e10-cli.php';
require_once 'e10-modules/e10doc/core/core.php';
require_once 'e10-modules/e10pro/zus/zus.php';
require_once 'e10-modules/e10pro/zus/zus.php';

use \E10\CLI\Application;


class UpgradeApp extends Application
{
	function createNewVysvedceni ()
	{
		$today = new \DateTime();
		$todayYear = intval($today->format ('Y'));
		$todayMonth = intval($today->format ('M'));
		if ($todayMonth < 7)
			$todayYear--;

		echo "- tvorba Vysvědčení pro další školní rok:\r\n";

		$q [] = 'SELECT studium.*, student.fullName as studentFullName  FROM [e10pro_zus_studium] AS studium';
		$q [] = ' LEFT JOIN e10_persons_persons AS student ON studium.student = student.ndx ';
		$q [] = ' WHERE 1';
		array_push ($q, ' AND [smazano] = %i', 0);
		array_push ($q, ' AND [stavHlavni] < %i', 4);
		array_push ($q, ' AND [skolniRok] = %i', $todayYear);
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
				'stavHlavni' => 0, 'stav' => 1000
			));
			$vysvedceniNdx = $this->db()->getInsertId ();
			echo "*";
			$rows2 = $this->db()->query ("SELECT *  FROM [e10pro_zus_studiumpre] where [studium] = %i ORDER BY [ndx]", $row ['ndx'])->fetchAll ();
			forEach ($rows2 as $row2)
			{
				$this->db()->query ("insert into e10pro_zus_znamky", array ('vysvedceni' => $vysvedceniNdx, 'svpPredmet' => $row2 ['svpPredmet'], 'znamka1p' => 0, 'znamka2p' => 0));
				echo "-";
			}
		}

		echo "\r\n";
	}

	function copyNewStudium ()
	{
    $today = new \DateTime();
		$todayYear = intval($today->format ('Y'));
		$tableStudium = $this->table('e10pro.zus.studium');

    echo "- kopírování Studií pro další školní rok:\r\n";

		// -- import
		$rows = $this->db()->query ("SELECT *  FROM [e10pro_zus_studium] where [smazano] = 0 AND [stavHlavni] < 4 AND [skolniRok] = $todayYear - 1 ORDER BY [ndx]")->fetchAll ();
		forEach ($rows as $row)
		{
			if ($row ['stupen'] == 2 && $row ['typVysvedceni'] == 1) // II.stupeň a závěrečné vysvědčení
				continue; // už nepokračuje...konec studia

			$stupen = $row ['stupen'];
			$rocnik = $row ['rocnik'] + 1;
			$typVysvedceni = $row ['typVysvedceni'];

			// Přechod z přípravného ročníku I.stupně do prvního ročníku
			if ($stupen == 1 && $row ['rocnik'] == 13)
			{
				$rocnik = 1; // 1.ročník I.stupně
				$typVysvedceni = 0; // normální Vysvědčení
			}

			// Přechod z přípravného ročníku II.stupně do prvního ročníku
			if ($stupen == 2 && $row ['rocnik'] == 14)
			{
				$rocnik = 8; // 1.ročník II.stupně
				$typVysvedceni = 0; // normální Vysvědčení
			}

			// Přechod na II.stupeň
			if ($stupen == 1 && ($row ['typVysvedceni'] == 1 || $row ['rocnik'] == 7)) // závěrečné vysvědčení, případně sedmý ročník I.stupně
			{
				$stupen++; // přechází na II.stupeň
				$rocnik = 8; // 1.ročník II.stupně
				$typVysvedceni = 0; // normální Vysvědčení
			}

			// 4.ročník II.stupně má jenom Závěrečné vysvědčení
			if ($stupen == 2 && $rocnik == 11)
			{
				$typVysvedceni = 1; // závěrečné Vysvědčení
			}

			$rocnikCfg = $this->cfgItem('e10pro.zus.rocniky.'.$rocnik, NULL);
			if ($rocnikCfg && isset($rocnikCfg['typVysvedceni']))
				$typVysvedceni = $rocnikCfg['typVysvedceni'];

			if ($rocnik == 1)
				$typVysvedceni = 0;

			$item = [
				'student' => $row ['student'], 'ucitel' => $row ['ucitel'],
				'typVysvedceni' => $typVysvedceni,
				'skolniRok' => $row ['skolniRok'] + 1,
				'poradoveCislo' => $row ['cisloStudia'],
				'svp' => $row ['svp'], 'svpObor' => $row ['svpObor'], 'svpOddeleni' => $row ['svpOddeleni'],
				'rocnik' => $rocnik, 'stupen' => $stupen, 'urovenStudia' => $row ['urovenStudia'],
				'cisloStudia' => $row ['cisloStudia'],

				'skolnePrvniPol' => $row ['skolneDruhePol'],
				'skolSlPrvniPol' => $row ['skolSlDruhePol'],
				'skolVyPrvniPol' => $row ['skolVyDruhePol'],
				'skolneDruhePol' => $row ['skolneDruhePol'],
				'skolSlDruhePol' => $row ['skolSlDruhePol'],
				'skolVyDruhePol' => $row ['skolVyDruhePol'],
				'bezDotace' => $row ['bezDotace'],
				'oznaceniStudia' => $row ['oznaceniStudia'],
				'pobocka' => $row ['pobocka'],
				'misto' => $row ['misto'],
				'stavHlavni' => 1, 'stav' => 1200,
				'datumNastupuDoSkoly' => $row ['datumNastupuDoSkoly'], 'datumUkonceniSkoly' => $row ['datumUkonceniSkoly']
			];

			$tableStudium->checkBeforeSave($item);

			// -- kopie studií
			$this->db()->query ("insert into e10pro_zus_studium", $item);
			$studiumNdx = $this->db()->getInsertId ();
      echo "*";
      $rows2 = $this->db()->query ("SELECT *  FROM [e10pro_zus_studiumpre] where [studium] = %i ORDER BY [ndx]", $row ['ndx'])->fetchAll ();
      forEach ($rows2 as $row2)
      {
        $this->db()->query ("insert into e10pro_zus_studiumpre", array ('studium' => $studiumNdx, 'svpPredmet' => $row2 ['svpPredmet'], 'ucitel' => $row2 ['ucitel']));
        echo "-";
      }
    }

		echo "\r\n";
	}

  public function createPredpisSkolneho ($pololeti)
	{
    $h = new createPredpisSkolneho ($this);
    $h->run ($pololeti);
  }

	public function upgradeVysvedceni ()
	{
		echo "--- upgradeVysvedceni --- \n";

		$rows = $this->db()->query ("SELECT * FROM [e10pro_zus_vysvedceni] where [stav] != 9800 AND studium = 0 ORDER BY [ndx]");
		foreach ($rows as $r)
		{
			//echo ("#{$r['ndx']} - {$r['jmeno']} ({$r['skolniRok']}/{$r['rocnik']}): ");

			$qs[] = "SELECT * FROM [e10pro_zus_studium] WHERE ";
			array_push($qs, '[student] = %i', $r['student'],
											' AND [skolniRok] = %i', $r['skolniRok'],
											' AND [rocnik] = %i', $r['rocnik']
											//' AND [poradoveCislo] = %i', $r['poradoveCislo']
			);
			array_push($qs, 'ORDER BY [ndx]');
			$studium = $this->db()->query ($qs)->fetch();
			if ($studium)
			{
				//echo ("found #{$studium['ndx']}");
				$this->db()->query ("UPDATE [e10pro_zus_vysvedceni] SET studium = %i where [ndx] = %i", $studium['ndx'], $r['ndx']);
			}
			else
			{
				//echo ("none.");
			}
			//echo ("\n");

			unset ($qs);
		}
	}

	public function upgradeStudia ()
	{
		echo "--- upgradeStudia --- \n";

		$q = "SELECT studium.*, student.fullName as studentName FROM [e10pro_zus_studium] as studium ".
				" LEFT JOIN e10_persons_persons AS student ON studium.student = student.ndx ".
				" WHERE studium.nazev = '' order by studentName";
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['student'])
			{
				$nazev = $r ['studentName'];
				$nazev .= ' / '.$this->cfgItem ("e10pro.zus.oddeleni.{$r ['svpOddeleni']}.nazev");
				$nazev .= ' - '.$this->cfgItem ("e10pro.zus.roky.{$r ['skolniRok']}.nazev");
				$newRec ['nazev'] = substr($nazev, 0, 100);

				$this->db()->query ("UPDATE [e10pro_zus_studium] SET ", $newRec, " where [ndx] = %i", $r['ndx']);
				//echo '- '.$nazev."\n";
			}
		}
	}

	public function upgradeKlasifikace ()
	{
		echo "--- upgradeKlasifikace --- \n";

		$q = "SELECT hodnoceni.*, vyuky.nazev as nazevVyuky FROM [e10pro_zus_hodnoceni] as hodnoceni ".
				"LEFT JOIN e10pro_zus_vyuky AS vyuky ON hodnoceni.vyuka = vyuky.ndx ";

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			echo "* ".$r['nazevVyuky']." ".$r['znamka']."\n";

			$item = ['klasifikaceZnamka' => $r['znamka'], 'klasifikacePoznamka' => $r['poznamka']];
			$this->db()->query ('UPDATE [e10pro_zus_hodiny] SET ', $item, ' WHERE ndx = %i', $r['hodina']);
		}
	}

	public function upgradePritomnosti ()
	{
		echo "--- upgradePritomnosti --- \n";

		$q = "SELECT dochazka.* FROM [e10pro_zus_hodinydochazka] as dochazka";

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['pritomnost' => $r['pritomnost']];
			$this->db()->query ('UPDATE [e10pro_zus_hodiny] SET ', $item, ' WHERE ndx = %i', $r['hodina']);
		}
	}


	public function tvorbaVyuky ()
	{
		$v = new \E10Pro\Zus\DoplneniVyukEngine($this);
		$v->setParams('2022', FALSE, 'xxxxx');
		$v->run ();
	}

	public function run ()
	{
		switch ($this->command ())
		{
			case	"skolne1": return $this->createPredpisSkolneho (1);
			case	"skolne2": return $this->createPredpisSkolneho (2);
			case	"nove_vysvedceni": return $this->createNewVysvedceni ();
			case	"nove_studium": return $this->copyNewStudium ();

			case	"tvorbaVyuky": return $this->tvorbaVyuky ();

			case	"upgradeKlasifikace": return $this->upgradeKlasifikace();
			case	"upgradePritomnosti": return $this->upgradePritomnosti();
			case	"upgradeStudia": return $this->upgradeStudia();
		}
		echo ("unknown or nothing param...\r\n");
	}
}

$myApp = new UpgradeApp ($argv);
$myApp->run ();

echo ("DONE.\n");
