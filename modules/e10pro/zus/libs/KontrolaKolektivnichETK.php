<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils, \e10\utils, \e10\str, \e10\Utility;

class KontrolaKolektivnichETK extends Utility
{
	var $vyukaNdx = 0;
	var $studenti = [];
	var $troubles = [];

	public function init()
	{
	}

	function nacistStudenty()
	{
		$q[] = 'SELECT studenti.*, studia.student as studentNdx, studia.stav as stavStudia,';
		array_push($q, ' studia.datumUkonceniSkoly, studia.datumNastupuDoSkoly, studia.nazev AS studiumNazev, studentiOsoby.fullName AS studentJmeno');
		array_push($q, ' FROM e10pro_zus_vyukystudenti AS studenti');
		array_push($q, ' LEFT JOIN e10pro_zus_studium AS studia ON studenti.studium = studia.ndx');
		array_push($q, ' LEFT JOIN e10_persons_persons AS studentiOsoby ON studia.student = studentiOsoby.ndx');
		array_push($q, ' WHERE [vyuka] = %i', $this->vyukaNdx);

		$studenti = $this->db()->query ($q);
		foreach ($studenti as $r)
		{
			if (!$r['studium'])
			{
				$this->troubles[] = [
					'msg' => 'V seznamu studentů je prázné studium',
				];
				continue;
			}
			if ($r['stavStudia'] === 9800)
			{
				$this->troubles[] = [
					'msg' => 'V seznamu studentů je smazané studium: '.$r['studiumNazev'],
				];
			}

			if (isset($this->studenti[$r['studium']]))
			{
				$this->troubles[] = [
					'msg' => 'V seznamu studentů je vícekrát stejné studium: '.$r['studiumNazev']
				];
			}

			$this->studenti[$r['studium']] = $r->toArray();
		}
	}

	function kontrolaHodin()
	{
		$q[] = 'SELECT * FROM e10pro_zus_hodiny WHERE 1';
		array_push($q, ' AND vyuka = %i', $this->vyukaNdx);
		array_push($q, ' AND stav != %i', 9800);
		array_push($q, ' ORDER BY [datum], [zacatek]');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['stav'] != 4000)
			{
				$this->troubles[] = [
					'msg' => 'Hodina není uzavřena',
					'date' => ['text' => utils::datef($r['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $r['ndx']],
				];
			}

			$hourLenMinutes = utils::timeToMinutes($r['konec']) - utils::timeToMinutes($r['zacatek']);
			//$hourLen = intval((utils::timeToMinutes ($r['konec']) - utils::timeToMinutes ($r['zacatek'])) / 45);
			if ($hourLenMinutes < 40)
			{
				$this->troubles[] = [
					'msg' => 'Hodina má špatně nastavený čas začátku nebo konce ('.$hourLenMinutes.' minut)',
					'date' => ['text' => utils::datef($r['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $r['ndx']],
				];
			}

			$this->kontrolaDochazkyVHodine($r);
		}
	}

	function kontrolaDochazkyVHodine($hodina)
	{
		$q[] = 'SELECT dochazka.*, studia.nazev AS nazevStudia, studia.stav AS stavStudia FROM e10pro_zus_hodinydochazka AS dochazka';
		array_push ($q, ' LEFT JOIN e10pro_zus_studium AS studia ON dochazka.studium = studia.ndx');
		array_push ($q, ' WHERE dochazka.[hodina] = %i', $hodina['ndx']);

		$dochazka = [];

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if (!$r['studium'])
			{
				$this->troubles[] = [
				'msg' => 'V hodině je prázdné studium'/*.json_encode($r->toArray())*/,
					'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
				];

				continue;
			}

			if ($r['stavStudia'] === 9800)
			{
				$this->troubles[] = [
				'msg' => 'V hodině je smazané studium: '.$r['nazevStudia']/*.json_encode($r->toArray())*/,
					'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
				];
			}

			if (!isset($this->studenti[$r['studium']]))
			{
				$this->troubles[] = [
				'msg' => 'V hodině je student nepatřící do ETK: '.$r['nazevStudia']/*.json_encode($r->toArray())*/,
					'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
				];
			}

			if (isset($dochazka[$r['studium']]))
			{
				$this->troubles[] = [
					'msg' => 'V hodině je vícekrát stejné studium: '.$r['nazevStudia'],
					'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
				];
			}

			$dochazka[$r['studium']] = $r->toArray();
		}

		foreach ($this->studenti as $studiumNdx => $studentETK)
		{
			if (!isset($dochazka[$studiumNdx]))
			{
				if ($studentETK['datumUkonceniSkoly'] && $studentETK['datumUkonceniSkoly'] < $hodina['datum'])
				{
					continue;
				}
				if ($studentETK['datumNastupuDoSkoly'] && $studentETK['datumNastupuDoSkoly'] > $hodina['datum'])
				{
					continue;
				}

				if ($studentETK['platnostDo'] && $studentETK['platnostDo'] < $hodina['datum'])
				{
					continue;
				}
				if ($studentETK['platnostOd'] && $studentETK['platnostOd'] > $hodina['datum'])
				{
					continue;
				}

				$this->troubles[] = [
					'msg' => 'V hodině '.utils::datef($hodina['datum']).' chybí student patřící do ETK: '.$studentETK['studiumNazev']/*.json_encode($r->toArray())*/,
					'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
				];
			}
			else
			{
				$daysAfter = utils::dateDiff($studentETK['datumUkonceniSkoly'], $hodina['datum']);
				if ($daysAfter > 45)
				{
					$this->troubles[] = [
						'msg' => 'V hodině je student, který ukončil studium k '.utils::datef($studentETK['datumUkonceniSkoly']).' ('.$daysAfter.' dnů): '.$studentETK['studiumNazev']/*.json_encode($r->toArray())*/,
						'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
					];
				}
				$daysBefore = utils::dateDiff($hodina['datum'], $studentETK['datumNastupuDoSkoly']);
				if ($daysBefore > 60)
				{
					$this->troubles[] = [
						'msg' => 'V hodině je student, který zahájil studium až '.utils::datef($studentETK['datumNastupuDoSkoly']).' ('.$daysBefore.' dnů): '.$studentETK['studiumNazev']/*.json_encode($r->toArray())*/,
						'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
					];
				}

				$daysAfter = utils::dateDiff($studentETK['platnostDo'], $hodina['datum']);
				if ($daysAfter > 0)
				{
					$this->troubles[] = [
						'msg' => 'V hodině je student, který ukončil přítomnost v výuce k '.utils::datef($studentETK['platnostDo']).' ('.$daysAfter.' dnů): '.$studentETK['studiumNazev'],
						'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
					];
				}
				$daysBefore = utils::dateDiff($hodina['datum'], $studentETK['platnostOd']);
				if ($daysBefore > 0)
				{
					$this->troubles[] = [
						'msg' => 'V hodině je student, který zahájil přítomnost ve výuce  '.utils::datef($studentETK['platnostOd']).' ('.$daysBefore.' dnů): '.$studentETK['studiumNazev'],
						'date' => ['text' => utils::datef($hodina['datum']), 'docAction' => 'edit', 'table' => 'e10pro.zus.hodiny', 'pk' => $hodina['ndx']],
					];
				}
			}
		}
	}

	public function run()
	{
		$this->nacistStudenty();
		$this->kontrolaHodin();
	}
}