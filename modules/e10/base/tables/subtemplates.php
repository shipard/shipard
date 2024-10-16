<?php

namespace E10\Base;

include_once __DIR__ . '/../base.php';

use \E10\DataModel, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;

class TableSubTemplates extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.subtemplates", "e10_base_subtemplates", "Podšablony");
	}

	public function checkSaveData (&$saveData, &$saveResult)
	{
		parent::checkSaveData($saveData, $saveResult);

		$oldFileName = $saveData['recData']['fileName'];
		$allowed = "/[^a-z0-9\\.\\-\\_\\/]/i";
		$saveData['recData']['fileName'] = preg_replace($allowed,"",$saveData['recData']['fileName']);

		if ($oldFileName === $saveData['recData']['fileName'] && $saveData['documentPhase'] !== 'insert')
			$saveResult['disableSetData'] = 1;
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['name']];

		return $hdr;
	}
} // class TableSubTemplates


/*
 * ViewSubTemplates
 *
 */

class ViewSubTemplates extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		if ($this->queryParam ('template'))
			$this->addAddParam ('template', $this->queryParam ('template'));
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fileName'];
		$listItem ['t2'] = $item['name'];

		$fileTypes = $this->table->columnInfoEnum ('type', 'cfgText');
		$listItem ['t2'] = $fileTypes [$item ['type']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$q [] = "SELECT * from [e10_base_subtemplates] as subtemplates WHERE 1";

		if ($this->queryParam ('template'))
			array_push ($q, " AND [template] = %i", $this->queryParam ('template'));

		// -- fulltext
		$fts = $this->fullTextSearch ();
		if ($fts != '')
			array_push ($q, " AND ([fileName] LIKE %s OR [name] LIKE %s OR [code] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, 'subtemplates.', ['subtemplates.[name]', 'ndx']);

		$this->runQuery ($q);
	}
} // class ViewSubTemplates


/**
 * Základní detail Šablony
 *
 */

class ViewDetailSubTemplate extends TableViewDetail
{
} // ViewDetailSubTemplate


/*
 * FormSubTemplate
 *
 */

class FormSubTemplate extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Kód', 'icon' => 'formScript'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('code', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('fileName');
					$this->addColumnInput ('type');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function checkAfterSave ()
	{
		$tableTemplates = new \E10\Base\TableTemplates($this->app());
		$template = $tableTemplates->loadItem($this->recData['template']);
		$result = $tableTemplates->saveFile ($template, $this->recData['fileName'], $this->recData['code'], ($this->recData['docState'] === 9800));
		if ($result !== '')
		{
			$this->saveResult['notifications'][] = ['style' => 'error', 'title' => 'Nelze vytvořit soubor s kaskádovým stylem',
																							'msg' => "<code>".utils::es($result).'</code>', 'mode' => 'top'];
			$this->saveResult['disableClose'] = 1;
		}

		return parent::checkAfterSave ();
	}
} // class FormSubTemplate

