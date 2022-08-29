<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils, \e10\utils;


/**
 * Class ReportKontrolaETK
 * @package e10pro\zus\libs
 */
class ReportKontrolaETK extends \E10\GlobalReport
{
	function init ()
	{
		$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		parent::init();

		$this->setInfo('icon', 'tables/e10pro.zus.vyuky');
		$this->setInfo('title', 'Kontrola ETK');
	}

	function createContent ()
	{
		//$this->kontrolaNevyplnennychStudetuVETK();
		//$this->kontrolaNevyplnennychStudetuVDochazce();
		//$this->kontrolaNevyplnennychStudetuVDochazce(1);

		$this->kontrolaSkupinovychETK();
	}

	protected function kontrolaSkupinovychETK()
	{
		$q = [];
		array_push($q, 'SELECT vyuky.*, ');
		array_push($q, ' pobocky.shortName AS pobockaNazev, ucitele.fullName AS ucitelJmeno');
		array_push($q, ' FROM [e10pro_zus_vyuky] AS vyuky');
		array_push($q, ' LEFT JOIN e10_base_places AS pobocky ON vyuky.misto = pobocky.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS ucitele ON vyuky.ucitel = ucitele.ndx');
		array_push($q, ' WHERE 1');
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->reportParams ['skolniRok']['value']);


		//array_push($q, ' AND [vyuky].[typ] = %i', 0); // kolektivní
		array_push($q, ' AND [vyuky].[stavHlavni] != %i', 4);

		array_push($q, ' ORDER BY vyuky.nazev, pobocky.shortName');

		$counter = 1;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['typ'] === 0)
			{
				$k = new \e10pro\zus\libs\KontrolaKolektivnichETK($this->app());
				$k->vyukaNdx = $r['ndx'];
				$k->init();
				$k->run();

				if (count($k->troubles))
				{
					$hr = ['#' => '#', 'date' => ' Datum', 'msg' => 'Problém', ];
					$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $hr, 'table' => $k->troubles,
							'title' => [
								['text' => $r['nazev'], 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk' => $r['ndx'], 'icon' => 'system/iconWarning', 'class' => 'h1 e10-error'],
								['text' => '#'.utils::nf($counter), 'class' => 'break'],
								['text' => $r['pobockaNazev'], 'class' => '', 'icon' => 'icon-map-marker'],
								['text' => $r['ucitelJmeno'], 'class' => '', 'icon' => 'x-teacher'],
							],
						]);
						$counter++;
				}
			}
			else
			{
				$k = new \e10pro\zus\libs\KontrolaIndividualnichETK($this->app());
				$k->vyukaNdx = $r['ndx'];
				$k->init();
				$k->run();

				if (count($k->troubles))
				{
					$hr = ['#' => '#', 'date' => ' Datum', 'msg' => 'Problém', ];
					$this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $hr, 'table' => $k->troubles,
							'title' => [
								['text' => $r['nazev'], 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk' => $r['ndx'], 'icon' => 'system/iconWarning', 'class' => 'h1 e10-error'],
								['text' => '#'.utils::nf($counter), 'class' => 'break'],
								['text' => $r['pobockaNazev'], 'class' => '', 'icon' => 'icon-map-marker'],
								['text' => $r['ucitelJmeno'], 'class' => '', 'icon' => 'x-teacher'],
							],
						]);
						$counter++;
				}
			}
		}
	}

	protected function kontrolaNevyplnennychStudetuVETK()
	{
		$q = [];
		array_push($q, 'SELECT vs.*, ');
		array_push($q, ' vyuky.nazev AS vyukaNazev, ');
		array_push($q, ' pobocky.shortName AS pobockaNazev, ucitele.fullName AS ucitelJmeno');
		array_push($q, ' FROM [e10pro_zus_vyukystudenti] AS vs');
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON vs.vyuka = vyuky.ndx');
		array_push($q, ' LEFT JOIN e10_base_places AS pobocky ON vyuky.misto = pobocky.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS ucitele ON vyuky.ucitel = ucitele.ndx');


		//array_push($q, ' LEFT JOIN e10_persons_persons AS studenti ON dochazka.student = studenti.ndx');
		array_push($q, '');
		array_push($q, '');
		array_push($q, ' WHERE 1');
		array_push ($q, ' AND vyuky.skolniRok = %s', $this->reportParams ['skolniRok']['value']);

		array_push($q, ' AND (vs.studium = %i', 0, ' OR vs.studium IS NULL)');
		array_push($q, ' AND [vyuky].[typ] = %i', 0); // kolektivní
		array_push($q, ' AND [vyuky].[stavHlavni] != %i', 4);

		array_push($q, ' ORDER BY vyuky.nazev, pobocky.shortName, vs.ndx');
		array_push($q, ' ');

		$rows = $this->db()->query($q);

		$t = [];
		foreach ($rows as $r)
		{
			$item = [
				'pobocka' => $r['pobockaNazev'], 'ucitel' => $r['ucitelJmeno']
			];

			$item['vyuka'] = ['text' => $r['vyukaNazev'], 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk' => $r['vyuka']];

			$t[] = $item;
		}

		if (count($t))
		{
			$h = ['#' => '#', 'vyuka' => 'ETK', 'ucitel' => 'Učitel', 'pobocka' => 'Pobočka'];
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE, 'title' => 'Skupinové ETK s prázdným studiem']);
		}
	}

	protected function kontrolaNevyplnennychStudetuVDochazce($chybiStudium = 0)
	{
		$q = [];
		array_push($q, 'SELECT dochazka.*, ');
		array_push($q, ' hodiny.datum AS hodinaDatum, hodiny.zacatek AS hodinaZacatek, hodiny.konec AS hodinaKonec, hodiny.vyuka AS vyukaNdx, ');
		array_push($q, ' studia.nazev AS studiumNazev, studenti.fullName AS studentJmeno,');
		array_push($q, ' vyuky.studium AS studiumNdx, vyuky.nazev AS vyukaNazev');
		array_push($q, '');
		array_push($q, ' FROM [e10pro_zus_hodinydochazka] AS dochazka');
		array_push($q, ' LEFT JOIN e10pro_zus_hodiny AS hodiny ON dochazka.hodina = hodiny.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_vyuky AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON vyuky.studium = studia.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS studenti ON dochazka.student = studenti.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND vyuky.skolniRok = %s', $this->reportParams ['skolniRok']['value']);

		if ($chybiStudium)
			array_push($q, ' AND (dochazka.studium = %i', 0, ' OR dochazka.studium IS NULL)');
		else
			array_push($q, ' AND (dochazka.student = %i', 0, ' OR dochazka.student IS NULL)');

		array_push($q, ' AND [vyuky].[typ] = %i', 0); // kolektivní
		array_push($q, ' AND [vyuky].[stavHlavni] != %i', 4);

		array_push($q, ' ORDER BY vyuky.nazev, hodiny.datum, dochazka.ndx');

		$rows = $this->db()->query($q);

		$t = [];
		foreach ($rows as $r)
		{
			$item = [
				'hodina' => ['text' => utils::datef($r['hodinaDatum']).' '.$r['hodinaZacatek'].' - '.$r['hodinaKonec'], 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $r['hodina']],
				'student' => $r['studentJmeno']
			];

			if ($r['studiumNdx'])
				$item['studium'] = ['text' => $r['studiumNazev'], 'prefix' => strval($r['studiumNdx'])];//json_encode($r->toArray())//$r['studiumNdx'].' - '.$r['studiumNazev'],

			//$item['vyuka'] = ['text' => 'AAA'.$r['vyukaNazev']];//json_encode($r->toArray())//$r['studiumNdx'].' - '.$r['studiumNazev'],
			$item['vyuka'] = ['text' => ($r['vyukaNazev'] !== '') ? $r['vyukaNazev'] : 'BEZ NÁZVU', 'docAction' => 'edit', 'table' => 'e10pro.zus.vyuky', 'pk' => $r['vyukaNdx']];
			$t[] = $item;
		}

		if (count($t))
		{
			$h = ['#' => '#', 'vyuka' => 'ETK', 'student' => 'Student', 'hodina' => 'Hodina'];
			$this->addContent(['type' => 'table', 'header' => $h, 'table' => $t, 'main' => TRUE, 'title' => 'Docházka skupinových ETK bez '. (($chybiStudium) ? 'studia' : 'studenta')]);
		}
	}
}
