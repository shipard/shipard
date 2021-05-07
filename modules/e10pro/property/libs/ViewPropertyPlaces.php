<?php

namespace e10pro\property\libs;
use \Shipard\Viewer\TableView;




class ViewPropertyPlaces extends TableView
{
	public function init ()
	{
		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['i2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * from [e10_base_places] AS places';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND ([fullName] LIKE %s OR [shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND places.[docStateMain] < 4");

		// -- trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND places.[docStateMain] = 4");

		array_push ($q, ' ORDER BY [id], [fullName], places.[ndx] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}
