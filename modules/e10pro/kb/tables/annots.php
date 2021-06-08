<?php

namespace e10pro\kb;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableAnnots
 * @package e10pro\kb
 */
class TableAnnots extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.kb.annots', 'e10pro_kb_annots', 'Anotace');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		/*
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];
		*/
		return parent::tableIcon ($recData, $options);
	}
}


/**
 * Class ViewAnnots
 * @package e10pro\kb
 */
class ViewAnnots extends TableView
{
	var $docTableNdx = 0;
	var $docRecNdx = 0;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('docTableNdx'))
		{
			$this->docTableNdx = intval($this->queryParam('docTableNdx'));
			if ($this->docTableNdx)
				$this->addAddParam('docTableNdx', $this->docTableNdx);
		}
		if ($this->queryParam ('docRecNdx'))
		{
			$this->docRecNdx = intval($this->queryParam('docRecNdx'));
			if ($this->docRecNdx)
				$this->addAddParam('docRecNdx', $this->docRecNdx);
		}
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['title'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t2'] = 'test';
/*
		$props = [];
		$props[] = ['text' => $item['shortName'], 'class' => ''];

		if (count($props))
			$listItem ['t2'] = $props;
*/
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

		$q [] = 'SELECT annots.*';
		array_push ($q, ' FROM [e10pro_kb_annots] AS annots');
		//array_push ($q, ' FROM [e10pro_kb_annotsKinds] AS ak');
		array_push ($q, ' WHERE 1');

		if ($this->docTableNdx)
			array_push ($q, ' AND annots.docTableNdx = %i', $this->docTableNdx);
		if ($this->docRecNdx)
			array_push ($q, ' AND annots.docRecNdx = %i', $this->docRecNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' annots.[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR annots.[perex] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'annots.', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormAnnot
 * @package e10pro\kb
 */
class FormAnnot extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Obsah', 'icon' => 'x-content'];
			//		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('annotKind');
					$this->addColumnInput ('title');

					$this->addColumnInput ('url');

					//$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

					$this->addColumnInput ('order');
					$this->addColumnInput ('linkLanguage');
					$this->addColumnInput ('linkCountry');
					$this->addColumnInput ('perex');

					//$this->addColumnInput ('docTableNdx');
					//$this->addColumnInput ('docRecNdx');
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

