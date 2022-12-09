<?php

namespace hosting\core\libs;

use \Shipard\Viewer\TableViewGrid, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;
use \Shipard\Viewer\TableViewPanel;


/**
 * class ViewerHelpdeskAdmins
 */
class ViewerHelpdeskAdmins extends TableViewGrid
{
	/** @var  \helpdesk\core\TableSections */
	var $tableSections;
	var $usersSections = NULL;
	var $allSections = NULL;

	var $classification = [];
	var $linkedPersons = [];
	var $notifications = [];

	public function init ()
	{
		parent::init();

		$this->loadNotifications ();

		$this->enableDetailSearch = TRUE;
    $this->type = 'form';

    $this->fullWidthToolbar = TRUE;
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->objectSubType = self::vsMain;
		$this->linesWidth = 50;
		$this->setPanels (self::sptQuery);

    $dsId = $this->queryParam ('dsId');

    if ($dsId !== FALSE)
    {
      $dsRecData = $this->db()->query('SELECT * FROM [hosting_core_dataSources] WHERE [gid] = %s', $dsId)->fetch();
      if ($dsRecData)
        $this->addAddParam ('dataSource', $dsRecData['ndx']);
    }

    $this->tableSections = $this->app->table ('helpdesk.core.sections');
		$this->usersSections = $this->tableSections->usersSections();

    $this->addAddParam ('helpdeskSection', 1);

		$g = [
			'ticketId' => '#',
		];

		$g['priority'] = '*P';
    $g['ds'] = 'Databáze';
		$g['subject'] = 'Předmět';
		$g['stateInfo'] = 'Stav';

		$this->setGrid ($g);
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
    $listItem ['ds'] = $item['dsName'];

		$listItem ['_options']['cellClasses']['stateInfo'] = 'lh16';

		if (isset($this->notifications[$item ['ndx']]))
		{
			$listItem['rowNtfBadge'] = "<span style='display: relative'><span class='e10-ntf-badge' style='display: inline;'>".count($this->notifications[$item ['ndx']])."</span></span>";
		}
		$listItem ['subject'] = [['text' => $item['subject'], 'class' => 'block']];
		//$listItem ['author'] = $item['authorName'];
		//$listItem ['date'] = Utils::datef($item['dateCreate'], '%S%t');

		$listItem ['stateInfo'] = [];
		$this->table->ticketStateInfo($item, $listItem ['stateInfo']);

		$listItem ['ticketId'] = $item['ticketId'];

		if (!$this->docState)
			$this->docState = $this->table->getDocumentState ($item);
		if ($this->docState)
		{
			$docStateIcon = $this->table->getDocumentStateInfo ($this->docState ['states'], $item, 'styleIcon');
			$listItem ['icon'] = $docStateIcon;
		}

		$ticketPriority = $this->app()->cfgItem('helpdesk.ticketPriorities.'.$item['priority'], NULL);
		if ($ticketPriority)
		{
			if (isset($ticketPriority['icon']))
				$listItem ['priority'] = ['text' => '', 'title' => $ticketPriority['sn'], 'icon' => $ticketPriority['icon'], ];
			else
				$listItem ['priority'] = ['text' => '', 'title' => $ticketPriority['sn'], ];
		}

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			if (!isset($item ['subject']))
				$item ['subject'] = [];
			else
				$item ['subject'][0]['class'] .= ' e10-bold';

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['subject'] = array_merge ($item ['subject'], $clsfGroup);
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = $this->bottomTabId();

		$q = [];
		array_push ($q, ' SELECT [tickets].*,');
		array_push ($q, ' authors.fullName AS authorName,');
    array_push ($q, ' ds.shortName AS dsName');
		array_push ($q, ' FROM [helpdesk_core_tickets] AS [tickets]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [authors] ON [tickets].[author] = [authors].ndx');
    array_push ($q, ' LEFT JOIN [hosting_core_dataSources] AS [ds] ON [tickets].[dataSource] = [ds].ndx');
		array_push ($q, ' WHERE 1');

		// -- special queries
		$qv = $this->queryValues ();
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE tickets.ndx = recid AND tableId = %s', $this->table->tableId());
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		// -- priority
		if (isset($qv['ticketPriority']))
			array_push ($q, ' AND [tickets].[priority] IN %in', array_keys($qv['ticketPriority']));

		// -- data sources
		if (isset($qv['ds']))
			array_push ($q, ' AND [tickets].[dataSource] IN %in', array_keys($qv['ds']));

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [tickets].[subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [tickets].[text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [tickets].[ticketId] LIKE %s', $fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'tickets.', ['-[proposedDeadline] DESC', '[priority]', '[dateTouch]']);

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

		// -- priority
		$ticketPriorities = $this->table->columnInfoEnum('priority');
		$this->qryPanelAddCheckBoxes($panel, $qry, $ticketPriorities, 'ticketPriority', 'Důležitost');

		// -- data sources
		$qds[] = 'SELECT * FROM hosting_core_dataSources AS ds';
		array_push ($qds, ' WHERE [helpdeskMode] != %i', 0);
		array_push ($qds, ' ORDER BY ds.shortName');
		$dss = $this->db()->query ($qds)->fetchPairs ('ndx', 'shortName');
		$this->qryPanelAddCheckBoxes($panel, $qry, $dss, 'ds', 'Databáze');

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

	protected function loadNotifications ()
	{
		$q = 'SELECT * FROM e10_base_notifications WHERE state = 0 AND personDest = %i AND tableId = %s';
		$rows = $this->db()->query ($q, $this->app()->userNdx(), $this->table->tableId());
		foreach ($rows as $r)
		{
			$this->notifications[$r['recIdMain']][] = $r->toArray();
		}
	}
}
