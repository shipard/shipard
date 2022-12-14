<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils, \e10\utils;


/**
 * Class ReportKontrolaStudii
 */
class ReportKontrolaStudii extends \E10\GlobalReport
{
	function init ()
	{
		$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		parent::init();

		$this->setInfo('icon', 'tables/e10pro.zus.vyuky');
		$this->setInfo('title', 'Kontrola Studií');
	}

	function createContent ()
	{
		$this->kontrolaStudii();
	}

	protected function kontrolaStudii()
	{
		$q = [];
		array_push($q, 'SELECT studia.* ');
		array_push($q, ' FROM [e10pro_zus_studium] AS studia');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND studia.skolniRok = %s', $this->reportParams ['skolniRok']['value']);
		array_push($q, ' AND [studia].[stavHlavni] != %i', 4);
		array_push($q, ' ORDER BY studia.nazev');

		$counter = 1;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $ks = new \e10pro\zus\libs\KontrolaStudia($this->app());
      $ks->setStudium($r['ndx']);
      $ks->run();

      if (count($ks->troubles))
      {
        $hr = ['#' => '#', 'msg' => 'Problém', ];
        $this->addContent(['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $hr, 'table' => $ks->troubles,
            'title' => [
              ['text' => $r['poradoveCislo'].': '.$r['nazev'], 'docAction' => 'edit', 'table' => 'e10pro.zus.studia', 'pk' => $r['ndx'], 'icon' => 'system/iconWarning', 'class' => 'h1 e10-error'],
              ['text' => '#'.utils::nf($counter), 'class' => 'break'],
            ],
            'params' => ['hideHeader' => 1]
          ]);
        $counter++;
      }
		}
	}
}
