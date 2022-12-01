<?php

namespace helpdesk\core\libs;

use \Shipard\Viewer\TableViewGrid, \Shipard\Utils\World, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;
use \Shipard\Viewer\TableViewPanel;



/**
 * class ViewTicketsGrid
 */
class ViewTicketsGrid extends TableViewGrid
{
	/** @var  \helpdesk\core\TableSections */
	var $tableSections;
	var $usersSections = NULL;
	var $allSections = NULL;

	var $classification = [];
	var $linkedPersons = [];


	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;
    $this->type = 'form';

    $this->fullWidthToolbar = TRUE;
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->objectSubType = self::vsMain;
		$this->linesWidth = 65;
		$this->setPanels (self::sptQuery);

    $this->tableSections = $this->app->table ('helpdesk.core.sections');
		$this->usersSections = $this->tableSections->usersSections();
		if (count($this->usersSections))
    	$this->addAddParam ('helpdeskSection', key($this->usersSections));



		//$this->createBottomTabs();

		$g = [
			'ticketId' => 'ID',
		];

		$g['subject'] = 'Předmět';
		$g['author'] = 'Autor';
		$g['date'] = 'Datum';
		//$g['note'] = 'Pozn.';

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['subject'] = $item['subject'];
		$listItem ['author'] = $item['authorName'];

		$listItem ['date'] = Utils::datef($item['dateCreate'], '%S%t');

		$listItem ['ticketId'] = $item['ticketId'];

		//if ($item['text'] !== '')
		//	$listItem ['text'] = [['text' => $item['note'], 'class' => 'block']];

		//$listItem ['begin'] = $item['datePlanBegin'];
		//$listItem ['deadline'] = $item['dateDeadline'];

		/*
		if ($itemState)
		{
			$listItem ['icon'] = $itemState['icon'] ?? '';//$this->table->tableIcon ($item);

			$css = "background-color: ".$itemState['colorbg'].'; color: '.$itemState['colorfg'];
			$listItem['_options']['cellCss'] = ['subject' => $css];
		}
		*/

		//$listItem['_options']['cellCss']['note'] = 'line-height: 1.5;';

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			if (!isset($item ['note']))
				$item ['note'] = [];
			else
				$item ['note'][0]['class'] .= ' pb1';

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['note'] = array_merge ($item ['note'], $clsfGroup);
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = $this->bottomTabId();

		$q = [];
		array_push ($q, ' SELECT [tickets].*,');
		array_push ($q, ' authors.fullName AS authorName');
		array_push ($q, ' FROM [helpdesk_core_tickets] AS [tickets]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [authors] ON [tickets].[author] = [authors].ndx');
		//array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [personsCust] ON [items].[personCustomer] = [personsCust].ndx');

		array_push ($q, ' WHERE 1');

		/*
		if ($this->fixedMainQuery === 'active')
		{
			$inProgressIS = array_merge($this->lifeCycleItemStates[20] ?? [], $this->lifeCycleItemStates[10] ?? []);

			if (count($inProgressIS))
				array_push ($q, ' AND [itemState] IN %in', $inProgressIS);
		}
		*/

		// -- public/private item
		/*
		$thisUserId = $this->app()->userNdx();
		array_push ($q, ' AND (');
		array_push ($q, ' [items].isPrivate = %i', 0);
		array_push ($q, ' OR ([items].isPrivate = %i', 1);
		array_push ($q, ' AND EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE [items].ndx = srcRecId',
			' AND srcTableId = %s','plans.core.items',
			' AND dstTableId = %s', 'e10.persons.persons',
			' AND dstRecId = %i', $thisUserId, ')');
		array_push ($q, ')');
		array_push ($q, ')');
		*/

		// -- special queries
		$qv = $this->queryValues ();
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE tickets.ndx = recid AND tableId = %s', $this->table->tableId());
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [tickets].[subject] LIKE %s', '%'.$fts.'%');
			//array_push ($q, ' OR [projectId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [note] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		//$this->queryMain ($q, 'items.', ['!ISNULL([dateDeadline]) DESC', '[dateDeadline]', '[datePlanBegin]', '[ndx]']);
		$this->queryMain ($q, 'tickets.', ['[ndx]']);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
		$this->linkedPersons = UtilsBase::linkedPersons ($this->app(), $this->table, $this->pks);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createToolbarAddButton (&$toolbar)
	{
    if (!$this->usersSections || !count($this->usersSections))
      return;

    if (count($this->usersSections) === 1)
    {
      parent::createToolbarAddButton ($toolbar);
      return;
    }

		$addButton = [
			'icon' => 'system/actionAdd', 'action' => '',
			'text' => 'Přidat', 'type' => 'button',
			'class' => 'pull-right-absolute',
			'dropdownMenu' => []
		];

		foreach ($this->usersSections as $sectionNdx => $s)
		{
			$addButton['dropdownMenu'][] = [
				'type' => 'action', 'action' => 'newform', 'text' => $s['fn'],
				'icon' => ($s['icon'] === '') ? : $s['icon'],
				'data-addParams' => '__helpdeskSection='.$sectionNdx,
			];
		}

    $toolbar[] = $addButton;
  }
}
