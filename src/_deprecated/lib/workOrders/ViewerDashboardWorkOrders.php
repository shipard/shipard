<?php

namespace lib\workOrders;


use \Shipard\Viewer\TableView, \e10\utils, \Shipard\Viewer\TableViewPanel;


/**
 * Class ViewerDashboardWorkOrders
 * @package lib\workOrders
 */
class ViewerDashboardWorkOrders extends TableView
{
	protected $msgTypes;
	protected $textRenderer;
	protected $linkedPersons;
	protected $properties;
	protected $classification;
	protected $atts;
	protected $cntLinkedMsgs;
	protected $useText = FALSE;
	protected $currencies;

	var $selectParts = NULL;

	var $comments = [];
	var $attachmentsComments;
	var $notifications = [];
	var $notifyPks = [];
	var $withNewComment = [];

	protected $today;
	var $thisUserId = 0;
	var $uiPlace = '';

	protected $forceMainQuery = FALSE;

	var $workOrderGroup = 0;

	var $viewerStyle = 0;
	var $withBody = TRUE;
	var $paneClass= 'e10-pane';
	var $simpleHeaders = FALSE;
	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1;

	var $sourcesIcons = [0 => 'icon-keyboard-o', 1 => 'icon-envelope-o', 2 => 'icon-plug', 3 => 'icon-android'];
	var $msgKinds;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'active', 'title' => 'Živé', 'icon' => 'system/filterActive'];
			$mq [] = ['id' => 'done', 'title' => 'Hotové', 'icon' => 'system/filterDone'];
			$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
			$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash'];
			$this->setMainQueries($mq);
		}

		$this->workOrderGroup = $this->queryParam('workOrderGroup');

		$this->viewerStyle = intval($this->queryParam('viewerMode'));

		$this->htmlRowsElementClass = 'e10-dsb-panes';
		$this->htmlRowElementClass = 'post';

		switch ($this->viewerStyle)
		{
			case self::dvsPanes :
				$this->setPaneMode(0, 26);
				break;
			case self::dvsPanesOneCol:
				$this->setPaneMode(1);
				break;
			case self::dvsPanesMini :
				$this->setPaneMode(0, 17);
				break;
			case self::dvsRows:
				$this->setPaneMode(1);
				break;
		}
		$this->initPanesOptions();

		if (!$this->thisUserId)
			$this->thisUserId = intval($this->table->app()->user()->data ('id'));

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());

		$this->linesWidth = 45;

		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->today = utils::today();

		$this->loadNotifications();

		parent::init();
	}

	function initPanesOptions()
	{
		$this->withBody = TRUE;
		$this->paneClass= 'e10-pane';
		$this->simpleHeaders = FALSE;

		switch ($this->viewerStyle)
		{
			case self::dvsPanes :
				break;
			case self::dvsPanesOneCol:
				break;
			case self::dvsPanesMini :
				$this->withBody = FALSE;
				$this->paneClass = 'e10-pane e10-pane-mini';
				$this->simpleHeaders = TRUE;
				break;
			case self::dvsRows:
				$this->withBody = FALSE;
				$this->paneClass = 'e10-pane e10-pane-row';
				break;
		}
	}

	public function selectRows ()
	{
		$q = [];

		$this->qrySelectRows($q, NULL, 0);

		array_push($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function qrySelectRows (&$q, $selectPart, $selectPartNumber)
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';

		$q [] = 'SELECT workOrders.*, ';
		array_push ($q, ' customers.fullName as customerFullName ');
		array_push ($q, ' FROM [e10mnf_core_workOrders] as workOrders');
		array_push ($q, ' LEFT JOIN e10_persons_persons as customers ON workOrders.customer = customers.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' workOrders.docNumber LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR workOrders.title LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR customers.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		// -- docState
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = 'active';
		if ($mqId === 'active' || $mqId == '')
		{
			if ($fts != '')
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1, 2)');
			else
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1)');
		}

		if ($mqId === 'done')
			array_push ($q, ' AND workOrders.[docStateMain] = 2');
		//array_push ($q, ' AND workOrders.[dateClosed] IS NOT NULL');

		if ($mqId === 'discarded')
			array_push ($q, ' AND workOrders.[docStateMain] = 5');
		if ($mqId === 'trash')
			array_push ($q, ' AND workOrders.[docStateMain] = 4');

		// -- workOrderGroup
		if ($this->workOrderGroup)
		{
			$wgo = $this->app()->cfgItem ('e10mnf.base.workOrdersGroups.'.$this->workOrderGroup);
			if (isset($wgo['docKinds']) && count($wgo['docKinds']))
				array_push ($q, ' AND workOrders.[docKind] IN %in', $wgo['docKinds']);
		}

		// -- msgTypes
		//$this->qryMessageTypes($q, $selectPart);
		//$this->qryClsf ($q);
		$this->qryOrder($q, $selectPart);
	}

	public function qryMessageTypes (&$q, $selectPart)
	{
		//if (isset ($this->msgTypes))
		//	array_push ($q, " AND messages.[msgType] IN %in", $this->msgTypes);

	}

	function qryForLinkedPersons (&$q, $linkId = FALSE)
	{
		array_push ($q, ' AND (');

		array_push ($q, ' EXISTS (',
			'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
			' WHERE docs.ndx = srcRecId AND srcTableId = %s', 'e10pro.wkf.documents',
			' AND dstTableId = %s', 'e10.persons.persons',
			' AND docLinks.dstRecId = %i', $this->thisUserId);
		if ($linkId !== FALSE)
		{
			if (is_array($linkId))
				array_push($q, ' AND docLinks.linkId IN %in', $linkId);
			else
				array_push($q, ' AND docLinks.linkId = %s', $linkId);
		}
		array_push ($q, ')');

		$ug = $this->table->app()->userGroups ();
		if (count ($ug) !== 0)
		{
			array_push ($q, ' OR ');
			array_push ($q, ' EXISTS (',
				'SELECT docLinks.dstRecId FROM [e10_base_doclinks] as docLinks',
				' WHERE docs.ndx = srcRecId AND srcTableId = %s', 'e10pro.wkf.documents',
				' AND dstTableId = %s', 'e10.persons.groups',
				' AND docLinks.dstRecId IN %in', $ug);
			if ($linkId !== FALSE)
			{
				if (is_array($linkId))
					array_push($q, ' AND docLinks.linkId IN %in', $linkId);
				else
					array_push($q, ' AND docLinks.linkId = %s', $linkId);
			}
			array_push ($q, ')');
		}

		// -- my new issues
		array_push ($q, ' OR ');
		array_push ($q, ' (docs.author = %i', $this->thisUserId, ' AND docs.docStateMain = 0)');


		array_push ($q, ')');
	}

	public function qryClsf (&$q)
	{
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE docs.ndx = recid AND tableId = %s', 'e10pro.wkf.documents');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}
	}

	protected function qryOrder (&$q, $selectPart)
	{
		//array_push ($q, ' ORDER BY [docStateMain], [dateCreate] DESC, [ndx] DESC ');
		//array_push ($q, ' ORDER BY [dateCreate] DESC, [title]');
		array_push($q, ' ORDER BY workOrders.[docStateMain], workOrders.[dateIssue] DESC, workOrders.[ndx] DESC');
	}

	protected function qryOrderAll (&$q)
	{

	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = \E10\Base\linkedPersons ($this->table->app(), $this->table, $this->pks, 'label label-default');
		$this->atts = \E10\Base\loadAttachments ($this->app(), $this->pks, 'e10mnf.core.workOrders');

		//$this->properties = \E10\Base\getPropertiesTable ($this->app(), 'e10pro.wkf.documents', $this->pks);
		$this->classification = \E10\Base\loadClassification ($this->app(), $this->table->tableId(), $this->pks);
	}

	function messageBodyContent ($d)
	{
		$this->textRenderer->render ($d ['text']);
		return ['class' => 'pageText', 'code' => $this->textRenderer->code];
	}

	function renderPane (&$item)
	{
		$this->checkViewerGroup($item);

		$ndx = $item ['ndx'];

		$item['pk'] = $ndx;
		$item ['pane'] = ['title' => [], 'body' => [], 'class' => $this->paneClass];
		if (!$this->withBody)
			$item ['pane']['class'] .= ' e10-ds '.$item ['docStateClass'];

		$myIssue = FALSE;
		$msgTitleClass= '';
		if (isset ($this->linkedPersons [$ndx]['e10mnf-workRecs-admins'][0]['pndx']))
		{
			if (in_array($this->thisUserId, $this->linkedPersons [$ndx]['e10mnf-workRecs-admins'][0]['pndx']))
				$myIssue = TRUE;
			$msgTitleClass = ' e10-me';
		}

		$title = [];

		$title[] = ['class' => 'id pull-right'.$msgTitleClass, 'text' => $item['docNumber'], 'icon' => 'icon-hashtag'];
		$title[] = ['class' => 'h2', 'text' => $item['title'], 'icon' => $this->table->tableIcon($item, 1)];
		if ($item['customerFullName'])
			$title[] = ['text' => $item['customerFullName'], 'icon' => 'system/iconUser', 'class' => 'e10-off block'];

		if ($item['dateIssue'])
			$title[] = ['icon' => 'icon-play-circle', 'text' => utils::datef ($item ['dateIssue'], '%d'), 'class' => 'label label-default'];

		if ($item['dateDeadlineConfirmed'])
			$title[] = ['icon' => 'icon-calendar-check-o', 'text' => utils::datef ($item ['dateDeadlineConfirmed'], '%d'), 'class' => 'label label-info'];
		elseif ($item['dateDeadlineRequested'])
			$title[] = ['icon' => 'system/iconCalendar', 'text' => utils::datef ($item ['dateDeadlineRequested'], '%d'), 'class' => 'label label-default'];

		if ($item['dateClosed'])
			$title[] = ['icon' => 'system/actionStop', 'text' => utils::datef ($item ['dateClosed'], '%d'), 'class' => 'label label-default'];


/*
		if (!utils::dateIsBlank($item['date']))
			$title[] = ['text' => utils::datef ($item['date'], '%D'), 'icon' => 'system/iconCalendar', 'class' => 'e10-off'];
		else
			$title[] = ['text' => utils::datef ($item['dateCreate'], '%D, %T'), 'icon' => 'icon-keyboard-o', 'class' => 'e10-off'];
*/

		// -- linked persons
		//if (!$this->simpleHeaders)
		{
			if (isset ($this->linkedPersons [$ndx]))
			{

				forEach ($this->linkedPersons [$ndx] as $lp)
					$title = array_merge($title, $lp);
			}
		}

		$title[] = ['text' => '', 'class' => 'block'];

		// -- labels
		if (isset ($this->classification [$ndx]))
		{
			forEach ($this->classification [$ndx] as $clsfGroup)
				$title = array_merge($title, $clsfGroup);
		}

		if (isset($this->cntLinkedMsgs[$ndx]))
		{
			foreach ($this->cntLinkedMsgs[$ndx] as $ltid => $ltcnts)
			{
				$title[] = [
					'icon' => $this->msgKinds[$ltid]['icon'],
					'class' => 'pull-right e10-off', 'text' => utils::nf($ltcnts['all'])
				];
			}
		}

		if (isset($this->atts[$ndx]))
			$title[] = ['text' => utils::nf($this->atts[$ndx]['count']), 'icon' => 'icon-paperclip', 'class' => 'e10-off pull-right'];

		$titleClass = '';
		if (isset($this->notifications[$ndx]) || isset($this->withNewComment[$ndx]))
			$titleClass .= ' e10-block-notification';

		if ($this->withBody)
			$titleClass .= ' e10-ds '.$item ['docStateClass'];

		$item ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title, 'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => $this->tableId()
		];


		// -- body
		if ($this->withBody)
		{
			$this->textRenderer->setOwner($item);
			$item ['pane']['body'][] = $this->messageBodyContent ($item);

			// -- attachments
			if (isset($this->atts[$item ['ndx']]))
			{
				$item ['pane']['body'][] = ['class' => 'attBoxSmall', 'attachments' => $this->atts[$item ['ndx']], 'fullSizeTreshold' => 2];
			}
		}
		else
		{
			if (isset($this->atts[$item ['ndx']]))
			{
				$links = $this->attLinks($item ['ndx']);
				if (count($links))
					$item ['pane']['body'][] = ['value' => $links, 'class' => 'padd5'];
			}
		}
	}

	function checkViewerGroup (&$item)
	{
	}

	protected function loadNotifications ()
	{
		$q = 'SELECT * FROM e10_base_notifications WHERE state = 0 AND personDest = %i AND tableId = %s';
		$rows = $this->db()->query ($q, $this->thisUserId, 'e10pro.wkf.documents');
		foreach ($rows as $r)
		{
			$this->notifications[$r['recId']][] = $r->toArray();

			if ($r['recIdMain'])
			{
				$this->withNewComment[$r['recIdMain']] = 1;
				$this->notifyPks[] = $r['recIdMain'];
			}
			else
				$this->notifyPks[] = $r['recId'];
		}
	}

	function panelActiveMainId ($panelId)
	{
		$id = '';

		if ($panelId === 'right')
		{
		}

		return $id;
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');

		$qry = [];
		$params = new \E10\Params ($panel->table->app());

		// -- add buttons
		/*
		$addButtons = [];
		$btnParams = $this->addButtonsParams();
		$this->tableMessages->addWorkflowButtons($addButtons, $btnParams);
		//$this->tableWorksRecs->addWorksRecsButtons($addButtons, $btnParams);

		$addButton = [
			'action' => 'new', 'data-table' => 'e10pro.wkf.documents', 'icon' => 'system/actionAdd',
			'text' => 'Nový', 'type' => 'button', 'actionClass' => 'btn btn-sm',
			'class' => 'e10-param-addButton', 'btnClass' => 'btn-success',
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
			'data-addparams' => '__type=common',
		];
		$addButtons[] = $addButton;

		if (count($addButtons))
			$qry[] = ['style' => 'content', 'type' => 'line', 'line' => $addButtons, 'pane' => 'e10-pane-params'];
		*/

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = array ('style' => 'params', 'title' => $cg['name'], 'params' => $params);
		}
		$params->detectValues();

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	protected function addButtonsParams()
	{
		/*
		$btnParams = ['projectsGroup' => $this->projectsGroup, 'uiPlace' => $this->uiPlace, 'thisViewerId' => $this->vid];
		if ($this->activeProjectNdx)
			$btnParams['project'] = $this->activeProjectNdx;
		if ($this->activeProjectFolderNdx)
			$btnParams['projectFolder'] = $this->activeProjectFolderNdx;

		return $btnParams;
		*/
		return [];
	}

	function attLinks ($ndx)
	{
		$links = [];
		$attachments = $this->atts[$ndx];
		if (isset($attachments['images']))
		{
			foreach ($attachments['images'] as $a)
			{
				$icon = ($a['filetype'] === 'pdf') ? 'icon-file-pdf-o' : 'icon-picture-o';
				$l = ['text' => $a['name'], 'icon' => $icon, 'class' => 'e10-att-link btn btn-xs btn-default df2-action-trigger', 'prefix' => ''];
				$l['data'] =
					[
						'action' => 'open-link',
						'url-download' => $this->app()->dsRoot.'/att/'.$a['path'].$a['filename'],
						'url-preview' => $this->app()->dsRoot.'/imgs/-w1200/att/'.$a['path'].$a['filename']
					];
				$links[] = $l;
			}
		}

		return $links;
	}

	public function createTopMenuSearchCode () {return '';}

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
		$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' placeholder='".utils::es($placeholder)."' value='$fts' data-onenter='1' style='width: calc(100% - 1em); padding: 6px 2em;'/>";
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

	function createStaticContent()
	{
		$c = $this->createCoreSearchCode();
		$this->objectData ['staticContent'] = $c;
	}
}
