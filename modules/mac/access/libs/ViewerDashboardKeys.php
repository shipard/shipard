<?php

namespace mac\access\libs;

use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10\str;


/**
 * Class ViewerDashboardKeys
 * @package mac\access\libs
 */
class ViewerDashboardKeys extends TableView
{
	var $viewerStyle = 0;
	var $paneClass= 'e10-pane';
	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1, dvsPanesMicro = 7;
	var $selectParts = NULL;

	/** @var \mac\access\TableTagsAssignments */
	var $tableTagsAssignments;
	var $tagsAssignments = [];

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

		$this->tableTagsAssignments = $this->app()->table('mac.access.tagsAssignments');

		$this->rowsPageSize = 100;

		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'active', 'title' => 'Aktivní', 'icon' => 'icon-check'];
			$mq [] = ['id' => 'assigned', 'title' => 'Přiřazeno', 'icon' => 'icon-user'];
			$mq [] = ['id' => 'unassigned', 'title' => 'Nepřiřazeno', 'icon' => 'icon-times'];
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
				$this->setPaneMode(4, 25);
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

		$q [] = 'SELECT [tags].* ';

		if ($selectPart)
			array_push ($q, ' %i', $selectPartNumber, ' AS selectPartOrder, %s', $selectPart, ' AS selectPart,');

		array_push ($q, ' FROM [mac_access_tags] AS [tags]');
		array_push ($q, ' WHERE 1');

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [tags].[id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [tags].[note] LIKE %s', '%'.$fts.'%');

			$keyValue = str::scannerString($fts);
			if (str::strlen($keyValue) === str::strlen($fts) && $keyValue == intval($keyValue))
				array_push ($q, ' OR [tags].[keyValue] LIKE %s', $keyValue.'%');

			array_push ($q, ' OR [tags].[keyValue] LIKE %s', '%'.$fts.'%');

			// -- person
			array_push($q, ' OR EXISTS (SELECT ta.ndx FROM mac_access_tagsAssignments AS ta ',
				' LEFT JOIN [e10_persons_persons] AS persons ON ta.person = persons.ndx AND [ta].assignType = %i', 0,
				' WHERE ta.tag = tags.ndx AND persons.fullName LIKE %s', '%'.$fts.'%',
				')');

			// -- place
			array_push($q, ' OR EXISTS (SELECT ta.ndx FROM mac_access_tagsAssignments AS ta ',
				' LEFT JOIN [e10_base_places] AS places ON ta.place = places.ndx AND [ta].assignType = %i', 1,
				' WHERE ta.tag = tags.ndx AND places.fullName LIKE %s', '%'.$fts.'%',
				')');

			array_push ($q, ')');
		}

		$qv = $this->queryValues ();
		if (isset($qv['tagTypes']))
			array_push ($q, ' AND [tags].[tagType] IN %in', array_keys($qv['tagTypes']));

		if ($mqId == 'active')
			array_push($q, ' AND [tags].[docStateMain] < 4');
		elseif ($mqId == 'assigned')
		{
			array_push($q, ' AND EXISTS (SELECT ndx FROM [mac_access_tagsAssignments] WHERE [tags].ndx = [tag])');
			array_push($q, ' AND [tags].[docStateMain] < 4');
		}
		elseif ($mqId == 'unassigned')
		{
			array_push($q, ' AND NOT EXISTS (SELECT ndx FROM [mac_access_tagsAssignments] WHERE [tags].ndx = [tag])');
			array_push($q, ' AND [tags].[docStateMain] < 4');
		}
		elseif ($mqId == 'archive')
			array_push($q, ' AND [tags].[docStateMain] = 5');
		elseif ($mqId == 'trash')
			array_push($q, ' AND [tags].[docStateMain] = 4');

		$this->qryOrder($q, $selectPart);
	}

	protected function qryOrder (&$q, $selectPart)
	{
		$mqId = $this->mainQueryId ();

		if ($mqId === '' || $mqId === 'active' || $mqId === 'assigned' || $mqId === 'unassigned')
			array_push ($q, ' ORDER BY [tags].[docStateMain], [tags].[keyValue]');
		else
			array_push ($q, ' ORDER BY [tags].[ndx]');
	}

	protected function qryOrderAll (&$q)
	{
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$tablePersons = $this->app()->table('e10.persons.persons');

		// -- tags assignments
		$q [] = 'SELECT assignments.*, tags.id AS tagId,';
		array_push ($q, ' persons.fullName as personName, persons.id AS personId, persons.company AS personCompany, persons.personType,');
		array_push ($q, ' places.fullName as placeName');
		array_push ($q, ' FROM [mac_access_tagsAssignments] AS assignments');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON assignments.person = persons.ndx');
		array_push ($q, ' LEFT JOIN [mac_access_tags] AS tags ON assignments.tag = tags.ndx');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON assignments.place = places.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND assignments.[tag] IN %in', $this->pks);
		array_push ($q, ' AND assignments.[docState] IN %in', [1000, 4000, 8000]);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$docStates = $this->tableTagsAssignments->documentStates ($r);
			$docStateClass = $this->tableTagsAssignments->getDocumentStateInfo ($docStates, $r, 'styleClass');

			$item = [
				'ndx' => $r['ndx'], 'dsc' => $docStateClass,
				'assignType' => $r['assignType'],
				'validFrom' => $r['validFrom'], 'validTo' => $r['validTo'],

			];

			if ($r['assignType'] === 0)
			{ // person
				$item['person'] = [
					'fullName' => $r['personName'], 'ndx' => $r['person'],
					'personType' => $r['personType'], 'company' => $r['personCompany'],
				];
				$item['useCautionMoney'] = $r['useCautionMoney'];
				$item['cautionMoneyAmount'] = $r['cautionMoneyAmount'];
				$item['person']['icon'] = $tablePersons->tableIcon($item['person']);
			}
			elseif ($r['assignType'] === 1)
			{ // place
				$item['place'] = [
					'fullName' => $r['placeName']
				];
			}

			$this->tagsAssignments[$r['tag']][] = $item;
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

		$title[] = ['class' => 'e10-bold', 'text' => $item['keyValue'], 'icon' => $this->table->tableIcon($item)];

		if ($item['ownTag'])
			$title[] = ['text' => '', 'title' => 'Vlastní čip', 'icon' => 'icon-user-circle', 'class' => 'e10-me pull-right'];

		if ($item['note'] !== '')
			$title[] = ['text' => $item['note'], 'class' => 'e10-small pull-right'];

		$title[] = ['text' => '', 'class' => 'block'];

		$item ['pane']['title'][] = [
			'class' => $paneClass,
			'value' => $title, 'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'mac.access.tags'
		];

		// -- tags assignment
		$list = ['rows' => [], 'table' => 'mac.access.tagsAssignments', 'docStateClass' => 'e10-ds-block'];
		if (isset($this->tagsAssignments[$ndx]))
		{
			foreach ($this->tagsAssignments[$ndx] as $ta)
			{
				$row = ['info' => [], 'ndx' => $ta['ndx'], 'docStateClass' => $ta['dsc']];
				$tt = [];
				if ($ta['assignType'] === 0)
				{
					if ($ta['person']['ndx'])
						$tt[] = ['text' => $ta['person']['fullName'], 'icon' => $ta['person']['icon'], 'class' => ''];
					else
						$tt[] = ['text' => 'Vadná osoba', 'icon' => 'icon-exclamation-triangle', 'class' => 'e10-error'];
					$tt[] = ['text' => utils::dateFromTo($ta['validFrom'], $ta['validTo'], NULL), 'xicon' => 'icon-keyboard-o', 'class' => 'e10-small'];
					if ($ta['useCautionMoney'])
						$tt[] = ['text' => utils::nf($ta['cautionMoneyAmount']), 'title' => 'Kauce', 'icon' => 'icon-money', 'class' => 'label label-default'];
				}
				elseif ($ta['assignType'] === 1)
				{
					$tt[] = ['text' => $ta['place']['fullName'], 'icon' => 'icon-map-marker', 'class' => ''];
					$tt[] = ['text' => utils::dateFromTo($ta['validFrom'], $ta['validTo'], NULL), 'xicon' => 'icon-keyboard-o', 'class' => 'e10-small'];
				}
				$row['title'] = $tt;
				$list['rows'][] = $row;
			}
		}
		else
		{
			$row = ['info' => []];
			$tt = [];
			$tt[] = ['text' => 'nepřiřazeno', 'icon' => 'icon-times', 'class' => 'e10-small'];
			$tt[] = [
				'action' => 'new', 'data-table' => 'mac.access.tagsAssignments', 'icon' => 'icon-plus-circle',
				'text' => 'Přiřadit',
				'type' => 'button', 'actionClass' => 'btn',
				'class' => 'pull-right', 'btnClass' => 'btn-success btn-xs',
				'data-addParams' => '__tag='.$ndx.'&__validFrom='.utils::today('Y-m-d'),
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
			];
			$row['title'] = $tt;
			$list['rows'][] = $row;
		}
		$item ['pane']['body'][] = ['list' => $list];
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');
		$qry = [];

		// -- add buttons
		$addButtons = [];

		$addButtons[] = [
			'action' => 'new', 'data-table' => 'mac.access.tags', 'icon' => 'icon-plus-circle',
			'text' => 'Nový klíč',
			'type' => 'button', 'actionClass' => 'btn',
			'class' => 'btn-block', 'btnClass' => 'btn-success btn-block',
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
		];

		$addButtons[] = [
			'type' => 'action', 'action' => 'addwizard', 'data-table' => 'e10.persons.persons',
			'text' => 'Přidat hromadně', 'data-class' => 'mac.access.libs.WizardAddTagsBatch', 'icon' => 'icon-tags',
			'class' => 'btn-block', 'actionClass' => 'btn btn-block', 'btnClass' => 'btn-primary',
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => '',
		];

		$qry[] = ['style' => 'content', 'type' => 'line', 'line' => $addButtons, 'pane' => 'e10-pane-params'];

		// -- tag types
		$allTagTypes = $this->app()->cfgItem ('mac.access.tagTypes');
		$tagTypes = [];
		forEach ($allTagTypes as $ttId => $tt)
			$tagTypes[$ttId] = ['title' => ['text' => $tt['name'], 'icon' => $tt['icon']], 'id' => $ttId];

		$paramsTagTypes = new \E10\Params ($panel->table->app());
		$paramsTagTypes->addParam ('checkboxes', 'query.tagTypes', ['items' => $tagTypes]);
		$qry[] = ['style' => 'params', 'title' => ['text' => 'Druhy klíčů', 'icon' => 'icon-key'], 'params' => $paramsTagTypes];
		$paramsTagTypes->detectValues();

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
