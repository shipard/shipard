<?php

namespace e10mnf\base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableWorkOrdersGroups
 * @package e10mnf\base
 */
class TableWorkOrdersGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.workOrdersGroups', 'e10mnf_base_workOrdersGroups', 'Skupiny zakázek');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10mnf_base_workOrdersGroups] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'icon' => $r['icon']];

			// -- docKinds
			$docLinksRows = $this->app()->db->query (
				'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
				' WHERE doclinks.linkId = %s', 'e10mnf-workRecsGroups-docKinds',
				' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($docLinksRows as $dl)
				$item['docKinds'][] = $dl['dstRecId'];

			// -- docNumbers
			$docNumbersRows = $this->app()->db->query (
				'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
				' WHERE doclinks.linkId = %s', 'e10mnf-workRecsGroups-docNumbers',
				' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($docNumbersRows as $dl)
				$item['docNumbers'][] = $dl['dstRecId'];

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg ['e10mnf']['base']['workOrdersGroups'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.base.workOrdersGroups.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewWorkOrdersGroups
 * @package e10mnf\base
 */
class ViewWorkOrdersGroups extends TableView
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
		$listItem ['i1'] = $item['id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'icon-sort', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10mnf_base_workOrdersGroups] as wog';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' wog.[fullName] LIKE %s', '%'.$fts.'%',
				' OR wog.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'wog.', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormWorkOrderGroup
 * @package e10mnf\base
 */
class FormWorkOrderGroup extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab();
					$this->openTab ();
					$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
