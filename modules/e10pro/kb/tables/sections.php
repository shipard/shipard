<?php

namespace e10pro\kb;

use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \e10\base\libs\UtilsBase;
use \Shipard\Application\DataModel;


/**
 * Class TableSections
 * @package e10pro\kb
 */
class TableSections extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.kb.sections', 'e10pro_kb_sections', 'Wiki Sekce');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}
}


/**
 * Class ViewSections
 * @package e10pro\kb
 */
class ViewSections extends TableView
{
	var $linkedPersons;
	var $tableWikies;
	var $enabledWikies = [];

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->tableWikies = $this->app->table ('e10pro.kb.wikies');

		$usersWikies = $this->tableWikies->usersWikies ();
		$active = 1;
		foreach ($usersWikies as $w)
		{
			$bt [] = ['id' => $w['ndx'], 'title' => $w['sn'], 'active' => $active, 'addParams' => ['wiki' => $w['ndx']]];
			$this->enabledWikies[] = $w['ndx'];
			$active = 0;
		}
		$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 0];
		$this->setBottomTabs ($bt);

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['title'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['i1'] = ['text' => '#s'.$item['ndx'], 'class' => 'id'];

		$props = [];
		if ($item['wikiName'])
			$props[] = ['text' => $item['wikiName'], 'icon' => 'icon-book', 'class' => 'label label-info'];
		if ($item['publicRead'])
			$props[] = ['text' => 'Veřejné', 'icon' => 'icon-users', 'class' => 'label label-success'];
		if (count($props))
			$listItem ['t2'] = $props;

		$props = [];
		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]))
		{
			$item ['t3'] = $this->linkedPersons [$item ['pk']];
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$wiki = intval($this->bottomTabId ());

		$q [] = 'SELECT sections.*, wikies.shortName AS wikiName FROM [e10pro_kb_sections] AS sections';
		array_push ($q, ' LEFT JOIN [e10pro_kb_wikies] AS [wikies] ON [sections].[wiki] = [wikies].ndx');
		array_push ($q, ' WHERE 1');

		if ($wiki)
			array_push ($q, ' AND sections.[wiki] = %i', $wiki);
		else
			array_push ($q, ' AND sections.[wiki] IN %in', $this->enabledWikies);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' sections.[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'sections.', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}


	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks);
	}
}


/**
 * Class FormSection
 * @package e10pro\kb
 */
class FormSection extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Perex', 'icon' => 'formPerex'];
			$tabs ['tabs'][] = ['text' => 'Patička', 'icon' => 'formFooter'];
			$tabs ['tabs'][] = ['text' => 'Kniha', 'icon' => 'formBook'];
			$tabs ['tabs'][] = ['text' => 'Titulní strana', 'icon' => 'formBook'];
			$tabs ['tabs'][] = ['text' => 'CSS', 'icon' => 'formBook'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('title');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ('publicRead');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('topMenuStyle');
					$this->addColumnInput ('homeTileStyle');
					$this->addColumnInput ('wiki');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addColumnInput ('perex', TableForm::coFullSizeY);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addColumnInput ('pageFooter', TableForm::coFullSizeY);
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('bookEnable');
					$this->addColumnInput ('bookTitle');
					$this->addColumnInput ('bookSubTitle');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('bookCoverPage', '', TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('bookCSS', '', TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

