<?php

namespace e10pro\purchase;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableOperationalLog
 */
class TableOperationalLog extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.purchase.operationalLog', 'e10pro_purchase_operationalLog', 'Provozní deník');
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		$recData ['author'] = $this->app()->userNdx();
		$recData ['date'] = Utils::today();
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$props = [];

    $props[] = ['text' => $recData['title'], 'icon' => 'system/iconCalendar'];


		$hdr ['info'][] = ['class' => 'info', 'value' => $props];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * class ViewOperationalLog
 */
class ViewOperationalLog extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['title'];
		$listItem ['t2'] = Utils::datef ($item['date']);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push($q, 'SELECT [log].*');
		array_push($q, ' FROM [e10pro_purchase_operationalLog] AS [log]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [log].[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [log].[text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[log].', ['[date] DESC', '[ndx] DESC']);
		$this->runQuery ($q);
	}
}


/**
 * class ViewDetailOperationalLog
 */
class ViewDetailOperationalLog extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.purchase.libs.dc.DCOperationalLog');
	}
}


/**
 * class FormOperationalLog
 */
class FormOperationalLog extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$this->layoutOpen (TableForm::ltHorizontal);
			$this->layoutOpen (TableForm::ltForm);
				$this->addColumnInput ('title');
				$this->addColumnInput ('date');
			$this->layoutClose ();
		$this->layoutClose ();

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openTabs ($tabs);
			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ('author');
				$this->addColumnInput ('recordType');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();
		$this->closeTabs ();

		$this->closeForm ();
	}
}
