<?php

namespace lib\wkf;


use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages;


/**
 * Class ViewerDocumentsCore
 * @package lib\wkf
 */
class ViewerDocumentsCore extends TableView
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

	var $hasProjectsFilter = FALSE;

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

	var $projectsGroup;
	var $usersProjects;
	var $usersProjectsGroups;
	var $projectsParam;
	var $activeProjectNdx = FALSE;

	var $projectFoldersParam;
	var $activeProjectFolderNdx = FALSE;

	var $viewerStyle = 0;
	var $withBody = TRUE;
	var $paneClass= 'e10-pane';
	var $simpleHeaders = FALSE;
	var $showProjectsParts = TRUE;
	var $showProjectsFolders = TRUE;
	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1;

	var $sourcesIcons = [0 => 'icon-keyboard-o', 1 => 'icon-envelope-o', 2 => 'icon-plug', 3 => 'icon-android', 4 => 'system/iconWarning'];
	var $msgKinds;

	/** @var  \e10pro\wkf\TableProjects */
	var $tableProjects;
	/** @var  \e10pro\wkf\TableMessages */
	var $tableMessages;
	/** @var  \e10pro\wkf\TableDocuments */
	var $tableDocuments;
	/** @var  \e10pro\wkf\TableWorksRecs */
	var $tableWorksRecs;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;

		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'active', 'title' => 'K řešení', 'icon' => 'system/filterActive'];
			$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'system/filterArchive'];
			$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
			$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash'];
			$this->setMainQueries($mq);
		}

		$this->tableProjects = $this->app->table ('e10pro.wkf.projects');
		$this->tableMessages = $this->app->table ('e10pro.wkf.messages');
		$this->tableDocuments = $this->app->table ('e10pro.wkf.documents');
		$this->tableWorksRecs = $this->app()->table ('e10mnf.core.workRecs');

		$this->projectsGroup = $this->queryParam('projectGroup');
		$this->msgKinds = $this->app->cfgItem ('e10pro.wkf.msgKinds');

		$up = $this->tableProjects->usersProjects($this->projectsGroup, TRUE);
		$this->usersProjects = $up['projects'];
		$this->usersProjectsGroups = $up['groups'];

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

		$this->initProjectsList ();

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

	function initProjectsList ()
	{
		if (!$this->hasProjectsFilter)
			return;

		$projects = ['0' => 'Všechny'] + $this->usersProjects;
		$this->projectsParam = new \E10\Params ($this->app);
		$this->projectsParam->addParam('switch', 'query-projects-project', ['title' => 'Projekty', 'switch' => $projects, 'list' => 1]);
		$this->projectsParam->detectValues();

		$this->activeProjectNdx = intval($this->projectsParam->detectValues()['query-projects-project']['value']);
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

		$q [] = 'SELECT docs.*, persons.fullName as authorFullName, projects.fullName as projectFullName';

		array_push ($q, ' FROM [e10pro_wkf_documents] as docs');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON docs.author = persons.ndx');
		array_push ($q, ' LEFT JOIN e10pro_wkf_projects as projects ON docs.project = projects.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, "[title] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR [text] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");

			array_push ($q, " EXISTS (
												SELECT persons.fullName FROM [e10_base_doclinks] as docLinks, e10_persons_persons AS p
												where docs.ndx = srcRecId AND srcTableId = %s AND dstTableId = %s AND docLinks.dstRecId = p.ndx
												AND p.fullName LIKE %s
					)", 'e10pro.wkf.documents', 'e10.persons.persons', '%'.$fts.'%');
/*
			array_push ($q, " OR ");

			array_push ($q, " EXISTS (
												SELECT comments.ndx FROM [e10pro_wkf_messages] as comments
												WHERE messages.ndx = comments.ownerMsg AND comments.[text] LIKE %s
					)", '%'.$fts.'%');
*/
			array_push ($q, ")");
		}

		// -- msgTypes
		$this->qryMessageTypes($q, $selectPart);
		$this->qryClsf ($q);
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

		if ($this->hasProjectsFilter)
		{
			$projectNdx = $this->activeProjectNdx;//intval($this->projectsParam->detectValues()['query-projects-project']['value']);

			if ($projectNdx)
				array_push ($q, ' AND messages.project = %i', $projectNdx);
			else
			{
				if (count($this->usersProjects))
					array_push($q, ' AND messages.project IN %in', array_keys($this->usersProjects));
				else
					array_push ($q, ' AND messages.project = %i', -1);
			}
		}
		else
		{
			if (count($this->usersProjects))
				array_push($q, ' AND (docs.project IN %in', array_keys($this->usersProjects), ' OR docs.project = 0)');
			else
				array_push ($q, ' AND docs.project = %i', 0);
		}
/*
		if ($this->activeProjectFolderNdx !== FALSE && $this->activeProjectFolderNdx != -1)
		{
			array_push ($q, ' AND messages.projectFolder = %i', $this->activeProjectFolderNdx);
		}
*/
	}

	protected function qryOrder (&$q, $selectPart)
	{
		//array_push ($q, ' ORDER BY [docStateMain], [dateCreate] DESC, [ndx] DESC ');
		array_push ($q, ' ORDER BY [dateCreate] DESC, [title]');
	}

	protected function qryOrderAll (&$q)
	{

	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = \E10\Base\linkedPersons ($this->table->app(), $this->table, $this->pks, 'label label-default');
		$this->atts = \E10\Base\loadAttachments ($this->app(), $this->pks, 'e10pro.wkf.documents');

		$this->properties = \E10\Base\getPropertiesTable ($this->app(), 'e10pro.wkf.documents', $this->pks);
		$this->classification = \E10\Base\loadClassification ($this->app(), $this->table->tableId(), $this->pks);
/*
		$q = 'SELECT [msgKind], [ownerMsg], COUNT(*) AS cnt FROM e10pro_wkf_messages WHERE ownerMsg IN %in GROUP BY [msgKind], [ownerMsg]';
		$rows = $this->db()->query ($q, $this->pks);
		foreach ($rows as $r)
			$this->cntLinkedMsgs[$r['ownerMsg']][$r['msgKind']] = ['all' => $r['cnt']];

		$this->loadComments();
*/
	}

	function messageBodyContent ($d)
	{
/*
		if ($d['msgType'] === TableMessages::mtInbox)
			return ['type' => 'text', 'subtype' => 'auto', 'text' => $d['text'], 'class' => 'pageText',
				'iframeUrl' => $this->app()->urlRoot.'/api/call/e10pro.wkf.messagePreview/'.$d['ndx']];

		if ($d['source'] == TableMessages::msTest)
		{
			// -- content
			$contentData = json_decode($d['text'], TRUE);
			return ['type' => 'content', 'content' => $contentData, 'class' => 'pageText'];
		}
*/
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
		if (isset ($this->linkedPersons [$ndx]['e10pro-wkf-document-participant'][0]['pndx']))
		{
			if (in_array($this->thisUserId, $this->linkedPersons [$ndx]['e10pro-wkf-document-participant'][0]['pndx']))
				$myIssue = TRUE;
			$msgTitleClass = ' e10-me';
		}

		$title = [];

		$title[] = ['class' => 'id pull-right'.$msgTitleClass, 'text' => utils::nf($item['ndx']), 'icon' => 'icon-hashtag'];
		$title[] = ['class' => 'h2', 'text' => $item['title'], 'icon' => $this->table->tableIcon($item, 1)];
		if (!utils::dateIsBlank($item['date']))
			$title[] = ['text' => utils::datef ($item['date'], '%D'), 'icon' => 'system/iconCalendar', 'class' => 'e10-off'];
		else
			$title[] = ['text' => utils::datef ($item['dateCreate'], '%D, %T'), 'icon' => 'icon-keyboard-o', 'class' => 'e10-off'];

		if (!$this->simpleHeaders)
		{
			if ($item ['authorFullName'])
				$title[] = ['icon' => 'system/iconUser', 'text' => $item ['authorFullName'], 'class' => 'e10-off'];
			elseif ($item['source'] !== 0 && isset ($this->properties[$item['pk']]['emailheaders']['eml-from']))
			{
				$srcEmail = utils::searchArray($this->properties[$item['pk']]['emailheaders']['eml-from'], 'property', 'eml-from');
				if ($srcEmail !== NULL)
				{
					$ei = ['icon' => 'system/iconEmail', 'text' => $srcEmail ['value'], 'class' => 'e10-off'];
					if (isset($srcEmail['note']))
						$ei['suffix'] = $srcEmail['note'];
					$title[] = $ei;
				}
			}
		}

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
		if (!$this->activeProjectNdx && $item['projectFullName'])
			$title[] = ['icon' => 'icon-lightbulb-o', 'class' => 'label label-default', 'text' => $item['projectFullName']];

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
			'value' => $title, 'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'e10pro.wkf.documents'
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

			// -- comments
			if (isset($this->comments[$ndx]))
			{
				$commentsTitle = [['value' => [['text' => 'Komentáře', 'icon' => 'icon-comments-o', 'class' => 'h2']]]];
				$list = ['rows' => [], 'title' => $commentsTitle];
				foreach ($this->comments[$ndx] as $comment)
				{
					$commentNdx = $comment['ndx'];

					$row = ['info' => []];
					$tt = [];
					$tt[] = ['text' => $comment['authorFullName'], 'icon' => 'system/iconUser', 'class' => 'e10-off'];
					$tt[] = ['text' => utils::datef($comment['dateCreate'], '%D, %T'), 'icon' => 'icon-keyboard-o', 'class' => 'e10-off'];

					if (isset($this->notifications[$commentNdx]))
						$tt[] = ['text' => 'Nový', 'icon' => 'icon-asterisk', 'class' => 'e10-tag-small e10-row-plus'];

					if ($comment['author'] === $this->thisUserId)
					{
						$tt [] = [
							'class' => 'e10-small', 'icon' => 'system/docStateEdit',
							'text' => '', 'title' => 'Opravit', 'type' => 'span',
							'pk' => $comment['ndx'], 'docAction' => 'edit', 'data-table' => 'e10pro.wkf.documents',
							'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
						];
					}

					$tt [] = ['class' => 'id pull-right', 'text' => utils::nf($commentNdx), 'icon' => 'icon-hashtag'];

					if ($comment['activateCnt'] > 1)
						$tt [] = ['class' => 'id pull-right clear', 'text' => utils::datef ($comment['dateTouch'], '%D, %T'), 'icon' => 'icon-pencil-square'];

					$row['title'] = $tt;
					$this->textRenderer->render ($comment ['text']);
					$row['info'][] = ['code' => $this->textRenderer->code, 'infoClass' => 'pageText e10-comment'];

					if (isset($this->attachmentsComments[$comment['ndx']]))
						$row['attachments'] = ['attachments' => $this->attachmentsComments[$commentNdx]];

					$list['rows'][] = $row;
				}
				$item ['pane']['body'][] = ['list' => $list];
			}

			// -- bottom commands

			$cmds = [];

			if (!$item['disableComments'])
			{
				$cmds [] = [
					'action' => 'new', 'data-table' => 'e10pro.wkf.documents', 'icon' => 'system/actionAdd',
					'text' => 'Nový komentář', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-success', 'class' => 'pull-right',
					'data-addParams' => '__msgType='.TableMessages::mtComment.'&__ownerMsg=' . $ndx,
					'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
				];
			}

			if (count($cmds))
				$item ['pane']['footer'][] = ['value' => $cmds];
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

	protected function loadComments ()
	{
		$q [] = 'SELECT messages.*, ';
		array_push ($q, ' persons.fullName as authorFullName');
		array_push ($q, ' FROM [e10pro_wkf_messages] as messages');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON messages.author = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [msgType] = %i', TableMessages::mtComment);
		array_push ($q, ' AND [ownerMsg] IN %in', $this->pks);

		$commentsPks = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->comments[$r['ownerMsg']][] = $r->toArray();
			$commentsPks[] = $r['ndx'];
		}
		$this->attachmentsComments = \E10\Base\loadAttachments ($this->app, $commentsPks, 'e10pro.wkf.documents');
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

	function addProjectsToPanel ($panel, &$qry)
	{
		$projects = ['0' => 'Vše'] + $this->usersProjects;
		if (count($projects) > 1)
			$qry[] = ['style' => 'params', 'xtitle' => 'Projekty', 'params' => $this->projectsParam];
	}

	function addProjectFoldersToPanel ($panel, &$qry)
	{
		if (isset($this->projectFoldersParam))
			$qry[] = ['style' => 'params', 'params' => $this->projectFoldersParam];
	}

	function panelActiveMainId ($panelId)
	{
		$id = '';

		if ($panelId === 'right')
		{
			if ($this->activeProjectNdx)
				$id .= $this->activeProjectNdx . '-';
			if ($this->activeProjectFolderNdx)
				$id .= $this->activeProjectFolderNdx . '-';
		}

		return $id;
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');

		$qry = [];
		$params = new \E10\Params ($panel->table->app());

		// -- add buttons
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

		// -- project folders
		$this->addProjectFoldersToPanel ($panel, $qry);

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
		$btnParams = ['projectsGroup' => $this->projectsGroup, 'uiPlace' => $this->uiPlace, 'thisViewerId' => $this->vid];
		if ($this->activeProjectNdx)
			$btnParams['project'] = $this->activeProjectNdx;
		if ($this->activeProjectFolderNdx)
			$btnParams['projectFolder'] = $this->activeProjectFolderNdx;

		return $btnParams;
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
