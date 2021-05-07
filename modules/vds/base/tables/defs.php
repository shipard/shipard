<?php

namespace vds\base;
use \e10\utils, \e10\json, \e10\TableView, \e10\TableForm, \e10\DbTable, \e10,E10\DataModel;


/**
 * Class TableDefs
 * @package vds\base
 */
class TableDefs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('vds.base.defs', 'vds_base_defs', 'Rozšiřující datové struktury');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewIssuesKinds
 * @package wkf\base
 */
class ViewDefs extends TableView
{
	public function init ()
	{
		parent::init();

//		$this->objectSubType = TableView::vsDetail;
//		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT defs.* ';
		array_push ($q, ' FROM [vds_base_defs] AS [defs]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' defs.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[defs].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormDef
 * @package vds\base
 */
class FormDef extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Struktura', 'icon' => 'icon-table'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('systemType');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('structure', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}

	public function checkBeforeSave (&$saveData)
	{
		if ($this->recData['structure'] === '')
		{
			$saveData['recData']['valid'] = 0;
			parent::checkBeforeSave($saveData);
			return;
		}

		$test = json::decode($this->recData['structure']);
		if ($test === NULL)
		{
			$saveData['recData']['valid'] = 0;
			$this->saveResult['notifications'][] = ['style' => 'error', 'title' => 'Nastavení struktury nelze zpracovat - patrně obsahuje syntaktickou chybu',
				'msg' => "<code>".json_last_error_msg().'</code>', 'mode' => 'top'];
			$this->saveResult['disableClose'] = 1;
		}
		else
			$saveData['recData']['valid'] = 1;

		parent::checkBeforeSave($saveData);
	}
}
