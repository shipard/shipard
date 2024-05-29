<?php
namespace e10doc\slr;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TablePersonsRecs
 */
class TablePersonsRecs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.personsRecs', 'e10doc_slr_personsRecs', 'Mzdové podklady Osob');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

    $hdr ['info'][] = ['class' => 'info', 'value' => /*$recData ['name']*/ 'TEST'];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewPersonsRecs
 */
class ViewPersonsRecs extends TableView
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
		//$listItem ['t1'] = $item['name'];


		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$props = [];

    /*
		$dt = $this->docsTypes[$item['docType']];
		$props[] = ['text' => $dt['pluralName'], 'icon' => $dt['icon'], 'class' => 'label label-info'];

		$listItem ['t2'] = $props;
    */
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [personsRecs].* ');
		array_push ($q, ' FROM [e10doc_slr_personsRecs] AS [personsRecs]');
		array_push ($q, '');
		array_push ($q, '');
		array_push ($q, '');
		array_push ($q, '');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			//array_push ($q,' [imports].[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[personsRecs].', ['[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormPersonRec
 */
class FormPersonRec extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Záznam', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('person');
					$this->addColumnInput ('import');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addList ('rows');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailPersonRec
 */
class ViewDetailPersonRec extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('e10doc.slr.dc.DCImport');
	}
}
