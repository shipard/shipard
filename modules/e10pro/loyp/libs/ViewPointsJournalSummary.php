<?php

namespace e10pro\loyp\libs;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;

/**
 * class ViewPointsJournalSummary
 */
class ViewPointsJournalSummary extends TableView
{
	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		//$this->setMainQueries ();

    $loyps = $this->app()->cfgItem('e10pro.loyp.loyps', NULL);

    foreach ($loyps as $loypNdx => $loyp)
    {
			$mq [] = ['id' => 'P'.$loypNdx, 'title' => $loyp['sn'], 'side' => 'left'];
		}
		$mq [] = ['id' => 'ALL', 'title' => 'Vše'];
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['person'].'_'.$item['loyp'];

    $listItem ['t1'] = $item['personFullName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];

    $listItem ['t2'] = Utils::nf($item['sumCntPoints'], 2);

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q = [];
    array_push ($q, 'SELECT [journal].person, [journal].loyp, SUM([journal].cntPoints) AS sumCntPoints,');
    array_push ($q, ' [persons].fullName AS personFullName, [persons].id AS personId');
    array_push ($q, ' FROM [e10pro_loyp_pointsJournal] AS [journal]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [journal].person = [persons].ndx');
		array_push ($q, ' WHERE 1');

		if ($mainQuery !== 'ALL')
		{
			$loypNdx = intval(substr($mainQuery, 1));
			if ($loypNdx)
				array_push ($q, ' AND [journal].loyp = %i', $loypNdx);
		}

		if ($fts != '')
			array_push ($q, " AND ([persons].[fullName] LIKE %s)", '%'.$fts.'%');

    array_push ($q, ' GROUP BY 1, 2');
    array_push ($q, ' ORDER BY sumCntPoints DESC, [journal].person');

    array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}
}
