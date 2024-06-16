<?php

namespace e10doc\slr;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * Class TableImports
 */
class TableImports extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.imports', 'e10doc_slr_imports', 'Importy podkladů ke mzdám');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['name']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function importEngine ($importNdx)
	{
		$importRecData = $this->loadItem($importNdx);
		if (!$importRecData)
			return NULL;

		$importTypeCfg = $this->app()->cfgItem('e10doc.slr.importTypes.'.$importRecData['importType'], NULL);
		if (!$importTypeCfg || !isset($importTypeCfg['classId']))
			return NULL;

		$ie = $this->app()->createObject($importTypeCfg['classId']);

		return $ie;
	}
}


/**
 * class ViewImports
 */
class ViewImports extends TableView
{
	var $sections = [];
	var $docsTypes;

	public function init ()
	{
		//$this->docsTypes = $this->app->cfgItem ('e10.docs.types', FALSE);

		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];


		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$props = [];
		$props[] = ['text' => $item['calendarYear'].'/'.$item['calendarMonth'], 'icon' => 'system/iconCalendar', 'class' => 'label label-info'];

		$listItem ['t2'] = $props;
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [imports].* ');
		array_push ($q, ' FROM [e10doc_slr_imports] AS [imports]');
		array_push ($q, '');
		array_push ($q, '');
		array_push ($q, '');
		array_push ($q, '');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [imports].[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[imports].', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormImport
 */
class FormImport extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Import', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('importType');
					$this->addColumnInput ('name');
					$this->addColumnInput ('calendarYear');
					$this->addColumnInput ('calendarMonth');

					$this->addList ('inbox', '', self::loAddToFormLayout);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailImport
 */
class ViewDetailImport extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.slr.dc.DCImport');
	}
}
