<?php


namespace e10\reports;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Table\DbTable;


/**
 * class TableReports
 */
class TableReports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.reports.reports', 'e10_reports_reports', 'Výstupní sestavy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['fullName']];


		return $hdr;
	}
}


/**
 * Class ViewReports
 */
class ViewReports extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsMain;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t2'] = $item['shortName'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10_reports_reports]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

    array_push ($q, ' ORDER BY [fullName], [ndx]');
    array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

  public function createToolbar ()
	{
		return [];
	}
}


/**
 * class ViewDetailReport
 */
class ViewDetailReport extends TableViewDetail
{
	public function createDetailContent ()
	{
	}

	public function createToolbar ()
	{
		return [];
	}
}
