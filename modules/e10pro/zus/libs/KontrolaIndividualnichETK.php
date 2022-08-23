<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils, \e10\utils, \e10\str, \e10\Utility;

class KontrolaIndividualnichETK extends Utility
{
	var $vyukaNdx = 0;
	var $studenti = [];
	var $troubles = [];

	public function init()
	{
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
		}
	}

	public function run()
	{
		$this->kontrolaHodin();
	}
}