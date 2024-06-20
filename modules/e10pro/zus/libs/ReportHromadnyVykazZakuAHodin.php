<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils;


/**
 * class ReportHromadnyVykazZakuAHodin
 */
class ReportHromadnyVykazZakuAHodin extends \E10\GlobalReport
{
  function init ()
	{
		$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		parent::init();

		$this->setInfo('icon', 'tables/e10pro.zus.studium');
		$this->setInfo('title', 'Hromadný tisk Výkazu žáků a vyučovaných hodin');
	}

  function createContent ()
	{
		$tablePersons = $this->app()->table('e10.persons.persons');
		$obory = $this->app()->cfgItem('e10pro.zus.obory');

		$q = [];
		array_push ($q, 'SELECT hodiny.ucitel, teachers.fullName as teacherFullName, vyuky.svpObor');
		array_push ($q, ' FROM [e10pro_zus_hodiny] AS [hodiny]');
		array_push ($q, ' LEFT JOIN [e10pro_zus_vyuky] AS vyuky ON hodiny.vyuka = vyuky.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS teachers ON hodiny.ucitel = teachers.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND hodiny.ucitel != %i', 0);
		array_push ($q, ' AND hodiny.stavHlavni = %i', 3);
		array_push ($q, " AND vyuky.[skolniRok] = %s", $this->reportParams ['skolniRok']['value']);
		array_push ($q, ' GROUP BY 1, 2, 3');
    array_push ($q, " ORDER BY teachers.lastName, teachers.firstName");

    $rows = $this->app->db()->query ($q);

		$data = [];

		forEach ($rows as $r)
		{
      $item = [
				'ucitel' => ['text'=> $r['teacherFullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r['ucitel']],
				'cnt' => $r['cnt'],
				'obor' => $obory[$r['svpObor']]['nazev'] ?? '!!!',
				'print' => 	[
					'type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => 'Výkaz',
					'data-report' => 'e10pro.zus.libs.ReportVykazZakuAHodin',
					'data-table' => 'e10.persons.persons', 'data-pk' => $r['ucitel'], 'actionClass' => 'btn-xs', 'class' => 'pull-right',
					'data-param-school-year' => $this->reportParams ['skolniRok']['value']
				]
			];


			$tr = ['ndx' => $r['ucitel']];

			/*
			$rvtk = new \e10pro\zus\libs\ReportVykazTridnichKnih($tablePersons, $tr);
			$rvtk->schoolYearId = $this->reportParams ['skolniRok']['value'];
			$rvtk->loadData2();

			if (isset($rvtk->sums['h1']['v1']))
				$item['h1v1'] = $rvtk->sums['h1']['v1'];
			if (isset($rvtk->sums['h1']['v2']))
				$item['h1v2'] = $rvtk->sums['h1']['v2'];
			if (isset($rvtk->sums['h1']['v3']))
				$item['h1v3'] = $rvtk->sums['h1']['v3'];
			if (isset($rvtk->sums['h1']['v0']))
				$item['h1v0'] = $rvtk->sums['h1']['v0'];
			if (isset($rvtk->sums['h1']['ps']))
				$item['h1ps'] = $rvtk->sums['h1']['ps'];

			if (isset($rvtk->sums['h2']['v1']))
				$item['h2v1'] = $rvtk->sums['h2']['v1'];
			if (isset($rvtk->sums['h2']['v2']))
				$item['h2v2'] = $rvtk->sums['h2']['v2'];
			if (isset($rvtk->sums['h2']['v3']))
				$item['h2v3'] = $rvtk->sums['h2']['v3'];
			if (isset($rvtk->sums['h2']['v0']))
				$item['h2v0'] = $rvtk->sums['h2']['v0'];
			if (isset($rvtk->sums['h2']['ps']))
				$item['h2ps'] = $rvtk->sums['h2']['ps'];
			*/

			$data[] = $item;
    }

		$h = [
			'#' => '#',
			'ucitel' => 'Učitel',
			'cnt' => '+Studia',
			'obor' => 'Obor'

		];

		if ($this->format !== 'pdf')
			$h['print'] = 'Tisk';

		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);
  }
}

