<?php

namespace services\subjects;


use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableBranchesParts
 * @package services\subjects
 */
class TableBranchesParts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.subjects.branchesParts', 'services_subjects_branchesParts', 'Členění Oborů subjektů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['keywords']];

		return $hdr;
	}
}


/**
 * Class ViewBranchesParts
 * @package services\subjects
 */
class ViewBranchesParts extends TableView
{
	var $nace = [];

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		if ($this->queryParam ('branch'))
			$this->addAddParam ('branch', $this->queryParam ('branch'));

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['activityName'])
			$props [] = ['text' => $item['activityName'], 'icon' => 'icon-spoon', 'class' => 'label label-default'];
		if ($item['commodityName'])
			$props [] = ['text' => $item['commodityName'], 'icon' => 'icon-shopping-basket', 'class' => 'label label-default'];

		if ($item['keywords'] !== '')
			$props [] = ['text' => $item['keywords'], 'icon' => 'icon-comments-o', 'class' => 'label label-default pull-right'];


		$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT branchesParts.*, activities.fullName as activityName, commodities.fullName as commodityName ';
		array_push ($q, ' FROM [services_subjects_branchesParts] AS branchesParts');
		array_push ($q, ' LEFT JOIN [services_subjects_activities] AS activities ON branchesParts.activity = activities.ndx');
		array_push ($q, ' LEFT JOIN [services_subjects_commodities] AS commodities ON branchesParts.commodity = commodities.ndx');
		array_push ($q, ' WHERE 1');

		$branchNdx = intval($this->queryParam ('branch'));
		if ($branchNdx)
			array_push ($q, ' AND branchesParts.branch = %i', $branchNdx);

		/*
		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND (',
				'branches.[fullName] LIKE %s OR branches.[shortName] LIKE %s', '%'.$fts.'%', '%'.$fts.'%',
				' OR activities.[fullName] LIKE %s OR activities.[shortName] LIKE %s', '%'.$fts.'%', '%'.$fts.'%',
				' OR commodities.[fullName] LIKE %s OR commodities.[shortName] LIKE %s', '%'.$fts.'%', '%'.$fts.'%',
				')'
			);
*/
		$this->queryMain ($q, 'branchesParts.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	function decorateRow (&$item)
	{
		if (isset ($this->nace [$item ['pk']]))
			$item ['t3'] = $this->nace [$item ['pk']];
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->loadNACE();
	}

	function loadNACE ()
	{
		$ql[] = 'SELECT docLinks.srcRecId as branchNdx, nomenc.itemId as nomencId, nomenc.fullName AS nomencFullName';
		array_push ($ql, ' FROM [e10_base_doclinks] AS docLinks');
		array_push ($ql, ' LEFT JOIN [e10_base_nomencItems] AS nomenc ON docLinks.dstRecId = nomenc.ndx');
		array_push ($ql, ' WHERE srcTableId = %s', 'services.subjects.branchesParts');
		array_push ($ql, ' AND docLinks.linkId = %s', 'services-subjects-bparts-nace');
		array_push ($ql, ' AND [srcRecId] IN %in', $this->pks);
		array_push ($ql, ' ORDER BY nomenc.id');
		$rows = $this->db()->query ($ql);
		foreach ($rows as $r)
		{
			if (!isset ($this->nace[$r['branchNdx']]))
				$this->nace[$r['branchNdx']] = [];

			$this->nace[$r['branchNdx']][] = [
				'text' => $r['nomencFullName'], 'prefix' => $r['nomencId'],
				'class' => 'label label-default', 'icon' => 'icon-compass'
			];
		}
	}
}


/**
 * Class ViewDetailBranchPart
 * @package services\subjects
 */
class ViewDetailBranchPart extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class FormBranchPart
 * @package services\subjects
 */
class FormBranchPart extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-content'];
//		$tabs ['tabs'][] = ['text' => 'Zařazení', 'icon' => 'icon-list-ol'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('activity');
					$this->addColumnInput ('commodity');
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('keywords');
					$this->addColumnInput ('branch');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

