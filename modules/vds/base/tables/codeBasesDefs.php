<?php

namespace vds\base;
use \e10\utils, \e10\json, \e10\TableView, \e10\TableForm, \e10\DbTable, \e10,E10\DataModel;


/**
 * class TableCodeBasesDefs
 */
class TableCodeBasesDefs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('vds.base.codeBasesDefs', 'vds_base_codeBasesDefs', 'Definice vlastních číselníků');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [vds_base_codeBasesDefs] WHERE [docState] != 9800 ORDER BY [ndx]');

		foreach ($rows as $r)
    {
			$list [$r['ndx']] =
      [
        'ndx' => $r ['ndx'],
        'fn' => $r ['fullName'],
				'sn' => $r ['shortName'],
        'vds' => $r ['vds'],
				'useFullName' => $r ['useFullName'],
				'useShortName' => $r ['useShortName'],
				'useDates' => $r ['useDates'],
				'orderByColumn' => $r ['orderByColumn'],
				'orderByDesc' => $r ['orderByDesc'],
			];
    }

		// save to file
		$cfg ['vds']['codeBasesDefs'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_vds.codeBasesDefs.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * class ViewCodeBasesDefs
 */
class ViewCodeBasesDefs extends TableView
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

		$q [] = 'SELECT cbDefs.* ';
		array_push ($q, ' FROM [vds_base_codeBasesDefs] AS [cbDefs]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' cbDefs.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[cbDefs].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormCodeBaseDef
 */
class FormCodeBaseDef extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		//$tabs ['tabs'][] = ['text' => 'Struktura', 'icon' => 'formStructure'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('vds');
					$this->addColumnInput ('useFullName');
					$this->addColumnInput ('useShortName');
					$this->addColumnInput ('useDates');
					$this->addColumnInput ('orderByColumn');
					$this->addColumnInput ('orderByDesc');
				$this->closeTab();
        /*
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('structure', NULL, TableForm::coFullSizeY, DataModel::ctCode);
				$this->closeTab ();
        */
			$this->closeTabs();
		$this->closeForm ();
	}
}
