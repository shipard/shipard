<?php

namespace e10\ui;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableExtApps
 */
class TableExtApps extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.ui.extApps', 'e10_ui_extApps', 'Externí aplikace');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['fullName']];

		return $hdr;
	}

  public function extAppsList()
  {
		$q = [];
    array_push($q, 'SELECT [apps].*');
    array_push($q, ' FROM [e10_ui_extApps] AS [apps]');
		array_push($q, ' WHERE 1');
    array_push($q, ' ORDER BY [order], [fullName]');

    $rows = $this->db()->query($q);
    $list = [];
    foreach ($rows as $r)
    {
      $list[$r['ndx']] = $r->toArray();
    }
    return $list;
  }
}


/**
 * Class ViewExtApps
 */
class ViewExtApps extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = [];

		$listItem ['t2'][] = ['text' => $item['url'], 'class' => 'label label-default'];

    if ($item['icon'] !== '')
		  $listItem ['icon'] = $item['icon'];
    else
      $listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push($q, 'SELECT [apps].*');
    array_push($q, ' FROM [e10_ui_extApps] AS [apps]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%', ' OR [url] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormExtApp
 */
class FormExtApp extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
          $this->addColumnInput ('fullName');
          $this->addColumnInput ('url');
					$this->addColumnInput ('order');
          $this->addColumnInput ('icon');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs();
		$this->closeForm ();
	}
}
