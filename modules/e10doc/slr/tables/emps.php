<?php

namespace e10doc\slr;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableEmps
 */
class TableEmps extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.emps', 'e10doc_slr_emps', 'Zaměstnanci');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewEmps
 */
class ViewEmps extends TableView
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
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => $item['personalId'], 'class' => 'id'];

		$props = [];


		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [emps].* ');
		array_push ($q, ' FROM [e10doc_slr_emps] AS [emps]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [emps].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [emps].[personalId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[emps].', ['[fullName]', '[personalId]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormEmp
 */
class FormEmp extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Zaměstn.', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Instituce', 'icon' => 'tables/e10doc.slr.orgs'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
          $this->addColumnInput ('person');
					$this->addColumnInput ('fullName');
          $this->addColumnInput ('personalId');
					$this->addSeparator(self::coH4);
          $this->addColumnInput ('slrBankAccount');
          $this->addColumnInput ('slrSymbol1');
          $this->addColumnInput ('slrSymbol2');
          $this->addColumnInput ('slrSymbol3');
					$this->addSeparator(self::coH4);
          $this->addColumnInput ('slrCentre');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addList ('orgs');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.slr.orgs' && $srcColumnId === 'slrBankAccount')
		{
			$cp = [
				'personNdx' => strval ($allRecData ['recData']['person'])
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * Class ViewDetailEmp
 */
class ViewDetailEmp extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('e10doc.slr.dc.DCImport');
	}
}
