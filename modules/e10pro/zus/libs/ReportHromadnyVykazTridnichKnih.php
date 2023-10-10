<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use E10Pro\Zus\zusutils;


/**
 * class ReportHromadnyVykazTridnichKnih
 */
class ReportHromadnyVykazTridnichKnih extends \E10\GlobalReport
{
  function init ()
	{
		$this->addParam ('switch', 'skolniRok', ['title' => 'Rok', 'cfg' => 'e10pro.zus.roky', 'titleKey' => 'nazev', 'defaultValue' => zusutils::aktualniSkolniRok()]);

		parent::init();

		$this->setInfo('icon', 'tables/e10pro.zus.studium');
		$this->setInfo('title', 'Hromadný tisk Výkazu třídních knih');
	}

  function createContent ()
	{
		$tablePersons = $this->app()->table('e10.persons.persons');

    $q [] = 'SELECT studium.ucitel, teachers.fullName as teacherFullName, COUNT(*) AS cnt'.
            ' FROM [e10pro_zus_studium] as studium'.
            ' LEFT JOIN e10_persons_persons AS teachers ON studium.ucitel = teachers.ndx'.
            ' WHERE studium.stavHlavni != 4';

		array_push ($q, " AND studium.[skolniRok] = %s", $this->reportParams ['skolniRok']['value']);

		array_push ($q, ' GROUP BY 1, 2');
    array_push ($q, " ORDER BY teachers.lastName, teachers.firstName");

    $rows = $this->app->db()->query ($q);

		$data = array ();

		forEach ($rows as $r)
		{
      $item = [
				'ucitel' => ['text'=> $r['teacherFullName'], 'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $r['ucitel']],
				'cnt' => $r['cnt'],
				'print' => 	[
					'type' => 'action', 'action' => 'print', 'style' => 'print', 'icon' => 'system/actionPrint', 'text' => 'Výkaz',
					'data-report' => 'e10pro.zus.libs.ReportVykazTridnichKnih',
					'data-table' => 'e10.persons.persons', 'data-pk' => $r['ucitel'], 'actionClass' => 'btn-xs', 'class' => 'pull-right',
					'data-param-school-year' => $this->reportParams ['skolniRok']['value']
				]
			];


			$tr = ['ndx' => $r['ucitel']];
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

			$data[] = $item;
    }

		$h = [
			'#' => '#',
			'ucitel' => 'Učitel',
			'cnt' => '+Studia',

			'h1v1' => '+1pV',
			'h1v2' => '+1pP',
			'h1v3' => '+1pN',
			'h1v0' => '+1p-',
			'h1ps' => '+1p PS',

			'h2v1' => '+2pV',
			'h2v2' => '+2pP',
			'h2v3' => '+2pN',
			'h2v0' => '+2p-',
			'h2ps' => '+2p PS',
		];

		if ($this->format !== 'pdf')
			$h['print'] = 'Tisk';

		$this->setInfo('param', $this->reportParams ['skolniRok']['title'], $this->reportParams ['skolniRok']['activeTitle']);

		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $data, 'main' => TRUE]);
  }
}

