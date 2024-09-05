<?php

namespace e10pro\vendms;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \e10\base\libs\UtilsBase;

/**
 * Class TableVendMsBoxes
 */
class TableVendmsBoxes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.vendms.vendmsBoxes', 'e10pro_vendms_vendmsBoxes', 'Boxy prodejních automatů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['label']];

		return $hdr;
	}
}


/**
 * Class ViewVendMsBoxes
 */
class ViewVendMsBoxes extends TableView
{
	var $linkedPersons;

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
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		/*
		if (isset ($this->linkedPersons [$item ['pk']]))
		{
			$item ['t2'] = $this->linkedPersons [$item ['pk']];
		}
			*/
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_vendms_vendms]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}


	public function selectRows2 ()
	{
		/*
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks);
		*/
	}
}


/**
 * class FormVendMsBox
 */
class FormVendMsBox extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
          $this->addColumnInput ('item');
          $this->addColumnInput ('label');
					$this->addColumnInput ('cellId');
          $this->addColumnInput ('vm');
				$this->closeTab ();

				$this->openTab ();
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
