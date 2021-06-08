<?php

namespace e10pro\kb;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableAnnotsKinds
 * @package e10pro\kb
 */
class TableAnnotsKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.kb.annotsKinds', 'e10pro_kb_annotsKinds', 'Druhy anotací');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];
		
		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewAnnotsKinds
 * @package e10pro\kb
 */
class ViewAnnotsKinds extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];
		$props[] = ['text' => $item['shortName'], 'class' => ''];

		if (count($props))
			$listItem ['t2'] = $props;

		$props = [];
		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT ak.*';
		array_push ($q, ' FROM [e10pro_kb_annotsKinds] AS ak');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' ak.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR ak.[shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'ak.', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormAnnotKind
 * @package e10pro\kb
 */
class FormAnnotKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
	//		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
				$this->closeTab ();
				//$this->openTab ();
				//$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

