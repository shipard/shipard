<?php

namespace wkf\core\viewers;

use \e10\TableView, \e10\utils, \Shipard\Viewer\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class DashboardIssuesCore
 * @package wkf\core\viewers
 */
class DashboardIssuesCore extends TableView
{
	protected $msgTypes;
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
	protected $treeMode = 0;

	var $hasProjectsFilter = FALSE;

	var $selectParts = NULL;

	var $comments = [];
	var $attachmentsComments;
	var $targets = [];
	var $projects = [];
	var $notifications = [];

	protected $today;
	var $thisUserId = 0;
	var $uiPlace = '';

	protected $forceMainQuery = FALSE;

	var $sectionNdx;
	var $sectionCfg = NULL;
	var $fixedSectionNdx = 0;
	var $topSectionNdx;
	var $topSectionCfg;
	var $usersSections;
	var $subSections = NULL;
	var $subSectionsParam = NULL;
	var $subSectionNdx = 0;

	var $viewerStyle = 0;
	var $withBody = TRUE;
	var $paneClass= 'e10-pane';
	var $simpleHeaders = FALSE;
	var $showProjectsParts = TRUE;
	var $showProjectsFolders = TRUE;

	var $useWorkOrders = 0;

	var $help = '';

	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1, dvsPanesMicro = 7, dvsViewer = 5;

	var $sourcesIcons = [
		0 => 'system/iconKeyboard', 1 => 'system/iconEmail', 2 => 'icon-plug',
		3 => 'icon-android', 4 => 'system/iconWarning', 5 => 'icon-globe'
	];
	var $msgKinds;
	var $issuesStatuses;

	/** @var  \wkf\base\TableSections */
	var $tableSections;
	/** @var  \wkf\core\TableIssues */
	var $tableIssues;

	var $issuesMarks;
	var $docLinksDocs;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		//$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

		$this->useWorkOrders = intval($this->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0));

		$this->initMainQueries();

		$help = $this->queryParam('help');
		if ($help)
			$this->help = $help;

		$this->tableIssues = $this->app->table ('wkf.core.issues');
		$this->tableSections = $this->app->table ('wkf.base.sections');
		$this->usersSections = $this->tableSections->usersSections();

		$this->fixedSectionNdx = intval($this->queryParam('fixedSection'));

		if ($this->fixedSectionNdx)
		{
			$this->sectionNdx = $this->fixedSectionNdx;
			$fixedSectionCfg = (isset($this->usersSections['all'][$this->fixedSectionNdx])) ? $this->usersSections['all'][$this->fixedSectionNdx] : NULL;
			if ($fixedSectionCfg)
				$this->topSectionNdx = $fixedSectionCfg['parentSection'];
		}
		else
		{
			$this->topSectionNdx = ($this->fixedSectionNdx) ? $this->fixedSectionNdx : $this->queryParam('section');
			$this->sectionNdx = $this->topSectionNdx;
			$this->topSectionCfg = ($this->topSectionNdx) ? $this->usersSections['all'][$this->topSectionNdx] : NULL;
		}

		$this->msgKinds = $this->app->cfgItem ('e10pro.wkf.msgKinds');
		$this->issuesStatuses = $this->app->cfgItem ('wkf.issues.statuses.all');

		$this->viewerStyle = self::dvsPanesMini;
		if ($this->app()->testPostParam('viewer-mode') !== '')
		{
			$this->viewerStyle = intval($this->app()->testPostParam('viewer-mode'));
		}
		else
		{
			$vs = $this->queryParam('viewerMode');
			$this->viewerStyle = ($vs === FALSE) ? self::dvsPanesMini : intval($this->queryParam('viewerMode'));
		}

		$this->htmlRowsElementClass = 'e10-dsb-panes';
		$this->htmlRowElementClass = 'post';

		$this->initPaneMode();
		$this->initPanesOptions();
		if ($this->treeMode)
			$this->initSectionsTree();
		else
			$this->initSubSections();

		$this->sectionCfg = ($this->sectionNdx) ? $this->usersSections['all'][$this->sectionNdx] : NULL;

		if (!$this->thisUserId)
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
			$mq [] = ['id' => 'active', 'title' => 'K řešení', 'icon' => 'system/filterActive'];
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
			case self::dvsViewer :
				$this->type = 'form';
				$this->objectSubType = TableView::vsMain;
				//$this->enableDetailSearch = FALSE;
				$this->fullWidthToolbar = TRUE;
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

	function initSubSections ()
	{
		if ($this->fixedSectionNdx || !$this->topSectionCfg || !count($this->topSectionCfg['subSections']))
			return;

		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(100);
		$marks->loadMarks('wkf.base.sections', $this->topSectionCfg['subSections']);

		//$this->subSections = ['0' => 'Všechny'];
		foreach ($this->topSectionCfg['subSections'] as $ss)
		{
			if (!isset($this->usersSections['all'][$ss]))
				continue;
			$subSectionCfg = $this->usersSections['all'][$ss];

			$nv = isset($marks->marks[$ss]) ? $marks->marks[$ss] : 0;
			if (!isset($marks->markCfg['states'][$nv]))
				$nv = 0;
			$nt = $marks->markCfg['states'][$nv]['name'];


			$this->subSections[$ss] = [
				['text' => $subSectionCfg['sn'], 'class' => ''],
				['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$ss}' style='display:none;'></span>"],
				['text' => '', 'icon' => $marks->markCfg['states'][$nv]['icon'].' fa-fw', 'title' => $nt, 'class' => 'pull-right e10-small'],
			];
		}

		$this->subSectionsParam = new \E10\Params ($this->app);
		$this->subSectionsParam->addParam('switch', 'query-sections-subsection', ['title' => '', 'switch' => $this->subSections, 'list' => 1]);
		$this->subSectionsParam->detectValues();

		$this->subSectionNdx = intval($this->subSectionsParam->detectValues()['query-sections-subsection']['value']);
		if ($this->subSectionNdx)
			$this->sectionNdx = $this->subSectionNdx;

		$this->usePanelLeft = TRUE;
	}

	function initSectionsTree ()
	{
		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(100);
		$marks->loadMarks('wkf.base.sections', array_keys($this->usersSections['all']));

		foreach ($this->usersSections['top'] as $us => $sectionCfg)
		{
			$this->subSections[$us] = [
				['text' => $sectionCfg['sn'], 'class' => '', 'icon' => $sectionCfg['icon']],
				['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$us}' style='display:none;'></span>"],
			];

			if (!isset($sectionCfg['subSections']) || !count($sectionCfg['subSections']))
			{
				$nv = isset($marks->marks[$us]) ? $marks->marks[$us] : 0;
				if (!isset($marks->markCfg['states'][$nv]))
					$nv = 0;
				$nt = $marks->markCfg['states'][$nv]['name'];
				$this->subSections[$us][] =
					['text' => '', 'icon' => $marks->markCfg['states'][$nv]['icon'], 'title' => $nt, 'class' => 'pull-right e10-small', 'css' => 'position: absolute; right:0; padding-right: 4px;'];
			}

			if (isset($sectionCfg['subSections']))
			{
				foreach ($sectionCfg['ess'] as $ss)
				{
					if (!isset($this->usersSections['all'][$ss]))
						continue;
					$subSectionCfg = $this->usersSections['all'][$ss];

					$nv = isset($marks->marks[$ss]) ? $marks->marks[$ss] : 0;
					if (!isset($marks->markCfg['states'][$nv]))
						$nv = 0;
					$nt = $marks->markCfg['states'][$nv]['name'];

					$this->subSections[$us][0]['subItems'][$ss] = [
						['text' => $subSectionCfg['sn'], 'class' => '', 'icon' => $subSectionCfg['icon']],
						['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$ss}' style='display:none;'></span>"],
						['text' => '', 'icon' => $marks->markCfg['states'][$nv]['icon'], 'title' => $nt, 'class' => 'pull-right e10-small', 'css' => 'position: absolute; right:0; padding-right: 4px;'],
					];
				}
			}
		}

		$this->subSections[0] = ['text' => 'Vše', 'class' => ''];

		if (isset($_POST['query-sections-subsection']))
			$this->subSectionNdx = intval($_POST['query-sections-subsection']);
		else
			$this->subSectionNdx = intval(key($this->subSections));

		$this->subSectionsParam = new \E10\Params ($this->app);
		$this->subSectionsParam->addParam('switch', 'query-sections-subsection', ['title' => '', 'defaultValue' => strval($this->subSectionNdx), 'switch' => $this->subSections, 'list' => 1]);
		$this->subSectionsParam->detectValues();

		$this->sectionNdx = $this->subSectionNdx;
		$this->usePanelLeft = TRUE;
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
			$mqId = $this->mainQueries[0]['id'];

		$q [] = 'SELECT issues.*, ';

		if ($selectPart)
			array_push ($q, ' %i', $selectPartNumber, ' AS selectPartOrder, %s', $selectPart, ' AS selectPart,');

		array_push ($q, ' persons.fullName AS authorFullName, ');
		array_push ($q, ' targets.shortName AS targetName,');
		array_push ($q, ' statuses.[order] AS statusOrder');

		if ($this->useWorkOrders)
		{
			array_push($q, ', wo.[title] AS woTitle, wo.[docNumber] AS woDocNumber');
			array_push($q, ', woCusts.[fullName] AS woCustName');
		}

		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON issues.author = persons.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_targets AS [targets] ON issues.target = targets.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_issuesStatuses AS [statuses] ON issues.status = statuses.ndx');

		if ($this->useWorkOrders)
		{
			array_push($q, ' LEFT JOIN [e10mnf_core_workOrders] AS wo ON [issues].workOrder = wo.ndx');
			array_push($q, ' LEFT JOIN [e10_persons_persons] AS woCusts ON [wo].customer = woCusts.ndx');
		}

		array_push ($q, ' WHERE 1');

		$this->qrySection($q, $selectPart);

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
		$this->qryClassification ($q);
		$this->qryOrder($q, $selectPart);
	}

	function qryDocState(&$q, $mqId, $fts, $selectPart)
	{
		if ($mqId === 'dashboard')
		{
			if ($selectPart === 'concept')
			{
				array_push($q, ' AND (',
					' (issues.[docStateMain] = %i', 0, ' AND [issues].[author] IN %in', [0, $this->thisUserId], ')',
					' OR (issues.[docStateMain] = %i', 0, ' AND issues.docState = %i', 1001, ')',
					' OR issues.[docState] = 8000',
					')'
				);
			}
			elseif ($selectPart !== 'unread')
			{
				if ($fts === '')
					array_push($q, ' AND (issues.[docStateMain] = %i)', 1);
				else
					array_push($q, ' AND (issues.[docStateMain] IN %in', [1, 2, 5], ')');
			}
		}
		elseif ($mqId === 'active')
		{
			array_push($q, ' AND (');
			if ($fts === '')
				array_push($q, 'issues.[docStateMain] = %i', 1);
			else
				array_push($q, ' issues.[docStateMain] IN %in', [1, 2, 5]);
			array_push($q,' OR (issues.[docStateMain] = %i', 0, ' AND [issues].[author] IN %in', [0, $this->thisUserId], ')',
				' OR (issues.[docStateMain] = %i', 0, ' AND issues.docState = %i', 1001, ')',
				' OR issues.[docState] = 8000'
				);
			if (count($this->notifications))
				array_push($q, ' OR [issues].ndx IN %in', array_keys($this->notifications));
			array_push($q, ')');
		}
		elseif ($mqId === 'done')
			array_push($q, ' AND (issues.[docStateMain] = %i)', 2);
		elseif ($mqId === 'archive')
			array_push($q, ' AND (issues.[docStateMain] = %i)', 5);
		elseif ($mqId === 'trash')
			array_push($q, ' AND (issues.[docStateMain] = %i)', 4);

		if ($mqId === 'all')
		{
			array_push($q, ' AND (issues.[docStateMain] != %i', 0,
				' OR (issues.[docStateMain] = %i', 0, ' AND [issues].[author] IN %in', [0, $this->thisUserId], ')',
				')');
		}
	}

	public function qrySection(&$q, $selectPart)
	{
		//array_push ($q, ' AND issues.[section] = %i', $this->sectionNdx);
	}

	public function qryClassification (&$q)
	{
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			foreach ($qv['clsf'] as $grpId => $grpItems)
			{
				array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE issues.ndx = recid AND tableId = %s', 'wkf.core.issues');
				array_push($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
				array_push ($q, ')');
			}
		}

		if (isset($qv['userRelated']['author']))
		{
			array_push ($q, ' AND issues.author = %i', $this->thisUserId);
		}

		if (isset($qv['userRelated']['assigned']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE issues.ndx = srcRecId',
				' AND srcTableId = %s','wkf.core.issues', ' AND linkId = %s','wkf-issues-assigned',
					' AND dstTableId = %s', 'e10.persons.persons',
					' AND dstRecId = %i', $this->thisUserId, ')');
		}

		if (isset($qv['userRelated']['notify']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE issues.ndx = srcRecId',
				' AND srcTableId = %s','wkf.core.issues', ' AND linkId = %s','wkf-issues-notify',
				' AND dstTableId = %s', 'e10.persons.persons',
				' AND dstRecId = %i', $this->thisUserId, ')');
		}

		if (isset($qv['userRelated']['important']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM [wkf_base_docMarks] WHERE issues.ndx = rec',
				' AND [table] = %i', 1241, ' AND [mark] = %i', 101, ' AND [state] != %i', 0, ' AND [user] = %i', $this->thisUserId, ')');
		}
	}

	protected function qryOrder (&$q, $selectPart)
	{
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$orderBy = (isset($this->sectionCfg['orderBy'])) ? $this->sectionCfg['orderBy'] : 0;
		if ($orderBy === 0)
			$orderBy = 1;

		switch ($orderBy)
		{
			case 1: array_push ($q, ' ORDER BY [displayOrder]'); break;
			case 2: array_push ($q, ' ORDER BY [displayOrder] DESC'); break;
			case 3: array_push ($q, ' ORDER BY [dateIncoming] DESC'); break;
			case 4: if ($mqId === 'dashboard' || $mqId === 'active')
								array_push ($q, ' ORDER BY [dateIncoming]');
							else
								array_push ($q, ' ORDER BY [dateIncoming] DESC');
							break;
		}
	}

	protected function qryOrderAll (&$q)
	{
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$orderBy = (isset($this->sectionCfg['orderBy'])) ? $this->sectionCfg['orderBy'] : 0;
		if ($orderBy === 0)
			$orderBy = 1;

		switch ($orderBy)
		{
			case 1: array_push ($q, ' ORDER BY selectPartOrder, [displayOrder]'); break;
			case 2: array_push ($q, ' ORDER BY selectPartOrder, [displayOrder] DESC'); break;
			case 3: array_push ($q, ' ORDER BY selectPartOrder, [dateIncoming] DESC'); break;
			case 4: if ($mqId === 'dashboard' || $mqId === 'active')
								array_push ($q, ' ORDER BY selectPartOrder, [dateIncoming]');
							else
								array_push ($q, ' ORDER BY selectPartOrder, [dateIncoming] DESC');
							break;
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$lpClass = ($this->viewerStyle !== self::dvsViewer) ? 'e10-small' : '';

		$this->linkedPersons = \E10\Base\linkedPersons ($this->table->app(), $this->table, $this->pks, $lpClass);
		$this->atts = \E10\Base\loadAttachments ($this->app(), $this->pks, $this->table->tableId());

		//$this->properties = \E10\Base\getPropertiesTable ($this->app(), 'e10pro.wkf.messages', $this->pks);
		$this->classification = \E10\Base\loadClassification ($this->app(), $this->table->tableId(), $this->pks);

		$this->loadComments();
		$this->loadProjects();
		$this->loadTargets();
		$this->loadConnectedIssuesTo();
		$this->loadConnectedIssuesFrom();

		$this->issuesMarks = new \lib\docs\Marks($this->app());
		$this->issuesMarks->setMark(101);
		$this->issuesMarks->loadMarks('wkf.core.issues', $this->pks);



		$linkedRows = $this->db()->query (
			'SELECT * FROM [e10_base_doclinks] WHERE 1',
			//' AND linkId = %s', 'e10docs-inbox',
			' AND dstRecId IN %in', $this->pks,
			' AND dstTableId = %s', 'wkf.core.issues'
		);

		foreach($linkedRows as $r)
		{
			$docTable = $this->app()->table($r['srcTableId']);
			$docRecData = $docTable->loadItem ($r['srcRecId']);
			$docInfo = $docTable->getRecordInfo ($docRecData);

			$docItem = [
				'icon' => $docTable->tableIcon ($docRecData), 'text' => $docInfo['docID'],
				'docAction' => 'edit', 'table' => $docTable->tableId(), 'pk' => $docRecData['ndx'], 'title' => $docInfo['title'],
				'class' => '', 'actionClass' => 'label label-info', 'type' => 'span'];

			$this->docLinksDocs[$r['dstRecId']][] = $docItem;
		}
	}

	function messageBodyContent ($d)
	{
		if ($d['issueType'] === TableIssues::mtInbox)
			return ['type' => 'text', 'subtype' => 'auto', 'text' => $d['text'], 'class' => 'pageText',
				'iframeUrl' => $this->app()->urlRoot.'/api/call/wkf.core.issuePreview/'.$d['ndx']];

		if ($d['source'] == TableIssues::msTest)
		{
			// -- content
			$contentData = json_decode($d['text'], TRUE);
			return ['type' => 'content', 'content' => $contentData, 'class' => 'pageText'];
		}

		$this->textRenderer->render ($d ['text']);
		return ['class' => 'pageText', 'code' => $this->textRenderer->code];
	}

	public function renderRow ($item)
	{
		$listItem ['icon'] = $this->table->tableIcon($item, 1);
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['tt'] = $item['subject'];

		if (isset($this->notifications[$item ['ndx']]))
			$listItem['class'] = ' e10-block-notification';

		$listItem ['i1'] = [];

		if ($item['onTop'])
			$listItem ['i1'][] = ['class' => 'id e10-success', 'text' => '', 'icon' => 'system/iconPinned'];
		if ($item['priority'] < 10)
			$listItem ['i1'][] = ['class' => 'id e10-error', 'text' => '', 'icon' => 'system/issueImportant'];
		elseif ($item['priority'] > 10)
			$listItem ['i1'][] = ['class' => 'id e10-off', 'text' => '', 'icon' => 'system/issueNotImportant'];

		$listItem ['i1'][] = ['class' => 'id', 'text' => '#'.$item['issueId'], 'Xicon' => 'system/iconHashtag'];

		$dl = [];
		$this->addDeadlineDate ($item, $dl);
		if (count($dl))
			$listItem['i2'] = $dl;

		$listItem ['t2'] = [];
		$listItem ['t3'] = [];

		// -- title: date + author
		$title = [];
		$dateIncoming = (!utils::dateIsBlank($item['dateIncoming'])) ? utils::createDateTime($item['dateIncoming'])->format('Ymd') : '00000000';
		$dateCreate = (!utils::dateIsBlank($item['dateCreate'])) ? utils::createDateTime($item['dateCreate'])->format('Ymd') : '00000000';
		if ($dateIncoming === $dateCreate)
			$title[] = ['text' => utils::datef($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => 'break'];
		else
		{
			$title[] = ['text' => utils::datef ($item['dateIncoming'], '%d'), 'icon' => 'icon-calendar-check-o', 'class' => 'e10-off'];
			$title[] = ['text' => utils::datef($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => ''];
		}

		if ($item['source'] === 0)
			if ($item ['authorFullName'])
				$title[] = ['icon' => 'system/iconUser', 'text' => $item ['authorFullName'], 'class' => ''];


		$listItem ['t2'] = $title;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		$ndx = $item['pk'];
		if (!isset($item['t3']))
			$item['t3'] = [];


		if (isset ($this->linkedPersons [$ndx]['wkf-issues-from'][0]))
		{
			$item['t3'] = array_merge($item['t3'], $this->linkedPersons [$ndx]['wkf-issues-from']);
		}

		if (isset ($this->linkedPersons [$ndx]['wkf-issues-assigned'][0]['pndx']))
		{
			if (!isset($item['t2']))
				$item['t2'] = [];

			if ($item['issueType'] === TableIssues::mtInbox && isset ($this->linkedPersons [$ndx]['wkf-issues-from']))
				$item['t2'][] = $this->linkedPersons [$ndx]['wkf-issues-from'];
			elseif ($item['issueType'] === TableIssues::mtOutbox && isset ($this->linkedPersons [$ndx]['wkf-issues-to']))
				$item['t2'] = $this->linkedPersons [$ndx]['wkf-issues-to'];


			if (in_array($this->thisUserId, $this->linkedPersons [$ndx]['wkf-issues-assigned'][0]['pndx']))
				$myIssue = TRUE;
			$msgTitleClass = ' e10-me';
		}
		//$item['t2'] = json_encode($this->linkedPersons[$ndx]);


		// -- labels
		if (isset ($this->classification [$ndx]))
		{
			foreach ($this->classification [$ndx] as $clsfGroup)
			{
				$item['t3'] = array_merge($item['t3'], $clsfGroup);
			}
		}

		$dstId = 't2';
		if (isset($item['t3'][0]))
			$dstId= 't3';
		if ($item['tableNdx'])
		{
			$showDocumentLabel = 1;
			if ($item['tableNdx'] === 1120 && $item['recNdx'] === $item['workOrder'])
				$showDocumentLabel = 0;

			if ($showDocumentLabel)
			{
				$docTable = $this->app()->tableByNdx($item['tableNdx']);
				$docRecData = $docTable->loadItem ($item['recNdx']);
				$docInfo = $docTable->getRecordInfo ($docRecData);

				$docItem = [
					'icon' => $docTable->tableIcon ($docRecData), 'text' => $docInfo['docID'],
					'docAction' => 'edit', 'table' => $docTable->tableId(), 'pk' => $docRecData['ndx'], 'title' => $docInfo['title'],
					'class' => '', 'actionClass' => 'label label-info', 'type' => 'span'];

				$item[$dstId][] = $docItem;
			}
		}
		if (isset ($this->docLinksDocs [$ndx]))
			$item[$dstId] = array_merge($item[$dstId], $this->docLinksDocs [$ndx]);
	}

	function renderPane (&$item)
	{
		$this->checkViewerGroup($item);
		$section = isset($this->usersSections['all'][$item['section']]) ? $this->usersSections['all'][$item['section']] : NULL;

		$ndx = $item ['ndx'];

		$issueKindCfg = $this->app()->cfgItem ('wkf.issues.kinds.'.$item['issueKind'], NULL);

		$item['pk'] = $ndx;
		$item ['pane'] = ['title' => [], 'body' => [], 'class' => $this->paneClass.' e10-att-target'];
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

		if (($this->sectionCfg && $this->sectionCfg['isAdmin']) || $section && $section['isAdmin'])
		{
			if (!isset($this->atts[$item ['ndx']]) && $item['source'] == TableIssues::msEmail)
			{
				$sab = [
					'type' => 'action', 'action' => 'addwizard', 'data-table' => 'wkf.core.issues', 'data-pk' => strval($ndx),
					'text' => '', 'title' => 'Uložit text zprávy jako přílohu', 'data-class' => 'wkf.core.libs.SaveIssueBodyWizard', 'icon' => 'system/iconFilePdf',
					'element' => 'span', 'class' => 'pull-right e10-small', 'actionClass' => '', 'btnClass' => '',
				];
				$title[] = $sab;
			}

			$seb = [
				'type' => 'action', 'action' => 'addwizard', 'data-table' => 'wkf.core.issues', 'data-pk' => strval($ndx),
				'text' => '', 'title' => 'Rychlé úpravy', 'data-class' => 'wkf.core.forms.SmartEdit', 'icon' => 'system/actionSettings',
				'element' => 'span', 'class' => 'pull-right e10-small', 'actionClass' => '', 'btnClass' => '',
			];
			$title[] = $seb;
		}

		$title[] = ['class' => 'id pull-right'.$msgTitleClass, 'text' => '#'.$item['issueId'], 'Xicon' => 'system/iconHashtag'];

		if ($item['onTop'])
			$title[] = ['class' => 'id pull-right e10-success', 'text' => '', 'icon' => 'system/iconPinned'];
		if ($item['priority'] < 10)
			$title[] = ['class' => 'id pull-right e10-error', 'text' => '', 'icon' => 'system/issueImportant'];
		elseif ($item['priority'] > 10)
			$title[] = ['class' => 'id pull-right e10-off', 'text' => '', 'icon' => 'system/issueNotImportant'];

		$title[] = ['class' => 'h2', 'text' => $item['subject'], 'icon' => $this->table->tableIcon($item, 1)];

		// -- date
		$dateIncoming = (!utils::dateIsBlank($item['dateIncoming'])) ? utils::createDateTime($item['dateIncoming'])->format('Ymd') : '00000000';
		$dateCreate = (!utils::dateIsBlank($item['dateCreate'])) ? utils::createDateTime($item['dateCreate'])->format('Ymd') : '00000000';
		if ($dateIncoming === $dateCreate)
			$title[] = ['text' => utils::datef($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => 'e10-off break'];
		else
		{
			$title[] = ['text' => utils::datef ($item['dateIncoming'], '%d'), 'icon' => 'icon-calendar-check-o', 'class' => 'e10-off'];
			$title[] = ['text' => utils::datef($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => 'e10-off'];
		}
		//if (!$this->simpleHeaders)
		{
			if ($item['source'] === 0)
				if ($item ['authorFullName'])
					$title[] = ['icon' => 'system/iconUser', 'text' => $item ['authorFullName'], 'class' => 'e10-off'];

			if ($item['issueType'] === TableIssues::mtInbox && isset ($this->linkedPersons [$ndx]['wkf-issues-from']))
				$title[] = $this->linkedPersons [$ndx]['wkf-issues-from'];
			elseif ($item['issueType'] === TableIssues::mtOutbox && isset ($this->linkedPersons [$ndx]['wkf-issues-to']))
				$title[] = $this->linkedPersons [$ndx]['wkf-issues-to'];
			if (isset ($this->linkedPersons [$ndx]['wkf-issues-notify']))
				$title[] = $this->linkedPersons [$ndx]['wkf-issues-notify'];

			if ($item['workOrder'])
			{
				$woTitle = $item['woTitle'];
				if ($woTitle === '')
					$woTitle = $item['woCustName'];
				$title[] = ['text' => $woTitle, 'class' => 'label label-default', 'icon' => 'tables/e10mnf.core.workOrders'];
			}
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

		// -- projects
		if (isset($this->projects[$ndx]))
			$title = array_merge($title, $this->projects[$ndx]);

		// -- targets
		if (isset($this->targets[$ndx]))
			$title = array_merge($title, $this->targets[$ndx]);

		if (($item['section'] && !$this->topSectionNdx) || ($this->topSectionCfg['sst'] === 10 && $item['section'] != $this->topSectionNdx))
		{
			$tgLabel = ['text' => $section['sn'], 'class' => 'label label-default', 'icon' => $section['icon']];

			if ($section['parentSection'])
			{
				$topSection = $this->tableIssues->topSection ($item['section']);
				$tgLabel['suffix'] = $topSection['sn'];
			}
			$title[] = $tgLabel;
		}

		// -- labels
		if (isset ($this->classification [$ndx]))
		{
			forEach ($this->classification [$ndx] as $clsfGroup)
				$title = array_merge($title, $clsfGroup);
		}

		if ($item['tableNdx'])
		{
			$showDocumentLabel = 1;
			if ($item['tableNdx'] === 1120 && $item['recNdx'] === $item['workOrder'])
				$showDocumentLabel = 0;

			if ($showDocumentLabel)
			{
				$docTable = $this->app()->tableByNdx($item['tableNdx']);
				$docRecData = $docTable->loadItem ($item['recNdx']);
				$docInfo = $docTable->getRecordInfo ($docRecData);

				$docItem = [
					'icon' => $docTable->tableIcon ($docRecData), 'text' => $docInfo['docID'],
					'docAction' => 'edit', 'table' => $docTable->tableId(), 'pk' => $docRecData['ndx'], 'title' => $docInfo['title'],
					'class' => '', 'actionClass' => 'label label-info', 'type' => 'span'];

				$title[] = $docItem;
			}
		}
		if (isset ($this->docLinksDocs [$ndx]))
			$title = array_merge($title, $this->docLinksDocs [$ndx]);

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

		// -- email forward
		/*
		if ($issueKindCfg['enableEmailForward'] ?? 0)
		{
			$title[] = [
				'text' => 'Přeposlat',
				'type' => 'action', 'action' => 'addwizard', 'table' => 'wkf.core.issues', 'pk' => $ndx,
				'data-class' => 'e10pro.purchase.addWizard',
				'icon' => 'user/envelope', 'btnClass' => 'btn-success btn-xs', 'class' => 'pull-right',

			];
		}
		*/

		if (isset($this->atts[$ndx]))
			$title[] = ['text' => utils::nf($this->atts[$ndx]['count']), 'icon' => 'system/formAttachments', 'class' => 'e10-off pull-right'];

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
			'value' => $title, 'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'wkf.core.issues'
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
							'pk' => $comment['ndx'], 'docAction' => 'edit', 'data-table' => 'e10pro.wkf.messages',
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
					'btnClass' => 'btn-success',
					'data-addParams' => '__issue=' . $ndx,
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

					// -- email forward
					if ($issueKindCfg['enableEmailForward'] ?? 0)
					{
						$links[] = [
							'text' => 'Přeposlat',
							'type' => 'action', 'action' => 'addwizard', 'table' => 'wkf.core.issues',
							'data-class' => 'wkf.core.libs.IssueEmailForwardWizard',
							'icon' => 'user/envelope', 'btnClass' => 'btn-success btn-xs', 'class' => 'pull-right',
							'data-addparams' => 'focusedPK=' . $ndx,
						];
					}

					if (count($links) > 1)
					{
						$splitButton = [
							'type' => 'action', 'action' => 'addwizard', 'data-table' => 'wkf.core.issues', 'data-pk' => strval($ndx),
							'text' => '', 'title' => 'Rozdělit na jednotlivé zprávy podle příloh',
							'data-class' => 'wkf.core.libs.SplitIssueByAttachmentsWizard', 'icon' => 'system/actionSplit',
							'element' => 'span', 'class' => 'pull-right', 'actionClass' => '', 'btnClass' => '',
						];
						$links[] = $splitButton;
					}

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
		$q [] = 'SELECT comments.ndx, comments.issue ';
		if ($this->withBody)
			array_push ($q, ', comments.text, persons.fullName as authorFullName');
		//array_push ($q, ' persons.fullName as authorFullName');
		array_push ($q, ' FROM [wkf_core_comments] AS comments');

		if ($this->withBody)
			array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON comments.author = persons.ndx');
		array_push ($q, ' WHERE 1');
		//array_push ($q, ' AND [msgType] = %i', TableMessages::mtComment);
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

	protected function loadProjects ()
	{
		$q = [];
		array_push($q, 'SELECT docLinks.*,');
		array_push($q, ' [projects].shortName AS projectName');
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [wkf_base_projects] AS [projects] ON docLinks.dstRecId = [projects].ndx');
		array_push($q, ' WHERE [docLinks].linkId = %s', 'wkf-issues-projects', ' AND [docLinks].srcTableId = %s', 'wkf.core.issues');
		array_push($q, ' AND [docLinks].srcRecId IN %in', $this->pks);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
			$this->projects[$r['srcRecId']][] = ['text' => $r['projectName'], 'icon' => 'icon-lightbulb-o', 'class' => 'label label-primary'];
	}

	protected function loadTargets ()
	{
		$q = [];
		array_push($q, 'SELECT docLinks.*,');
		array_push($q, ' [targets].shortName AS targetName');
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [wkf_base_targets] AS [targets] ON docLinks.dstRecId = [targets].ndx');
		array_push($q, ' WHERE [docLinks].linkId = %s', 'wkf-issues-targets', ' AND [docLinks].srcTableId = %s', 'wkf.core.issues');
		array_push($q, ' AND [docLinks].srcRecId IN %in', $this->pks);

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
			$this->targets[$r['srcRecId']][] = ['text' => $r['targetName'], 'icon' => 'icon-flag-checkered', 'class' => 'label label-primary'];
	}

	function addSubSectionsToPanel ($panel, &$qry)
	{
		if ($this->subSections)
			$qry[] = ['style' => 'params', 'xtitle' => 'Sekce123', 'params' => $this->subSectionsParam];
	}

	function panelActiveMainId ($panelId)
	{
		$id = '';

		if ($panelId === 'right')
		{
		}

		return $id;
	}

	public function createPanelContentLeft (TableViewPanel $panel)
	{
		$qry = [];

		// -- subSections
		$this->addSubSectionsToPanel($panel, $qry);
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');
		$qry = [];
		$params = new \E10\Params ($panel->table->app());

		if ($this->treeMode && $this->viewerStyle !== self::dvsViewer)
		{
			$qry[] = ['style' => 'e10-small', 'pane' => 'padd5 e10-off', 'line' => ['code' => "<div style='margin-top: 3em;'/>"]];
		}

		$this->createPanelContentRight_Tags($panel,$params, $qry);
		$this->createPanelContentRight_UserRelated($panel,$params, $qry);

		$sectionInfo = [];
		$this->tableSections->sectionInfo($this->sectionNdx, $sectionInfo, $this->queryParam('widgetId'), $this->vid);
		if (count($sectionInfo))
			$qry[] = ['style' => 'e10-small', 'pane' => 'padd5 e10-off', 'line' => $sectionInfo];
		else
			$qry[] = ['style' => 'e10-small', 'pane' => 'padd5 e10-off pull-right-absolute', 'line' => ['text' => '', 'class' => '']];

		if (count($qry))
			$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	function createPanelContentRight_Tags (TableViewPanel $panel, &$params, &$qry)
	{
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$tagsParams = new \E10\Params ($panel->table->app());
			$tagsParams->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = ['style' => 'params', 'title' => $cg['name'], 'params' => $tagsParams];
			$tagsParams->detectValues();
		}
	}

	function createPanelContentRight_UserRelated (TableViewPanel $panel, &$params, &$qry)
	{
		$checkBoxesUserRelated = [
			'important' => ['title' => 'Důležité', 'id' => 'important', 'css' => 'display: block; text-align:left;'],
			'assigned' => ['title' => 'Přiřazeno', 'id' => 'assigned', 'css' => 'display: block; text-align:left;'],
			'notify' => ['title' => 'Na vědomí', 'id' => 'notify', 'css' => 'display: block; text-align:left;'],
			'author' => ['title' => 'Jsem autor', 'id' => 'author', 'css' => 'display: block; text-align:left;'],
		];
		$paramsUserRelated = new \E10\Params ($panel->table->app());
		$paramsUserRelated->addParam ('checkboxes', 'query.userRelated', ['items' => $checkBoxesUserRelated]);
		$qry[] = ['style' => 'params', 'title' => ['text' => 'Moje věci', 'icon' => 'system/iconUser'], 'params' => $paramsUserRelated];
		$paramsUserRelated->detectValues();
	}

	protected function addButtonsParams()
	{
		$btnParams = ['uiPlace' => $this->uiPlace, 'thisViewerId' => $this->vid];

		if ($this->treeMode)
		{
			if ($this->sectionCfg && isset($this->sectionCfg['parentSection']))
				$btnParams['topSection'] = $this->sectionCfg['parentSection'];
		}
		else
			$btnParams['topSection'] = $this->topSectionNdx;

		$btnParams['section'] = $this->sectionNdx;

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
				$icon = ($a['filetype'] === 'pdf') ? 'system/iconFilePdf' : 'system/iconFile';
				$l = ['text' => $a['name'], 'icon' => $icon, 'class' => 'e10-att-link btn btn-xs btn-default df2-action-trigger', 'prefix' => ''];
				$l['data'] =
					[
						'action' => 'open-link',
						'url-download' => $this->app()->dsRoot.'/att/'.$a['path'].$a['filename'],
						'url-preview' => $this->app()->dsRoot.'/imgs/-w1200/att/'.$a['path'].$a['filename'],
						'popup-id' => 'wdbi', 'with-shift' => 'tab' /* 'popup' */
					];
				$links[] = $l;
			}
		}

		if (isset($attachments['files']))
		{
			foreach ($attachments['files'] as $a)
			{
				$suffix = strtolower(substr($a['filename'], -3));
				if ($suffix !== 'zip')
					continue;

				$icon = 'system/iconFile';

				$unzipButton = [
					'text' => 'ZIP: '.$a['filename'], 'type' => 'action', 'action' => 'addwizard', 'icon' => $icon,
					'btnClass' => 'btn-xs btn-primary', 'class' => '_pull-right',
					'data-class' => 'wkf.core.libs.UnzipIssueAttachmentWizard',
					'data-addparams' => 'issueNdx='.$ndx.'&attachmentNdx='.$a['ndx'],
				];

				$links[] = $unzipButton;
			}
		}

		return $links;
	}

	public function createTopMenuSearchCode ()
	{
		if ($this->viewerStyle === self::dvsViewer)
			return parent::createTopMenuSearchCode();

		return '';
	}

	function createCoreSearchCode($toolbarClass = 'e10-sv-search-toolbar-default')
	{
		$fts = utils::es($this->fullTextSearch ());
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$placeholder = 'hledat ⏎';

		$c = '';

		$c .= "<div class='e10-sv-search e10-sv-search-toolbar $toolbarClass' style='padding-left: 1ex; padding-right: 1ex;' data-style='padding: .5ex 1ex 1ex 1ex; display: inline-block; width: 100%;' id='{$this->vid}Search'>";
		$c .=	"<table style='width: 100%'><tr>";

		$c .= $this->createCoreSearchCodeBegin();

		$c .= "<td class='fulltext' style='min-width:40%;'>";
		$c .=	"<span class='' style='width: 2em;text-align: center;position: absolute;padding-top: 2ex; opacity: .8;'><icon class='fa fa-search' style='width: 1.1em;'></i></span>";
		$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' placeholder='".utils::es($placeholder)."' value='$fts' data-onenter='1' style='width: calc(100% - 1em); padding: 6px 2em;'/>";
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

		$addButtons = [];

		if ($this->sectionNdx)
		{
			$addButtonsEnabled = 1;

			if ($this->treeMode && isset($this->sectionCfg['subSections']) && count($this->sectionCfg['subSections']))
				$addButtonsEnabled = 0;
			if ($this->sectionCfg['nia'] == 1 && !$this->sectionCfg['isAdmin'])
				$addButtonsEnabled = 0;
			if ($this->sectionCfg['eik'] === 9)
				$addButtonsEnabled = 0;

			if ($addButtonsEnabled)
			{
				$btnParams = $this->addButtonsParams();
				$this->tableIssues->addWorkflowButtons($addButtons, $btnParams);
			}
		}

		if ($this->help !== '')
		{
			$addButtons [] = [
				'type' => 'action', 'action' => 'open-popup', 'text' => '',
				'icon' => 'system/iconHelp', 'style' => 'cancel', 'side' => 1,
				'data-popup-url' => 'https://shipard.org/' . $this->help,
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8', 'class' => '',
				'title' => 'Nápověda'//DictSystem::text(DictSystem::diBtn_Help)
			];
		}

		if (count($addButtons))
		{
			$c .= '<td style="width: auto; text-align: right;">';
			$c .= $this->app()->ui()->composeTextLine($addButtons);
			$c .= '</td>';
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
		if ($this->viewerStyle === self::dvsViewer)
			return;

		$c = $this->createCoreSearchCode();
		$this->objectData ['staticContent'] = $c;
	}

	public function createToolbar ()
	{
		if ($this->viewerStyle === self::dvsViewer)
		{
			$addButtonsEnabled = 1;

			if ($this->treeMode && isset($this->sectionCfg['subSections']) && count($this->sectionCfg['subSections']))
				$addButtonsEnabled = 0;
			if ($this->sectionCfg['nia'] == 1 && !$this->sectionCfg['isAdmin'])
				$addButtonsEnabled = 0;
			if ($this->sectionCfg['eik'] === 9)
				$addButtonsEnabled = 0;

			if ($addButtonsEnabled)
			{
				$toolbar = [];
				$btnParams = $this->addButtonsParams();
				$this->tableIssues->addWorkflowButtons($toolbar, $btnParams);

				return $toolbar;
			}
		}
		return [];
	}

	public function createDetails ()
	{
		return [];
	}
}
