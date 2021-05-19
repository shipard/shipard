<?php

namespace wkf\base;

use \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableExtNotifications
 * @package wkf\base
 */
class TableExtNotifications extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.extNotifications', 'wkf_base_extNotifications', 'Externí notifikace', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewExtNotifications
 * @package wkf\base
 */
class ViewExtNotifications extends TableView
{
	var $ntfSections;

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
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['fullName'];

		$props = [];
		if ($item['l1chFullName'])
			$props[] = ['text' => $item['l1chFullName'], 'icon' => 'icon-bullhorn', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['t3'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->ntfSections [$item ['pk']]))
			$item ['t2'] = $this->ntfSections [$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT en.*, l1ch.fullName AS l1chFullName';
		array_push ($q, ' FROM [wkf_base_extNotifications] AS [en]');
		array_push ($q, ' LEFT JOIN [integrations_ntf_channels] AS [l1ch] ON en.l1Channel = l1ch.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' en.[fullName] LIKE %s', '%'.$fts.'%',
				' OR en.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[en].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- sections
		$q = [];
		array_push($q, 'SELECT links.ndx, links.linkId as linkId,');
		array_push($q, ' links.srcRecId as sectionNdx, sections.fullName AS sectionFullName');
		array_push($q, ' FROM e10_base_doclinks AS [links]');
		array_push($q, ' LEFT JOIN wkf_base_sections AS [sections] ON links.dstRecId = sections.ndx');
		array_push($q, ' WHERE dstTableId = %s', 'wkf.base.sections');
		array_push($q, ' AND srcTableId = %s', 'wkf.base.extNotifications');
		array_push($q, ' AND links.srcRecId IN %in', $this->pks);

		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$this->ntfSections[$r['sectionNdx']][] = ['text' => $r['sectionFullName'], 'icon' => 'icon-columns', 'class' => 'label label-default'];
		}
	}
}


/**
 * Class FormExtNotification
 * @package wkf\base
 */
class FormExtNotification extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		//$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput('fullName');
					$this->addColumnInput('shortName');
					$this->addColumnInput('minPriority');
					$this->addColumnInput('l1Channel');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
