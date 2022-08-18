<?php

namespace wkf\bboard;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableBBoards
 */
class TableBBoards extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.bboard.bboards', 'wkf_bboard_bboards', 'Nástěnky');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_bboard_bboards] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'],
				'icon' => ($r['icon'] === '') ? 'icon-file': $r['icon'],
			];

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg['wkf']['bboard']['bboards'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_wkf.bboard.bboards.json', Utils::json_lint (json_encode ($cfg)));
	}

	public function usersBBoards()
	{
		$allBBoards = $this->app()->cfgItem('wkf.bboard.bboards', NULL);
		return $allBBoards;
	}
}


/**
 * Class ViewBBoards
 */
class ViewBBoards extends TableView
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

		if ($item['fullName'] === $item['shortName'])
		{
			$listItem ['t1'] = $item['fullName'];
		}
		else
		{
			$listItem ['t1'] = ['text' => $item['fullName'], 'suffix' => $item['shortName']];
		}

		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['order'])
			$props[] = ['text' => Utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT bboards.* ';
		array_push ($q, ' FROM [wkf_bboard_bboards] AS [bboards]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' bboards.[fullName] LIKE %s', '%'.$fts.'%',
				' OR bboards.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[bboards].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormBoard
 */
class FormBBoard extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput('fullName');
					$this->addColumnInput('shortName');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput('icon');
					$this->addColumnInput('order');
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
