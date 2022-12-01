<?php

namespace helpdesk\core;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Utils;


/**
 * class TableSections
 */
class TableSections extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('helpdesk.core.sections', 'helpdesk_core_sections', 'Sekce helpdesku');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['shortName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [helpdesk_core_sections] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$sections = [];
		foreach ($rows as $r)
		{
			$section = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'],
        'icon' => $r['icon'],
        'order' => intval($r['order'])
			];

			$cntPeoples = 0;

			$cntPeoples += $this->docLinksConfigList ($section, 'members', 'e10.persons.persons', 'helpdesk-sections-members', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($section, 'membersGroups', 'e10.persons.groups', 'helpdesk-sections-members', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($section, 'admins', 'e10.persons.persons', 'helpdesk-sections-admins', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($section, 'adminsGroups', 'e10.persons.groups', 'helpdesk-sections-admins', $r ['ndx']);

			$section['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$sections [$r['ndx']] = $section;
		}

		$cfg ['helpdesk']['sections'] = $sections;
		file_put_contents(__APP_DIR__ . '/config/_helpdesk.sections.json', utils::json_lint (json_encode ($cfg)));
	}

	function docLinksConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
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

  public function usersSections($enabledCfgItem = '')
  {
    $allSections = $this->app()->cfgItem('helpdesk.sections', NULL);
		$sections = [];
		if ($allSections === NULL)
			return $sections;

		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		foreach ($allSections as $itemNdx => $i)
		{
			if ($enabledCfgItem !== '' && !($i[$enabledCfgItem] ?? 0))
				continue;

			$enabled = 0;
			if (!isset($i['allowAllUsers'])) $enabled = 1;
			elseif ($i['allowAllUsers']) $enabled = 1;
			elseif (isset($i['admins']) && in_array($userNdx, $i['admins'])) $enabled = 2;
			elseif (isset($i['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $i['adminsGroups'])) !== 0) $enabled = 2;
			elseif (in_array($userNdx, $i['members'] ?? [])) $enabled = 1;
			elseif (count($userGroups) && count(array_intersect($userGroups, $i['membersGroups'] ?? [])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$sections[$itemNdx] = $i;
			$sections[$itemNdx]['accessLevel'] = $enabled;
		}

    return $sections;
  }
}


/**
 * class ViewSections
 */
class ViewSections extends TableView
{
	var $linkedPersons = [];

	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		//$listItem ['t2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

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

		$q [] = 'SELECT * FROM [helpdesk_core_sections]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->app(), $this->table, $this->pks);
	}

}


/**
 * class FormSection
 */
class FormSection extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addSeparator(self::coH4);
					$this->addList ('doclinksPersons', '', TableForm::loAddToFormLayout);
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailSection
 */
class ViewDetailSection extends TableViewDetail
{
}

