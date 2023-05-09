<?php
namespace e10mnf\mf;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Table\DbTable, \Shipard\Form\TableForm;


/**
 * class TableProducts
 */
class TableProducts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.mf.products', 'e10mnf_mf_products', 'Výrobky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewProducts
 */
class ViewProducts extends TableView
{
	public function init ()
	{
		parent::init();
		$this->linesWidth = 33;

		$this->objectSubType = TableView::vsMain;
		$this->enableDetailSearch = TRUE;
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = [['text' => $item['shortName'], 'class' => 'label label-default']];
		$listItem ['t2'][] = ['text' => $item['id'], 'class' => 'label label-info'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10mnf_mf_products]';
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
}


/**
 * class ViewDetailProduct
 */
class ViewDetailProduct extends TableViewDetail
{
	public function createDetailContent ()
	{
    $this->addDocumentCard('e10mnf.mf.libs.dc.DCProduct');
	}
}


/**
 * class FormProduct
 */
class FormProduct extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
      $tabs ['tabs'][] = ['text' => 'Materiál', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
          $this->addColumnInput ('id');
				$this->closeTab ();
        $this->openTab();
          $this->addList('materials');
        $this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

