<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';

use e10pro\zus\zusutils;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewGrid, \Shipard\Utils\World, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewDailyAttendance
 */
class ViewDailyAttendance extends TableViewGrid
{
	var $now;
	var $date = NULL;
	var $personNdx = 0;
	var $enabledPersons = NULL;

  //var $workRecsItems = [];

	public function init ()
	{
		$this->now = new \DateTime();

		$this->topParams = new \e10doc\core\libs\GlobalParams ($this->table->app());
		$this->topParams->addParam ('date', 'queryDate', ['title' => 'Datum', 'defaultValue' => utils::today('d.m.Y'), 'flags' => ['inViewerToolbar' => 1]]);


		parent::init();
		$this->setPanels (TableView::sptQuery);


		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;
    $this->objectSubType = self::vsMain;
    $this->linesWidth = 60;

		$g = [
			'person' => '_Osoba',
      'wrItems' => 'Časy',
    ];

		$this->setGrid ($g);

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];

		$this->setMainQueries ($mq);

		$this->enabledPersons = $this->loadEnabledPersons();


		if ($this->personNdx)
			$this->addAddParam ('person', $this->personNdx);
	}

	protected function loadEnabledPersons()
	{
		if ($this->app()->hasRole('empsAdm'))
			return NULL;

		$orgs = [];
		$q = [];
		array_push($q, ' SELECT orgsPersons.*');
		array_push($q, ' FROM [e10pro_emps_orgsPersons] AS [orgsPersons]');
		array_push($q, ' WHERE [person] = %i', $this->app()->userNdx());
		array_push($q, ' AND [superior] = %i', 1);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!in_array($r['orgs'], $orgs))
				$orgs[] = $r['orgs'];
		}

		if (!count($orgs))
			return [-1];

		$persons = [];
		$q = [];
		array_push($q, ' SELECT orgsPersons.*');
		array_push($q, ' FROM [e10pro_emps_orgsPersons] AS [orgsPersons]');
		array_push($q, ' WHERE [orgs] IN %in', $orgs);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!in_array($r['person'], $persons))
				$persons[] = $r['person'];
		}

		if (!count($persons))
			return [-1];

		return $persons;
	}

	public function selectRows ()
	{
		$this->date = utils::today();
		$dateStr = $this->topParamsValues['queryDate']['value'] ?? '';

		if (utils::dateIsValid($dateStr, 'd.m.Y'))
			$this->date = \DateTime::createFromFormat('d.m.Y', $dateStr);

		$academicYear = zusutils::skolniRok($this->date);
		$dow = intval($this->date->format('N')) - 1; // 0 = monday

    $q = [];
    array_push ($q, 'SELECT persons.*');
    array_push ($q, ' FROM [e10_persons_persons] AS persons');
    array_push ($q, ' WHERE 1');
    array_push ($q, ' AND persons.company = 0');

		if ($this->enabledPersons)
			array_push ($q, ' AND persons.ndx IN %in', $this->enabledPersons);

		array_push ($q, ' AND (');
			array_push ($q, ' EXISTS (',
					'SELECT DISTINCT vyukyRozvrh.ucitel FROM e10pro_zus_vyukyrozvrh AS vyukyRozvrh',
					' LEFT JOIN e10pro_zus_vyuky AS vyuky ON vyuky.ndx = vyukyRozvrh.vyuka ',
					' WHERE 1',
					' AND (persons.ndx = vyukyRozvrh.ucitel)',
					' AND vyukyRozvrh.[den] = %i', $dow,
					' AND vyuky.[skolniRok] = %i', $academicYear,
			 ')');

			array_push ($q, ' OR EXISTS (',
					'SELECT DISTINCT vyukyRozvrh.ucitel FROM e10pro_zus_vyukyrozvrh AS vyukyRozvrh',
					' LEFT JOIN e10pro_zus_vyuky AS vyuky ON vyuky.ndx = vyukyRozvrh.vyuka ',
					' WHERE 1',
					' AND (persons.ndx = vyuky.ucitel2)',
					' AND vyukyRozvrh.[den] = %i', $dow,
					' AND vyuky.[skolniRok] = %i', $academicYear,
			 ')');

			array_push ($q, ' OR EXISTS (',
					'SELECT DISTINCT hodiny.ucitel FROM e10pro_zus_hodiny AS hodiny',
					' LEFT JOIN e10pro_zus_vyuky AS vyuky ON vyuky.ndx = hodiny.vyuka ',
					' WHERE persons.ndx = hodiny.ucitel ',
					' AND hodiny.[nahradniTermin] = %i', 1,
					' AND hodiny.[nahradaDatum] = %d', $this->date,
					' AND vyuky.[skolniRok] = %i', $academicYear,
			 ')');

			array_push ($q, ' OR EXISTS (',
					'SELECT DISTINCT hodiny.suplujiciUcitel FROM e10pro_zus_hodiny AS hodiny',
					' LEFT JOIN e10pro_zus_vyuky AS vyuky ON vyuky.ndx = hodiny.vyuka ',
					' WHERE persons.ndx = hodiny.suplujiciUcitel ',
					' AND hodiny.[suplovani] = %i', 1,
					' AND hodiny.[datum] = %d', $this->date,
					' AND vyuky.[skolniRok] = %i', $academicYear,
			 ')');

		array_push ($q, ')');


		$fs = $this->fullTextSearch ();
		if ($fs != '')
		{
			array_push ($q, ' AND (');
			array_push( $q, ' (persons.[fullName] LIKE %s)', '%'.$fs.'%');
			array_push ($q, ')');
		}

    array_push( $q, ' ORDER BY [lastName], [firstName] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $this->date->format('Y-m-d').'_'.$item['ndx'];

		$listItem ['icon'] = $this->table->icon ($item);
		$listItem ['person'] = $item['fullName'];

		$ee = new \e10pro\zus\libs\WorkRecsTimetableEngine($this->app());
		$ee->setParams($item['ndx'], $this->date);
		$ee->loadData();

		if ($ee->invalid)
			$listItem['class'] = 'e10-warning1';

    foreach ($ee->workRecs as $r)
    {
      $wriId = $r['beginDate']->format('Y-m-d').'_'.$r['person'];
      //$wrLabel = ['text' => '', 'class' => 'labe label-default'];

			$class = 'label label-default';

			if (!$r['unused'])
			{
				if (isset($r['invalid']))
				{
					if ($r['invalid'] === 1)
						$class = 'label label-danger';
					elseif ($r['invalid'] === 2)
						$class = 'label label-warning';
					elseif ($r['invalid'] === 0)
						$class = 'label label-success';
				}
			}

			$allMinutes = Utils::minutesToTime($r['timeLen'] / 60);
			$wrLabel = [
				'text' => $r['beginTime'].' - '.$r['endTime'],
				'suffix' => $allMinutes,
				'icon' => 'system/iconClock', 'class' => $class,
			];

			if (!isset($listItem['wrItems']))
				$listItem['wrItems'] = [];
			$listItem['wrItems'][] = $wrLabel;
    }

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}
}
