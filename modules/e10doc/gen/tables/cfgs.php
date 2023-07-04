<?php

namespace e10doc\gen;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Utils;

/**
 * class TableCfgs
 */
class TableCfgs extends DbTable
{
	CONST srcTypeDoc = 0, srcTypeWorkOrder = 1;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.gen.cfgs', 'e10doc_gen_cfgs', 'Nastavení generování dokladů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function saveConfig ()
	{
		$cfgs = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_gen_cfgs] WHERE [docState] != 9800 ORDER BY [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'],

				'srcType' => $r ['srcType'],
				'srcDocType' => $r ['srcDocType'],
				'dstDocType' => $r ['dstDocType'],
			];

			$cfgs [$r['ndx']] = $item;
		}

		// --- save to file
		$cfg ['e10doc']['gen']['cfgs'] = $cfgs;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.gen.cfgs.json', Utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * class ViewCfgs
 */
class ViewCfgs extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = ['text' => $item['formatId'], 'class' => 'id'];

		$props = [];

		$listItem ['t2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

		array_push ($q, 'SELECT [cfgs].* ');
		array_push ($q, ' FROM [e10doc_gen_cfgs] AS [cfgs]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [cfgs].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[cfgs].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormCfg
 */
class FormCfg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');

					$this->addColumnInput ('srcType');
					if ($this->recData['srcType'] == TableCfgs::srcTypeDoc)
						$this->addColumnInput ('srcDocType');

					$this->addSeparator(self::coH4);
					$this->addColumnInput ('dstDocType');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
