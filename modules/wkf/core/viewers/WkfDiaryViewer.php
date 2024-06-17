<?php

namespace wkf\core\viewers;

use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class WkfDiaryViewer
 * @package wkf\core\viewers
 */
class WkfDiaryViewer extends TableView
{
	var $srcTableNdx = 0;
	var $srcRecNdx = 0;
	var $srcTableId = 0;
	var $diaryInfo = NULL;

	protected $textRenderer;
	protected $linkedPersons;
	protected $connectedIssuesTo;
	protected $connectedIssuesFrom;
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

	protected $today;
	var $thisUserId = 0;
	var $uiPlace = '';

	var $usersSections;

	var $viewerStyle = 0;
	var $withBody = TRUE;
	var $paneClass= 'e10-pane';
	var $simpleHeaders = FALSE;
	var $showProjectsParts = TRUE;
	var $showProjectsFolders = TRUE;
	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1, dvsPanesMicro = 7;

	var $sourcesIcons = [
		0 => 'icon-keyboard-o', 1 => 'system/iconEmail', 2 => 'icon-plug',
		3 => 'icon-android', 4 => 'system/iconWarning', 5 => 'icon-globe'
	];

	var $issuesStatuses;

	/** @var  \wkf\base\TableSections */
	var $tableSections;
	/** @var  \wkf\core\TableIssues */
	var $tableIssues;

	/** @var \Shipard\Table\DbTable */
	var $srcTable = NULL;

	var $issuesMarks;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		//$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

		$this->initMainQueries();

		$this->tableIssues = $this->app->table ('wkf.core.issues');
		$this->tableSections = $this->app->table ('wkf.base.sections');
		$this->usersSections = $this->tableSections->usersSections();

		$this->srcTableNdx = intval($this->queryParam ('srcTableNdx'));
		$this->addAddParam ('tableNdx', $this->srcTableNdx);

		$this->srcRecNdx = intval($this->queryParam ('srcRecNdx'));
		$this->addAddParam ('recNdx', $this->srcRecNdx);

		$srcRecData = $this->queryParam ('srcDocRecData');
		if (is_array($srcRecData))
		{
			$srcTable = $this->app()->tableByNdx($this->srcTableNdx);
			if ($srcTable)
			{
				$this->diaryInfo = $srcTable->getDiaryInfo($srcRecData);
				$this->srcTable = $srcTable;
			}
		}
		//error_log("--SRC-REC-DATA--".json_encode($srcRecData));


		$this->issuesStatuses = $this->app->cfgItem ('wkf.issues.statuses.all');

		$this->viewerStyle = /*self::dvsPanesMini*/self::dvsRows;
		if ($this->app()->testPostParam('viewer-mode') !== '')
		{
			//$this->viewerStyle = intval($this->app()->testPostParam('viewer-mode'));
		}
		else
		{
			//$vs = $this->queryParam('viewerMode');
			//$this->viewerStyle = ($vs === FALSE) ? self::dvsPanesMini : intval($this->queryParam('viewerMode'));
		}

		$this->htmlRowsElementClass = 'e10-dsb-panes';
		$this->htmlRowElementClass = 'post';

		$this->initPaneMode();
		$this->initPanesOptions();

		$this->thisUserId = intval($this->table->app()->user()->data ('id'));

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());

		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->today = utils::today();

		$this->loadNotifications();

		parent::init();
	}

	function initMainQueries()
	{
		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'active', 'title' => 'Aktivní', 'icon' => 'system/filterActive'];
			$mq [] = ['id' => 'done', 'title' => 'Hotovo', 'icon' => 'system/filterDone'];
			$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'system/filterArchive'];
			$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
			if ($this->app()->hasRole('pwuser'))
				$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash'];
			$this->setMainQueries($mq);
		}
	}

	function initPaneMode()
	{
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
			case self::dvsPanesMicro :
				$this->setPaneMode(2, 17);
				break;
			case self::dvsRows:
				$this->setPaneMode(1);
				break;
		}
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
			case self::dvsPanesMicro :
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

		if (0 && !$this->selectParts)
		{
			$this->qrySelectRows($q, NULL, 0);
		}
		else
		{
			$index = 0;
			$sp = [0, 1];
			foreach (/*$this->selectParts*/$sp as $selectPart)
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
			$mqId = $this->mainQueries[0]['id'];

		$q [] = 'SELECT [issues].*, ';

		//if ($selectPart)
		//	array_push ($q, ' %i', $selectPartNumber, ' AS selectPartOrder, %s', $selectPart, ' AS selectPart,');

		array_push ($q, ' persons.fullName AS authorFullName, ');
		array_push ($q, ' targets.shortName AS targetName,');
		array_push ($q, ' statuses.[order] AS statusOrder');
		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON issues.author = persons.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_targets AS [targets] ON issues.target = targets.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_issuesStatuses AS [statuses] ON issues.status = statuses.ndx');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND (');

		if ($selectPart === 0)
		{
			array_push ($q, ' ([issues].[tableNdx] = %i', $this->srcTableNdx);
			array_push ($q, ' AND [issues].[recNdx] = %i)', $this->srcRecNdx);
		}

		else
		if ($selectPart === 1)
		{
			if ($this->srcTableNdx === 1000)
			{ // e10.persons.persons
				array_push ($q, ' EXISTS (',
							'SELECT docLinks.ndx FROM [e10_base_doclinks] AS docLinks',
							' WHERE issues.ndx = srcRecId AND srcTableId = %s', 'wkf.core.issues',
							' AND dstTableId = %s', 'e10.persons.persons', 'AND docLinks.dstRecId = %i', $this->srcRecNdx,
							')'
				);
			}
			elseif ($this->srcTable)
			{
				array_push ($q, ' EXISTS (',
							'SELECT docLinks.ndx FROM [e10_base_doclinks] AS docLinks',
							' WHERE issues.ndx = dstRecId AND dstTableId = %s', 'wkf.core.issues',
							' AND srcTableId = %s', $this->srcTable->tableId(), 'AND docLinks.srcRecId = %i', $this->srcRecNdx,
							')'
				);
			}
		}
		array_push ($q, ') ');

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, 'issues.[subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR issues.[text] LIKE %s', '%'.$fts.'%');

			array_push ($q, ' OR EXISTS (',
				'SELECT persons.fullName FROM [e10_base_doclinks] AS docLinks, e10_persons_persons AS p',
				' WHERE issues.ndx = srcRecId AND srcTableId = %s', 'wkf.core.issues',
				' AND dstTableId = %s', 'e10.persons.persons', ' AND docLinks.dstRecId = p.ndx',
				' AND p.fullName LIKE %s)', '%'.$fts.'%'
			);
			/*
			array_push ($q, ' OR ');

			array_push ($q, ' EXISTS (
												SELECT comments.ndx FROM [e10pro_wkf_messages] as comments
												WHERE messages.ndx = comments.ownerMsg AND comments.[text] LIKE %s
					)', '%'.$fts.'%');
			*/
			array_push ($q, ')');
		}

		$this->qryDocState($q, $mqId, $fts, $selectPart);
		//$this->qryOrder($q, $selectPart);
	}

	function qryDocState(&$q, $mqId, $fts, $selectPart)
	{
		if ($mqId === 'active')
			array_push($q, ' AND (issues.[docStateMain] <= %i)', 2);
 		elseif ($mqId === 'done')
			array_push($q, ' AND (issues.[docStateMain] = %i)', 2);
		elseif ($mqId === 'archive')
			array_push($q, ' AND (issues.[docStateMain] = %i)', 5);
		elseif ($mqId === 'trash')
			array_push($q, ' AND (issues.[docStateMain] = %i)', 4);

		if ($mqId === 'all')
		{
			array_push($q, ' AND (issues.[docStateMain] != %i', 0,
				' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
				')');
		}
	}

	protected function qryOrder (&$q, $selectPart)
	{
		//array_push ($q, ' ORDER BY [docStateMain], [dateCreate] DESC, [ndx] DESC ');
		array_push ($q, ' ORDER BY [displayOrder]');
	}

	protected function qryOrderAll (&$q)
	{
		array_push ($q, ' ORDER BY displayOrder');
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = \E10\Base\linkedPersons ($this->table->app(), $this->table, $this->pks, 'e10-small');
		$this->atts = \E10\Base\loadAttachments ($this->app(), $this->pks, $this->table->tableId());

		//$this->properties = \E10\Base\getPropertiesTable ($this->app(), 'e10pro.wkf.messages', $this->pks);
		$this->classification = \E10\Base\loadClassification ($this->app(), $this->table->tableId(), $this->pks);

		$this->loadComments();
		$this->loadConnectedIssuesTo();
		$this->loadConnectedIssuesFrom();

		$this->issuesMarks = new \lib\docs\Marks($this->app());
		$this->issuesMarks->setMark(101);
		$this->issuesMarks->loadMarks('wkf.core.issues', $this->pks);
	}

	function messageBodyContent ($d)
	{
		if ($d['issueType'] === TableIssues::mtInbox)
			return ['type' => 'text', 'subtype' => 'auto', 'text' => $d['text'], 'class' => 'pageText',
				'iframeUrl' => $this->app()->urlRoot.'/api/call/e10pro.wkf.messagePreview/'.$d['ndx']];

		if ($d['source'] == TableIssues::msTest)
		{
			// -- content
			$contentData = json_decode($d['text'], TRUE);
			return ['type' => 'content', 'content' => $contentData, 'class' => 'pageText'];
		}

		$this->textRenderer->render ($d ['text']);
		return ['class' => 'pageText', 'code' => $this->textRenderer->code];
	}

	function renderPane (&$item)
	{
		$this->checkViewerGroup($item);
		$section = isset($this->usersSections['all'][$item['section']]) ? $this->usersSections['all'][$item['section']] : NULL;

		$ndx = $item ['ndx'];

		$item['pk'] = $ndx;
		$item ['pane'] = ['title' => [], 'body' => [], 'class' => $this->paneClass];
		if (!$this->withBody)
			$item ['pane']['class'] .= ' e10-ds '.$item ['docStateClass'];

		$myIssue = FALSE;
		$msgTitleClass= '';
		if (isset ($this->linkedPersons [$ndx]['wkf-issues-assigned'][0]['pndx']))
		{
			if (in_array($this->thisUserId, $this->linkedPersons [$ndx]['wkf-issues-assigned'][0]['pndx']))
				$myIssue = TRUE;
			$msgTitleClass = ' e10-me';
		}

		$title = [];

		$title[] = ['class' => 'id pull-right'.$msgTitleClass, 'text' => '#'.$item['issueId'], 'Xicon' => 'system/iconHashtag'];

		if ($item['onTop'])
			$title[] = ['class' => 'id pull-right e10-success', 'text' => '', 'icon' => 'system/iconPinned'];
		if ($item['priority'] < 10)
			$title[] = ['class' => 'id pull-right e10-error', 'text' => '', 'icon' => 'system/issueImportant'];
		elseif ($item['priority'] > 10)
			$title[] = ['class' => 'id pull-right e10-off', 'text' => '', 'icon' => 'system/issueNotImportant'];

		$title[] = ['class' => 'h2', 'text' => $item['subject'], 'icon' => $this->table->tableIcon($item, 1)];
		$title[] = ['text' => utils::datef ($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => 'e10-off break'];

		//if (!$this->simpleHeaders)
		{
			if ($item['issueType'] !== TableIssues::mtInbox)
				if ($item ['authorFullName'])
					$title[] = ['icon' => 'system/iconUser', 'text' => $item ['authorFullName'], 'class' => 'e10-off'];

			if ($item['issueType'] === TableIssues::mtInbox && isset ($this->linkedPersons [$ndx]['wkf-issues-from']))
				$title[] = $this->linkedPersons [$ndx]['wkf-issues-from'];
			elseif ($item['issueType'] === TableIssues::mtOutbox && isset ($this->linkedPersons [$ndx]['wkf-issues-to']))
				$title[] = $this->linkedPersons [$ndx]['wkf-issues-to'];
		}

		$this->addDeadlineDate ($item, $title);

		// -- linked persons
		//if (!$this->simpleHeaders)
		{
			/*
			if (isset ($this->linkedPersons [$ndx]))
			{
				forEach ($this->linkedPersons [$ndx] as $lp)
					$title = array_merge($title, $lp);
			}
			*/
		}

		$title[] = ['text' => '', 'class' => 'block'];

		if ($item['status'])
		{
			if (!isset($item['vgId']) || (isset($item['vgId']) && $item['vgId'] !== 's'.$item['status']))
			{
				$is = $this->issuesStatuses[$item['status']];
				$isLabel = ['text' => $is['sn'], 'class' => 'label label-success'];
				$isLabel['icon'] = 'icon-tasks';
				$title[] = $isLabel;
			}
		}

		/**
		if (($item['section'] && !$this->topSectionNdx) || ($this->topSectionCfg['sst'] === 10 && $item['section'] != $this->topSectionNdx))
		{
			$tgLabel = ['text' => $section['sn'], 'class' => 'label label-default', 'icon' => $section['icon']];

			if ($section['parentSection'])
			{
				$topSection = $this->tableIssues->topSection ($item['section']);
				$tgLabel['suffix'] = $topSection['sn'];
			}
			$title[] = $tgLabel;
		}**/

		// -- labels
		if (isset ($this->classification [$ndx]))
		{
			forEach ($this->classification [$ndx] as $clsfGroup)
				$title = array_merge($title, $clsfGroup);
		}

		// -- mark
		$title[] = [
			'text' => '', 'docAction' => 'mark', 'mark' => 101, 'table' => 'wkf.core.issues', 'pk' => $ndx,
			'value' => isset($this->issuesMarks->marks[$ndx]) ? $this->issuesMarks->marks[$ndx] : 0,
			'actionClass' => 'pull-right', 'class' => '', 'mark-st' => 'i',
		];

		if (isset($this->comments[$ndx]) && count($this->comments[$ndx]))
		{
			$title[] = ['icon' => 'icon-comment-o', 'class' => 'pull-right e10-off', 'text' => utils::nf(count($this->comments[$ndx]))];
		}

		if (isset($this->atts[$ndx]))
			$title[] = ['text' => utils::nf($this->atts[$ndx]['count']), 'icon' => 'system/iconPaperclip', 'class' => 'e10-off pull-right'];

		if (isset($this->connectedIssuesTo[$ndx]) || isset($this->connectedIssuesFrom[$ndx]))
		{
			$cnt = 0;
			if (isset($this->connectedIssuesTo[$ndx]))
				$cnt += $this->connectedIssuesTo[$ndx]['cnt'];
			if (isset($this->connectedIssuesFrom[$ndx]))
				$cnt += $this->connectedIssuesFrom[$ndx]['cnt'];
			$title[] = ['text' => utils::nf($cnt), 'icon' => 'icon-link', 'class' => 'e10-off pull-right'];
		}

		$titleClass = '';
		if (isset($this->notifications[$ndx]))
			$titleClass .= ' e10-block-notification';

		if ($this->withBody)
			$titleClass .= ' e10-ds '.$item ['docStateClass'];

		$item ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title,
			'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'wkf.core.issues',
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
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
							'class' => 'e10-small', 'icon' => 'system/actionOpen',
							'text' => '', 'title' => 'Opravit', 'type' => 'span',
							'pk' => $comment['ndx'], 'docAction' => 'edit', 'data-table' => 'wkf.core.comments',
							'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
						];
					}

					$tt [] = ['class' => 'id pull-right', 'text' => utils::nf($commentNdx), 'icon' => 'system/iconHashtag'];

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
					'action' => 'new', 'data-table' => 'wkf.core.comments', 'icon' => 'system/actionAdd',
					'text' => 'Nový komentář', 'type' => 'button', 'actionClass' => 'btn btn-xs btn-success', 'class' => 'pull-right',
					'data-addParams' => '__issue=' . $ndx,
					'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid
				];
			}

			if (count($cmds))
				$item ['pane']['footer'][] = ['value' => $cmds];
		}
		else
		{
			if ($this->viewerStyle != self::dvsPanesMicro)
			{
				if ($item['onTop'] > 6)
				{
					$this->textRenderer->setOwner($item);
					$item ['pane']['body'][] = $this->messageBodyContent($item);
				}

				if (isset($this->atts[$item ['ndx']]))
				{
					$links = $this->attLinks($item ['ndx']);
					if (count($links))
						$item ['pane']['body'][] = ['value' => $links, 'class' => 'padd5'];
				}
			}
		}
	}

	function checkViewerGroup (&$item)
	{
	}

	protected function loadComments ()
	{
		$q [] = 'SELECT comments.*,';
		array_push ($q, ' persons.fullName as authorFullName');
		array_push ($q, ' FROM [wkf_core_comments] AS comments');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON comments.author = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [issue] IN %in', $this->pks);

		$commentsPks = [];
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->comments[$r['issue']][] = $r->toArray();
			$commentsPks[] = $r['ndx'];
		}
		$this->attachmentsComments = \E10\Base\loadAttachments ($this->app, $commentsPks, 'wkf.core.comments');
	}

	function loadConnectedIssuesTo()
	{
		$connectionsTypes = $this->app()->cfgItem ('wkf.issues.connections.types');

		$this->connectedIssuesTo = [];
		$qli[] = 'SELECT connections.*, ';
		array_push ($qli, ' connectedIssues.subject, connectedIssues.issueType, connectedIssues.issueKind, connectedIssues.issueId AS issueDocNumber');
		array_push ($qli, ' FROM [wkf_core_issuesConnections] AS connections');
		array_push ($qli, ' LEFT JOIN [wkf_core_issues] AS connectedIssues ON connections.connectedIssue = connectedIssues.ndx');
		array_push ($qli, ' WHERE connections.[issue] IN %in', $this->pks);
		array_push ($qli, ' ORDER BY rowOrder');

		$rows = $this->db()->query($qli);
		foreach ($rows as $r)
		{
			$ct = $r['connectionType'];
			$srcNdx = $r['issue'];

			if (!isset($this->connectedIssuesTo[$srcNdx]))
				$this->connectedIssuesTo[$srcNdx] = ['cnt' => 0, 'issues' => []];

			if (!isset($this->connectedIssuesTo[$srcNdx]['issues'][$ct]))
			{
				$ctCfg = $connectionsTypes[$ct];
				$this->connectedIssuesTo[$srcNdx]['issues'][$ct] = ['name' => $ctCfg['name'], 'issues' => []];
			}

			$this->connectedIssuesTo[$srcNdx]['cnt']++;

			$issueLabel = [
				'text' => $r['subject'], 'prefix' => '#'.$r['issueDocNumber'],
				'icon' => $this->table->tableIcon($r), 'class' => 'block',
				'docAction' => 'edit', 'table' => $this->table->tableId(), 'pk' => $r['connectedIssue']
			];
			$this->connectedIssuesTo[$srcNdx]['issues'][$ct]['issues'][] = $issueLabel;
		}
	}

	function loadConnectedIssuesFrom()
	{
		$connectionsTypes = $this->app()->cfgItem ('wkf.issues.connections.types');

		$this->connectedIssuesFrom = [];
		$qli[] = 'SELECT connections.*, ';
		array_push ($qli, ' connectedIssues.subject, connectedIssues.issueType, connectedIssues.issueKind, connectedIssues.issueId AS issueDocNumber');
		array_push ($qli, ' FROM [wkf_core_issuesConnections] AS connections');
		array_push ($qli, ' LEFT JOIN [wkf_core_issues] AS connectedIssues ON connections.issue = connectedIssues.ndx');
		array_push ($qli, ' WHERE connections.[connectedIssue] IN %in', $this->pks);
		array_push ($qli, ' ORDER BY rowOrder');

		$rows = $this->db()->query($qli);
		foreach ($rows as $r)
		{
			$ct = $r['connectionType'];
			$srcNdx = $r['connectedIssue'];

			if (!isset($this->connectedIssuesFrom[$srcNdx]))
				$this->connectedIssuesFrom[$srcNdx] = ['cnt' => 0, 'issues' => []];

			if (!isset($this->connectedIssuesFrom[$srcNdx]['issues'][$ct]))
			{
				$ctCfg = $connectionsTypes[$ct];
				$this->connectedIssuesFrom[$srcNdx]['issues'][$ct] = ['name' => $ctCfg['name'], 'issues' => []];
			}

			$this->connectedIssuesFrom[$srcNdx]['cnt']++;

			$issueLabel = [
				'text' => $r['subject'], 'prefix' => '#'.$r['issueDocNumber'],
				'icon' => $this->table->tableIcon($r), 'class' => 'block',
				'docAction' => 'edit', 'table' => $this->table->tableId(), 'pk' => $r['connectedIssue']
			];
			$this->connectedIssuesFrom[$srcNdx]['issues'][$ct]['issues'][] = $issueLabel;
		}
	}

	protected function loadNotifications ()
	{
		$q = 'SELECT * FROM e10_base_notifications WHERE state = 0 AND personDest = %i AND tableId = %s';
		$rows = $this->db()->query ($q, $this->thisUserId, 'wkf.core.issues');
		foreach ($rows as $r)
		{
			$this->notifications[$r['recIdMain']][] = $r->toArray();
		}
	}

	protected function addButtonsParams()
	{
		$btnParams = ['uiPlace' => $this->uiPlace, 'thisViewerId' => $this->vid];

		if (isset($this->diaryInfo['sectionNdx']))
			$btnParams['section'] = $this->diaryInfo['sectionNdx'];

		$btnParams['tableNdx'] = $this->srcTableNdx;
		$btnParams['recNdx'] = $this->srcRecNdx;
		$btnParams['diary'] = 1;

		return $btnParams;
	}

	protected function addDeadlineDate ($item, &$props)
	{
		$deadline = NULL;
		if ($item['dateDeadline'])
			$deadline = $item['dateDeadline'];

		if (!$deadline)
			return;

		$dl = ['icon' => 'icon-clock-o', 'text' => utils::datef($deadline), 'class' => 'label label-default'];

		$ii = $deadline->diff($this->today);

		if ($item['docStateMain'] < 2)
		{
			if ($this->today >= $deadline)
			{
				if ($ii->days > 15) {
					$dl['class'] .= ' e10-warning3';
				} elseif ($ii->days > 10) {
					$dl['class'] .= ' e10-warning2';
				} elseif ($ii->days > 0) {
					$dl['class'] .= ' e10-warning1';
				}
			} else {
				if ($ii->days > 15) {
					$dl['class'] .= ' e10-state-done';
				} else {
					$dl['class'] .= ' e10-state-new';
				}
			}
		}
		$props[] = $dl;
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

	public function createTopMenuSearchCode ()
	{
		return $this->createCoreSearchCode('e10-sv-search-toolbar');
	}

	function createCoreSearchCode($toolbarClass = 'e10-sv-search-toolbar-default')
	{
		$fts = utils::es($this->fullTextSearch ());
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$placeholder = 'hledat';

		$c = '';

		$c .= "<div class='e10-sv-search e10-sv-search-toolbar $toolbarClass' style='padding-left: 1ex; padding-right: 1ex;' data-style='padding: .5ex 1ex 1ex 1ex; display: inline-block; width: 100%;' id='{$this->vid}Search'>";
		$c .=	"<table style='width: 100%'><tr>";

		$c .= $this->createCoreSearchCodeBegin();

		$c .= "<td class='fulltext' style='min-width:40%;'>";
		$c .=	"<span class='' style='width: 2em;text-align: center;position: absolute;padding-top: 2ex; opacity: .8;'><icon class='fa fa-search' style='width: 1.1em;'></i></span>";
		$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' placeholder='".utils::es($placeholder)."' value='$fts' data-xxx-onenter='1' style='width: calc(100% - 1em); padding: 6px 2em;'/>";
		$c .=	"<span class='df2-background-button df2-action-trigger df2-fulltext-clear' data-action='fulltextsearchclear' id='{$this->vid}Progress' data-run='0' style='margin-left: -2.5em; padding: 6px 2ex 3px 1ex; position:inherit; width: 2.5em; text-align: center;'><icon class='fa fa-times' style='width: 1.1em;'></i></span>";
		$c .= '</td>';

		$c .= "<td style='width: auto;'>";
		$c .= "<div class='viewerQuerySelect e10-dashboard-viewer'>";
		$c .= "<input name='mainQuery' type='hidden' value='$mqId'/>";
		$idx = 0;
		$active = '';
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

		$addButtonsEnabled = 1;

		if ($addButtonsEnabled)
		{
			$addButtons = [];
			$btnParams = $this->addButtonsParams();
			$this->tableIssues->addWorkflowButtons($addButtons, $btnParams);
			$addButtons[] = [
				'type' => 'action', 'action' => 'open-popup', 'text' => '',
				'icon' => 'system/iconHelp', 'style' => 'cancel',
				'data-popup-url' => 'https://shipard.org/'.'prirucka/51',
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
				'actionClass' => 'pull-right', 'class' => 'ml1',
				'title' => 'Nápověda'//DictSystem::text(DictSystem::diBtn_Help)
			];

			if (count($addButtons))
			{
				$c .= '<td style="width: auto; text-align: right;">';
				$c .= $this->app()->ui()->composeTextLine($addButtons);
				$c .= '</td>';
			}
		}

		$c .= '</tr></table>';
		$c .= '</div>';

		return $c;
	}

	function createCoreSearchCodeBegin()
	{
		return '';
	}

	function createStaticContent()
	{
		//$c = $this->createCoreSearchCode();
		//$this->objectData ['staticContent'] = $c;
		parent::createStaticContent();
	}

	public function createToolbar ()
	{
		return [];
	}

	public function createDetails ()
	{
		return [];
	}
}
