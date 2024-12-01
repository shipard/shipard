<?php
namespace e10doc\slr;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableEmpsRecs
 */
class TableEmpsRecs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.empsRecs', 'e10doc_slr_empsRecs', 'Mzdové podklady zaměstnanců');
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
 * class ViewEmpsRecs
 */
class ViewEmpsRecs extends TableView
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
		$listItem ['t1'] = $item['personName'];

		$listItem ['i1'] = ['text' => $item['empPersonalId'], 'class' => 'id'];

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
    array_push ($q, 'SELECT [empsRecs].*,');
		array_push ($q, ' emps.fullName AS personName, emps.personalId AS empPersonalId,');
		array_push ($q, ' imports.calendarYear, imports.calendarMonth');
		array_push ($q, ' FROM [e10doc_slr_empsRecs] AS [empsRecs]');
		array_push ($q, ' LEFT JOIN [e10doc_slr_emps] AS emps ON [empsRecs].[emp] = [emps].ndx');
		array_push ($q, ' LEFT JOIN [e10doc_slr_imports] AS [imports] ON [empsRecs].[import] = [imports].ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [emps].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[empsRecs].', ['imports.calendarYear DESC', 'imports.calendarMonth DESC', 'fullName', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormEmpRec
 */
class FormEmpRec extends TableForm
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
					$this->addColumnInput ('emp');
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

	function checkLoadedList ($list)
	{
		if (!intval($this->recData['import'] ?? 0))
			return;
		if (!intval($this->recData['emp'] ?? 0))
			return;

		if ($list->listId === 'rows' && count($list->data) == 0)
		{
			$import = $this->app()->loadItem ($this->recData['import'], 'e10doc.slr.imports');
			if (!$import || $import['importType'] !== 'cz-manual')
				return;

			$q = [];
			array_push($q, 'SELECT itms.*');
			array_push($q, ' FROM [e10doc_slr_slrItems] AS [itms]');
			array_push($q, ' WHERE 1');
			array_push($q, ' AND docState IN %in', [4000, 8000]);
			array_push($q, ' ORDER BY itemType, ndx');
			$itmsRows = $this->app()->db()->query($q);
			$rowOrder = 1000;
			foreach ($itmsRows as $itmRow)
			{
				$newRow = [
					'ndx' => 0,
					'rowOrder' => $rowOrder,
					'slrItem' => $itmRow['ndx'],
					'amount' => 0.0
				];
				$list->data [] = $newRow;
				$rowOrder += 1000;
			}
			return;
		}

		parent::checkLoadedList ($list);
	}
}


/**
 * class ViewDetailEmpRec
 */
class ViewDetailEmpRec extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.slr.dc.DCEmpRec');
	}
}
