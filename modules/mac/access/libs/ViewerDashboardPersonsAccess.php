<?php

namespace mac\access\libs;

use \Shipard\Viewer\TableView, \e10\utils, \Shipard\Viewer\TableViewPanel, \e10\str;


/**
 * Class ViewerDashboardPersonsAccess
 * @package mac\access\libs
 */
class ViewerDashboardPersonsAccess extends TableView
{
	var $viewerStyle = 0;
	var $paneClass= 'e10-pane';
	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1, dvsPanesMicro = 7;
	var $selectParts = NULL;

	var $pksPersons = [];
	var $personsKeys = [];
	var $accessLevels = [];
	var $tagTypes;

	var $tablePersons;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

		$this->rowsPageSize = 100;

		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'active', 'title' => 'Aktivní', 'icon' => 'icon-check'];
			$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'icon-archive'];
			$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'icon-toggle-on'];
			if ($this->app()->hasRole('pwuser'))
				$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'icon-trash'];
			$this->setMainQueries($mq);
		}

		$vs = $this->queryParam('viewerMode');
		$this->viewerStyle = ($vs === FALSE) ? self::dvsPanesMini : intval($this->queryParam('viewerMode'));

		$this->htmlRowsElementClass = 'e10-dsb-panes';
		$this->htmlRowElementClass = 'post';

		$this->initPaneMode();
		$this->initPanesOptions();

		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->tagTypes = $this->app()->cfgItem('mac.access.tagTypes');

		parent::init();
	}

	function initPaneMode()
	{
		switch ($this->viewerStyle)
		{
			case self::dvsPanes :
				$this->setPaneMode(3, 33);
				break;
			case self::dvsPanesOneCol:
				$this->setPaneMode(2);
				break;
			case self::dvsPanesMini :
				$this->setPaneMode(3, 33);
				break;
			case self::dvsPanesMicro :
				$this->setPaneMode(6, 17);
				break;
			case self::dvsRows:
				$this->setPaneMode(1);
				break;
		}
	}

	function initPanesOptions()
	{
		$this->paneClass= 'e10-pane';

		switch ($this->viewerStyle)
		{
			case self::dvsPanes :
				break;
			case self::dvsPanesOneCol:
				break;
			case self::dvsPanesMini :
				$this->paneClass = 'e10-pane e10-pane-mini';
				break;
			case self::dvsPanesMicro :
				$this->paneClass = 'e10-pane e10-pane-mini';
				break;
			case self::dvsRows:
				$this->paneClass = 'e10-pane e10-pane-row';
				break;
		}
	}
	public function addListItem ($listItem)
	{
		parent::addListItem($listItem);

		if ($listItem['person'] && !in_array($listItem['person'], $this->pksPersons))
			$this->pksPersons[] = $listItem['person'];
	}

	public function selectRows ()
	{
		$q = [];

		if (!$this->selectParts)
		{
			$this->qrySelectRows($q, NULL, 0);
		}
		else
		{
			$index = 0;
			foreach ($this->selectParts as $selectPart)
			{
				if ($index)
					array_push($q, ' UNION ');
				array_push($q, '(');
				$this->qrySelectRows($q, $selectPart, $index);
				array_push($q, ')');

				$index++;
			}
			$this->qryOrderAll ($q);
		}

		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function qrySelectRows (&$q, $selectPart, $selectPartNumber)
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = 'active';

		$q [] = 'SELECT [pa].*, ';
		array_push ($q, ' persons.fullName as personName, persons.id AS personId, persons.company AS personCompany, persons.personType');
		array_push ($q, ' FROM [mac_access_personsAccess] AS [pa]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [pa].[person] = [persons].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' [persons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push($q, ' OR EXISTS (SELECT ta.ndx FROM mac_access_tagsAssignments AS ta ',
				' LEFT JOIN [mac_access_tags] AS tags ON ta.tag = tags.ndx',
				' WHERE ta.person = pa.person AND tags.keyValue LIKE %s', '%'.$fts.'%',
				')');
			array_push ($q, ')');
		}

		$qv = $this->queryValues ();
		if (isset($qv['accessLevels']))
		{
			array_push($q, ' AND EXISTS (SELECT pac.ndx FROM mac_access_personsAccessLevels AS pac ',
				' LEFT JOIN [mac_access_personsAccess] AS thispa ON pac.accessLevel = thispa.ndx',
				' WHERE pac.personAccess = pa.ndx AND pac.accessLevel IN %in', array_keys($qv['accessLevels']),
				')');
		}

		if ($mqId == 'active')
			array_push($q, ' AND [pa].[docStateMain] < 4');
		elseif ($mqId == 'archive')
			array_push($q, ' AND [pa].[docStateMain] = 5');
		elseif ($mqId == 'trash')
			array_push($q, ' AND [pa].[docStateMain] = 4');

		$this->qryOrder($q, $selectPart);
	}

	protected function qryOrder (&$q, $selectPart)
	{
		$mqId = $this->mainQueryId ();

		if ($mqId === '' || $mqId === 'active' || $mqId === 'assigned' || $mqId === 'unassigned')
			array_push ($q, ' ORDER BY [pa].[docStateMain], [persons].[lastName]');
		else
			array_push ($q, ' ORDER BY [pa].[ndx]');
	}

	protected function qryOrderAll (&$q)
	{
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- keys
		if (count($this->pksPersons))
		{
			$q[] = 'SELECT ta.*, [tags].keyValue, [tags].tagType FROM [mac_access_tagsAssignments] AS [ta]';
			array_push($q, ' LEFT JOIN [mac_access_tags] AS [tags] ON [ta].tag = [tags].ndx');
			array_push($q, ' WHERE [ta].[person] IN %in', $this->pksPersons);
			array_push($q, ' AND [ta].[docState] IN %in', [1000, 4000, 8000]);
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$item = [
					'text' => $r['keyValue'], 'icon' => $this->tagTypes[$r['tagType']]['icon'], 'class' => 'label label-default',
					'suffix' => utils::dateFromTo($r['validFrom'], $r['validTo'], NULL)
				];

				if (!$r['tag'])
				{
					$item['text'] = 'VADNÝ KLÍČ';
					$item['class'] = 'label label-danger';
					$item['icon'] = 'system/iconWarning';
				}
				elseif ($r['docState'] !== 4000)
				{
					$item['class'] = 'label label-warning';
				}

				$this->personsKeys[$r['person']][] = $item;
			}
		}

		// -- levels
		$q= [];
		$q[] = 'SELECT pal.*, [al].fullName FROM [mac_access_personsAccessLevels] AS [pal]';
		array_push($q, ' LEFT JOIN [mac_access_levels] AS [al] ON [pal].[accessLevel] = [al].ndx');
		array_push($q, ' WHERE [pal].[ndx] IN %in', $this->pks);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'text' => $r['fullName'], 'icon' => 'icon-empire', 'class' => 'label label-default',
				'suffix' => utils::dateFromTo($r['validFrom'], $r['validTo'], NULL)
			];
			$this->accessLevels[$r['personAccess']][] = $item;
		}
	}

	function renderPane (&$item)
	{
		$paneClass = 'padd5';
		if ($item['keyValue'] === '')
			$paneClass .= ' e10-warning3';

		$ndx = $item ['ndx'];

		$item['pk'] = $ndx;
		$item ['pane'] = ['title' => [], 'body' => [], 'class' => $this->paneClass];
		$item ['pane']['class'] .= ' e10-ds '.$item ['docStateClass'];

		$title = [];

		$person = ['fullName' => $item['personName'], 'ndx' => $item['person'], 'personType' => $item['personType'], 'company' => $item['personCompany']];
		$title[] = ['class' => 'e10-bold', 'text' => $item['personName'], 'icon' => $this->tablePersons->tableIcon($person)];
		$title[] = ['text' => '#'.$item['personId'], 'class' => 'e10-small pull-right'];

		$item ['pane']['title'][] = [
			'class' => $paneClass,
			'value' => $title, 'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'mac.access.personsAccess'
		];

		if (isset ($this->accessLevels[$item ['pk']]))
			$item ['pane']['body'][] = ['value' => $this->accessLevels[$item ['pk']], 'class' => 'padd5'];
		if (isset ($this->personsKeys[$item ['person']]))
			$item ['pane']['body'][] = ['value' => $this->personsKeys[$item ['person']], 'class' => 'padd5'];
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');
		$qry = [];

		// -- add buttons
		$addButtons = [];

		$addButtons[] = [
			'action' => 'new', 'data-table' => 'mac.access.personsAccess', 'icon' => 'icon-plus-circle',
			'text' => 'Nová Osoba',
			'type' => 'button', 'actionClass' => 'btn',
			'class' => 'btn-block', 'btnClass' => 'btn-success btn-block',
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
		];

		/*
		$addButtons[] = [
			'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10.persons.persons',
			'text' => 'Přidat hromadně', 'data-class' => 'mac.access.libs.WizardAddTagsBatch', 'icon' => 'icon-tags',
			'class' => 'btn-block', 'actionClass' => 'btn btn-block', 'btnClass' => 'btn-primary',
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => '',
		];
		*/

		$qry[] = ['style' => 'content', 'type' => 'line', 'line' => $addButtons, 'pane' => 'e10-pane-params'];

		// -- access levels
		$allAccessLevels = $this->db()->query ('SELECT ndx, fullName FROM mac_access_levels WHERE docStateMain != 4')->fetchPairs ('ndx', 'fullName');
		$accessLevels = [];
		forEach ($allAccessLevels as $ttId => $tt)
			$accessLevels[$ttId] = ['title' => $tt, 'id' => $ttId];

		$paramsAccessLevels = new \E10\Params ($panel->table->app());
		$paramsAccessLevels->addParam ('checkboxes', 'query.accessLevels', ['items' => $accessLevels]);
		$qry[] = ['style' => 'params', 'title' => ['text' => 'Úrovně přístupu', 'icon' => 'icon-empire'], 'params' => $paramsAccessLevels];
		$paramsAccessLevels->detectValues();

		if (count($qry))
			$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createTopMenuSearchCode ()
	{
		return $this->createCoreSearchCode('e10-sv-search-toolbar-fixed');
	}

	function createCoreSearchCode($fixed = FALSE)
	{
		$fts = utils::es($this->fullTextSearch ());
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$bgColor = $fixed ? 'rgba(0,0,0,.1)' : 'transparent';

		$placeholder = 'hledat ⏎';

		$c = '';

		$c .= "<div class='e10-sv-search e10-sv-search-toolbar' style='background-color: $bgColor; border: none; padding: 0 1ex;' data-style='padding: .5ex 1ex 1ex 1ex; display: inline-block; width: 100%;' id='{$this->vid}Search'>";
		$c .=	"<table style='width: 100%'><tr>";
		$c .= "<td class='fulltext' style='width:95%;'>";
		$c .=	"<span class='' style='width: 2em;text-align: center;position: absolute;padding-top: 2ex; opacity: .8;'><icon class='fa fa-search' style='width: 1.1em;'></i></span>";
		$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' placeholder='".utils::es($placeholder)."' value='$fts' style='width: calc(100% - 1em); padding: 6px 2em;'/>";
		$c .=	"<span class='df2-background-button df2-action-trigger df2-fulltext-clear' data-action='fulltextsearchclear' id='{$this->vid}Progress' data-run='0' style='margin-left: -2.5em; padding: 6px 2ex 3px 1ex; position:inherit; width: 2.5em; text-align: center;'><icon class='fa fa-times' style='width: 1.1em;'></i></span>";
		$c .= '</td>';

		$c .= "<td class='' style=''>";
		$c .= "<div class='viewerQuerySelect e10-dashboard-viewer'>";
		$c .= "<input name='mainQuery' type='hidden' value='$mqId'/>";
		$idx = 0;
		forEach ($this->mainQueries as $q)
		{
			if ($mqId === $q['id'])
				$active = ' active';
			$txt = utils::es ($q ['title']);
			$c .= "<span class='q$active' data-mqid='{$q['id']}' title='$txt'>".$this->app()->ui()->renderTextLine($q)."</span>";
			$idx++;
			$active = '';
		}
		$c .= '</div>';
		$c .= '</td>';

		$c .= '</tr></table>';
		$c .= '</div>';

		return $c;
	}

	public function createDetails ()
	{
		return [];
	}
}
