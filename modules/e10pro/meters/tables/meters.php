<?php

namespace E10Pro\Meters;

require_once __DIR__ . '/../../../e10/base/base.php';

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable;


/**
 * Class TableMeters
 * @package E10Pro\Meters
 */
class TableMeters extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.meters.meters', 'e10pro_meters_meters', 'Měřiče');
	}
}


/**
 * Class ViewMeters
 * @package E10Pro\Meters
 */
class ViewMeters extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT * from [e10pro_meters_meters] WHERE 1";

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s OR [shortName] LIKE %s OR [id] LIKE %s)', '%'.$fts.'%', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '', ['[fullName]', 'id']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailMeter
 * @package E10Pro\Meters
 */
class ViewDetailMeter extends TableViewDetail
{
}


/**
 * Class FormMeter
 * @package E10Pro\Meters
 */
class FormMeter extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-attachments'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('unit');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

