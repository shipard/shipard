<?php

namespace e10\web;

use \E10\utils, \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \e10\base\libs\UtilsBase;

/**
 * Class TableArticlesSections
 */
class TableArticlesSections extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.articlesSections', 'e10_web_articlesSections', 'Sekce článků');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'fullName', 'value' => $recData ['title']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [e10_web_articlesSections] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$list = [];
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'],
				'title' => $r['title'], 'icon' => $r['icon'],
				'addGallery' => $r['addGallery'], 'addDownload' => $r['addDownload'],
				'showDate' => $r['showDate'], 'showAuthor' => $r['showAuthor'],
				'showDateOrAuthor' => intval($r['showDate'] + $r['showAuthor']),
			];

			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($item, 'admins', 'e10.persons.persons', 'e10-web-articleSection-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($item, 'adminsGroups', 'e10.persons.groups', 'e10-web-articleSection-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($item, 'authors', 'e10.persons.persons', 'e10-web-articleSection-authors', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($item, 'authorsGroups', 'e10.persons.groups', 'e10-web-articleSection-authors', $r ['ndx']);

			$item['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$list [$r['ndx']] = $item;
		}

		$cfg ['e10']['web']['articlesSections'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10.web.articlesSections.json', utils::json_lint (json_encode ($cfg)));
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

	function usersSections ()
	{
		$list = [];
		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$allSections = $this->app()->cfgItem ('e10.web.articlesSections', NULL);
		if ($allSections === NULL)
			return $list;

		foreach ($allSections as $one)
		{
			$enabled = 0;
			if ($one['allowAllUsers']) $enabled = 1;
			elseif (isset($one['admins']) && in_array($userNdx, $one['admins'])) $enabled = 1;
			elseif (isset($one['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $one['adminsGroups'])) !== 0) $enabled = 1;
			elseif (isset($one['authors']) && in_array($userNdx, $one['authors'])) $enabled = 1;
			elseif (isset($one['authorsGroups']) && count($userGroups) && count(array_intersect($userGroups, $one['authorsGroups'])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$list[$one['ndx']] = $one;
		}

		return $list;
	}
}


/**
 * Class ViewWikies
 * @package e10pro\kb
 */
class ViewArticlesSections extends TableView
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
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

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

		$q [] = 'SELECT * FROM [e10_web_articlesSections]';
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
 * Class FormArticleSection
 * @package e10\web
 */
class FormArticleSection extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('title');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('showDate');
					$this->addColumnInput ('showAuthor');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('addGallery');
					$this->addColumnInput ('addDownload');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

