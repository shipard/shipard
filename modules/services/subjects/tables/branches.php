<?php

namespace services\subjects;



use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableBranches
 * @package services\subjects
 */
class TableBranches extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.branches', 'services_subjects_branches', 'Obory subjektů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];

		// -- branches
		$rows = $this->app()->db->query ('SELECT * FROM [services_subjects_branches] WHERE docState != 9800 ORDER BY [fullName], [ndx]');
		foreach ($rows as $r)
		{
			$branchNdx = $r['ndx'];
			$branchDef = ['ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'],];

			$list ['branches'][$branchNdx] = $branchDef;
		}

		// -- branches parts
		$rows = $this->app()->db->query (
			'SELECT * FROM [services_subjects_branchesParts] WHERE docState != 9800 ',
			' AND [branch] IN %in', array_keys($list ['branches']),
			' ORDER BY [fullName], [ndx]');
		foreach ($rows as $r)
		{
			$partNdx = $r['ndx'];
			$branchNdx = $r['branch'];

			$partDef = ['ndx' => $r ['ndx'], 'branch' => $branchNdx, 'fn' => $r ['fullName'], 'a' => $r['activity'], 'c' => $r['commodity']];
			$list ['parts'][$partNdx] = $partDef;

			$list ['branches'][$branchNdx]['parts'][] = $partNdx;
		}

		// -- load NACE
		$naceList = [];
		$ql[] = 'SELECT docLinks.*, nomenc.itemId as nomencId FROM [e10_base_doclinks] AS docLinks ';
		array_push ($ql, ' LEFT JOIN [e10_base_nomencItems] AS nomenc ON docLinks.dstRecId = nomenc.ndx');
		array_push ($ql, ' WHERE srcTableId = %s', 'services.subjects.branchesParts');
		array_push ($ql, ' AND docLinks.linkId = %s', 'services-subjects-bparts-nace');
		array_push ($ql, ' ORDER BY nomenc.id');
		$rows = $this->db()->query ($ql);
		foreach ($rows as $r)
		{
			$id = $r['dstRecId'];
			if (!isset ($naceList[$id]))
				$naceList[$id] = [];
			elseif (in_array($r['srcRecId'], $naceList[$id]))
				continue;

			$naceList[$id][] = $r['srcRecId'];
		}

		// -- save to file
		$cfg ['services']['subjects']['branches'] = $list;
		$cfg ['services']['subjects']['branches']['nace'] = $naceList;
		file_put_contents(__APP_DIR__ . '/config/_services.subjects.branches.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewBranches
 * @package services\subjects
 */
class ViewBranches extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		/*
		$props = [];

		if ($item['activityName'])
			$props [] = ['text' => $item['activityName'], 'icon' => 'icon-spoon'];
		if ($item['commodityName'])
			$props [] = ['text' => $item['commodityName'], 'icon' => 'icon-shopping-basket'];

		$listItem ['t2'] = $props;
*/
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT branches.*';
		array_push ($q, ' FROM [services_subjects_branches] AS branches');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND (',
					'branches.[fullName] LIKE %s OR branches.[shortName] LIKE %s', '%'.$fts.'%', '%'.$fts.'%',
					')'
			);

		$this->queryMain ($q, 'branches.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailBranch
 * @package services\subjects
 */
class ViewDetailBranch extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('services.subjects.branchesParts', 'services.subjects.ViewBranchesParts', ['branch' => $this->item ['ndx']]);
	}
}


/**
 * Class FormBranch
 * @package services\subjects
 */
class FormBranch extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		//$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-content'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

