<?php

namespace e10pro\kb;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;
use \e10\base\libs\UtilsBase;

/**
 * Class TableWikies
 * @package e10pro\kb
 */
class TableWikies extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.kb.wikies', 'e10pro_kb_wikies', 'Wiki');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['title']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [e10pro_kb_wikies] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$wikies = [];
		foreach ($rows as $r)
		{
			$wiki = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'],
				'title' => $r['title'], 'icon' => $r['icon'], 'dp' => intval($r['dashboardPlace'])
			];

			//if ($r['excludeFromDashboard'])
			//	$s['excludeFromDashboard'] = 1;


			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($wiki, 'admins', 'e10.persons.persons', 'e10pro-kb-wikies-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($wiki, 'adminsGroups', 'e10.persons.groups', 'e10pro-kb-wikies-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($wiki, 'users', 'e10.persons.persons', 'e10pro-kb-wikies-users', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($wiki, 'usersGroups', 'e10.persons.groups', 'e10pro-kb-wikies-users', $r ['ndx']);

			$wiki['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$wikies [$r['ndx']] = $wiki;
		}

		$cfg ['e10pro']['kb']['wikies'] = $wikies;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.kb.wikies.json', utils::json_lint (json_encode ($cfg)));
	}

	function saveConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}

	function usersWikies ($dashboardPlace = FALSE)
	{
		$wikies = [];
		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$allWikies = $this->app()->cfgItem ('e10pro.kb.wikies', NULL);
		if ($allWikies === NULL)
			return $wikies;

		foreach ($allWikies as $wikiNdx => $w)
		{
			if ($dashboardPlace !== FALSE && $w['dp'] !== $dashboardPlace)
				continue;

			$enabled = 0;
			if (!isset($w['allowAllUsers'])) $enabled = 1;
			elseif ($w['allowAllUsers']) $enabled = 1;
			elseif (isset($w['admins']) && in_array($userNdx, $w['admins'])) $enabled = 1;
			elseif (isset($w['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $w['adminsGroups'])) !== 0) $enabled = 1;
			elseif (isset($w['pageEditors']) && in_array($userNdx, $w['pageEditors'])) $enabled = 1;
			elseif (isset($w['pageEditorGroups']) && count($userGroups) && count(array_intersect($userGroups, $w['pageEditorGroups'])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$wikies[$wikiNdx] = $w;
		}

		return $wikies;
	}

	function userSections ()
	{
		$list = [];

		$thisUserId = $this->app()->userNdx();
		$ug = $this->app()->userGroups ();

		$q [] = 'SELECT sections.ndx FROM [e10pro_kb_sections] AS [sections]';
		array_push ($q, ' WHERE 1');
		//array_push ($q, ' AND [wiki] = %i', $this->wikiNdx);
		array_push ($q, ' AND docStateMain < %i', 4);

		array_push ($q, ' AND (');
		array_push ($q, ' EXISTS (',
			'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
			' WHERE [sections].ndx = srcRecId AND srcTableId = %s', 'e10pro.kb.sections',
			' AND dstTableId = %s', 'e10.persons.persons',
			' AND docLinks.dstRecId = %i', $thisUserId);
		array_push ($q, ')');

		if (count ($ug) !== 0)
		{
			array_push ($q, ' OR ');
			array_push ($q, ' EXISTS (',
				'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
				' WHERE [sections].ndx = srcRecId AND srcTableId = %s', 'e10pro.kb.sections',
				' AND dstTableId = %s', 'e10.persons.groups',
				' AND docLinks.dstRecId IN %in', $ug);
			array_push ($q, ')');
		}

		array_push ($q, 'OR ([sections].[publicRead] = 1)');

		array_push ($q, ')');

		array_push ($q, ' ORDER BY [order], [title]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$list [] = $r['ndx'];
		}

		return $list;
	}
}


/**
 * Class ViewWikies
 * @package e10pro\kb
 */
class ViewWikies extends TableView
{
	var $linkedPersons;

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
		$listItem ['icon'] = $this->table->tableIcon ($item);

		if ($item['publicRead'])
			$listItem['i1'] = ['text' => 'Veřejná', 'icon' => 'icon-users', 'class' => 'label label-success'];

		$props = [];
		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'icon-sort', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]))
		{
			$item ['t2'] = $this->linkedPersons [$item ['pk']];
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_kb_wikies]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[title]', '[ndx]']);
		$this->runQuery ($q);
	}


	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks);
	}
}


/**
 * Class FormSection
 * @package e10pro\kb
 */
class FormWiki extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Wiki', 'icon' => 'icon-book'];
			$tabs ['tabs'][] = ['text' => 'Patička', 'icon' => 'icon-file-text-o'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('title');
					$this->addColumnInput ('publicRead');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('dashboardPlace');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addColumnInput ('pageFooter', TableForm::coFullSizeY);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

