<?php

namespace e10doc\slr;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableCentres
 */
class TableCentres extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.centres', 'e10doc_slr_centres', 'Střediska');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

//		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
//		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];

		return $hdr;
	}
}


/**
 * class ViewCentres
 */
class ViewCentres extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['cnCentreName'];
    $listItem ['i1'] = ['text' => $item['cnCentreId'], 'class' => 'id'];
		$listItem ['t2'] = ['text' => $item['importId'], 'class' => 'label label-info'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [slrCentres].*,');
    array_push ($q, ' cbCentres.fullName AS cnCentreName, cbCentres.id AS cnCentreId');
		array_push ($q, ' FROM [e10doc_slr_centres] AS [slrCentres]');
    array_push ($q, ' LEFT JOIN [e10doc_base_centres] AS [cbCentres] ON slrCentres.centre = cbCentres.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [cbCentres].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [cbCentres].[id] LIKE %s', '%'.$fts.'%');
      array_push ($q, ' OR [slrCentres].[importId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[slrCentres].', ['[importId]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormCentre
 */
class FormCentre extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Druh', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('centre');
					$this->addColumnInput ('importId');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailCentre
 */
class ViewDetailCentre extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
