<?php

namespace e10pro\zus\libs;

use \Shipard\Viewer\TableViewGrid, \Shipard\Utils\World, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewWorkRecs
 */
class ViewWorkRecs extends TableViewGrid
{
	var $now;
	var $useWorkOrders = FALSE;
	var $personNdx = 0;

  var $workRecsItems = [];

	public function init ()
	{
		$this->now = new \DateTime();
		if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
			$this->useWorkOrders = TRUE;

		parent::init();
		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableToolbar = FALSE;
		$this->enableDetailSearch = TRUE;
    $this->objectSubType = self::vsMain;
    $this->linesWidth = 65;

		$g = [
			'date' => 'Datum',
			'person' => '_Osoba',
      'tlh' => ' Hodiny',
      'wrItems' => 'Časy',
    ];

		$this->setGrid ($g);

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];

		$this->setMainQueries ($mq);

		$this->createBottomTabs();

		if ($this->personNdx)
			$this->addAddParam ('person', $this->personNdx);
	}

	public function createBottomTabs ()
	{
		$dbCounters = $this->table->app()->cfgItem ('e10mnf.workRecs.wrNumbers', FALSE);
		if ($dbCounters !== FALSE)
		{
			$activeDbCounter = key($dbCounters);
			if (count ($dbCounters) > 1)
			{
				forEach ($dbCounters as $cid => $c)
				{
					$addParams = ['dbCounter' => intval($cid)];
					$nbt = [
						'id' => $cid, 'title' => ($c['tabName'] !== '') ? $c['tabName'] : $c['shortName'],
						'active' => ($activeDbCounter === $cid),
						'addParams' => $addParams
					];
					$bt [] = $nbt;
				}
				$this->setBottomTabs ($bt);
			}
			else
				$this->addAddParam ('dbCounter', $activeDbCounter);
		}
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();
		$bottomTabId = intval($this->bottomTabId());
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT workrecs.beginDate, workrecs.person, persons.fullName AS personName, SUM(workrecs.timeLen) AS tlh';
		array_push ($q, ' FROM [e10mnf_core_workRecs] AS workrecs');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON workrecs.person = persons.ndx');
		array_push ($q, ' WHERE 1');

//		if ($this->personNdx)
//			array_push ($q, ' AND workrecs.person = %i', $this->personNdx);

		// -- bottom tabs
		if ($bottomTabId != 0)
			array_push ($q, ' AND workrecs.dbCounter = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [workrecs].[subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [persons].fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND workrecs.[docStateMain] < 4");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND workrecs.[docStateMain] = 4");

    array_push ($q, ' GROUP BY workrecs.beginDate, workrecs.person ');

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [dateBegin] DESC ');
		else
			array_push ($q, ' ORDER BY workrecs.[beginDate] DESC, [persons].[lastName], [persons].[firstName], workrecs.person');

    array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['beginDate']->format('Y-m-d').'_'.$item['person'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $listItem ['person'] = $item['personName'];

    $listItem ['date'] = $item['beginDate'];
    $listItem ['tlh'] = Utils::minutesToTime($item['tlh'] / 60);//round($item['tlh'] / 60 / 60, 2);

		$ee = new \e10pro\zus\libs\WorkRecsTimetableEngine($this->app());
		$ee->setParams($item['person'], $item['beginDate']);
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

      $this->workRecsItems[$wriId][] = $wrLabel;
    }



    return $listItem;
	}

  function decorateRow (&$item)
	{
		if (isset ($this->workRecsItems [$item ['pk']]))
		{
      $item['wrItems'] = $this->workRecsItems [$item ['pk']];
		}
	}
}
