<?php

namespace wkf\docs\viewers;


use \Shipard\Viewer\TableView, \e10\utils, \Shipard\Viewer\TableViewPanel;
use \e10\base\libs\UtilsBase;

/**
 * Class DashboardDocumentsCore
 * @package wkf\docs\viewers
 */
class DashboardDocumentsCore extends TableView
{
	protected $textRenderer;
	protected $linkedPersons;
	protected $classification;
	protected $atts;
	protected $useText = FALSE;
	protected $treeMode = 0;

	var $selectParts = NULL;

	var $comments = [];
	var $attachmentsComments;
	var $notifications = [];

	protected $today;
	var $thisUserId = 0;
	var $uiPlace = '';

	protected $forceMainQuery = FALSE;

	var $folderNdx;
	var $folderCfg = NULL;
	var $topFolderNdx;
	var $topFolderCfg;
	var $usersFolders;
	var $subFolders = NULL;
	var $subFoldersParam = NULL;
	var $subFolderNdx = 0;

	var $viewerStyle = 0;
	var $withBody = TRUE;
	var $paneClass= 'e10-pane';
	var $simpleHeaders = FALSE;

	var $help = '';

	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1, dvsPanesMicro = 7;

	var $sourcesIcons = [
		0 => 'icon-keyboard-o', 1 => 'icon-envelope-o', 2 => 'icon-plug',
		3 => 'icon-android', 4 => 'system/iconWarning', 5 => 'icon-globe'
	];
	var $documentsKinds;

	/** @var  \wkf\docs\TableFolders */
	var $tableFolders;
	/** @var  \wkf\docs\TableDocuments */
	var $tableDocuments;

	var $issuesMarks;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

		$this->initMainQueries();

		$help = $this->queryParam('help');
		if ($help)
			$this->help = $help;

		$this->tableDocuments = $this->app->table ('wkf.docs.documents');
		$this->tableFolders = $this->app->table ('wkf.docs.folders');
		$this->usersFolders = $this->tableFolders->usersFolders();

		$this->topFolderNdx = $this->queryParam('folder');
		$this->folderNdx = $this->topFolderNdx;
		$this->topFolderCfg = ($this->topFolderNdx) ? $this->usersFolders['all'][$this->topFolderNdx] : NULL;

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
			$this->initFoldersTree();
		else
			$this->initSubFolders();

		$this->folderCfg = ($this->folderNdx) ? $this->usersFolders['all'][$this->folderNdx] : NULL;

		if (!$this->thisUserId)
			$this->thisUserId = intval($this->table->app()->user()->data ('id'));

		$this->textRenderer = new \lib\core\texts\Renderer($this->app());

		$this->today = utils::today();

		parent::init();
	}

	function initMainQueries()
	{
		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'active', 'title' => 'K řešení', 'icon' => 'system/filterActive'];
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

	function initSubFolders ()
	{
		if (!$this->topFolderCfg || !count($this->topFolderCfg['subFolders']))
			return;


		//$this->subSections = ['0' => 'Všechny'];
		foreach ($this->topFolderCfg['subFolders'] as $sf)
		{
			if (!isset($this->usersFolders['all'][$sf]))
				continue;
			$subFolderCfg = $this->usersFolders['all'][$sf];


			$this->subFolders[$sf] = [
				['text' => $subFolderCfg['sn'], 'class' => ''],
			//	['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$ss}' style='display:none;'></span>"],
			//	['text' => '', 'icon' => $marks->markCfg['states'][$nv]['icon'].' fa-fw', 'title' => $nt, 'class' => 'pull-right e10-small'],
			];
		}

		$this->subFoldersParam = new \E10\Params ($this->app);
		$this->subFoldersParam->addParam('switch', 'query-folders-subfolder', ['title' => '', 'switch' => $this->subFolders, 'list' => 1]);
		$this->subFoldersParam->detectValues();

		$this->subFolderNdx = intval($this->subFoldersParam->detectValues()['query-folders-subfolder']['value']);
		if ($this->subFolderNdx)
			$this->folderNdx = $this->subFolderNdx;

		$this->usePanelLeft = TRUE;
	}

	function initFoldersTree ()
	{
		foreach ($this->usersFolders['top'] as $uf => $folderCfg)
		{
			$this->subFolders[$uf] = [
				['text' => $folderCfg['sn'], 'class' => '', 'icon' => $folderCfg['icon'].' fa-fw'],
				//['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$us}' style='display:none;'></span>"],
			];

			if ($folderCfg['subFolders'])
			{
				foreach ($folderCfg['esf'] as $sf)
				{
					if (!isset($this->usersFolders['all'][$sf]))
						continue;
					$subFolderCfg = $this->usersFolders['all'][$sf];

					$this->subFolders[$uf][0]['subItems'][$sf] = [
						['text' => $subFolderCfg['sn'], 'class' => '', 'icon' => $subFolderCfg['icon'].' fa-fw'],
						//['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$ss}' style='display:none;'></span>"],
						//['text' => '', 'icon' => $marks->markCfg['states'][$nv]['icon'].' fa-fw', 'title' => $nt, 'class' => 'pull-right e10-small', 'css' => 'position: absolute; right:0; padding-right: 4px;'],
					];
				}
			}
		}

		$this->subFolders[0] = ['text' => 'Vše', 'class' => ''];

		$this->subFoldersParam = new \E10\Params ($this->app);
		$this->subFoldersParam->addParam('switch', 'query-folders-subfolder', ['title' => '', 'switch' => $this->subFolders, 'list' => 1]);
		$this->subFoldersParam->detectValues();

		if (isset($_POST['query-folders-subfolder']))
			$this->subFolderNdx = intval($_POST['query-folders-subfolder']);
		else
			$this->subFolderNdx = intval(key($this->subFolders));
		$this->folderNdx = $this->subFolderNdx;
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

		$q [] = 'SELECT docs.*, ';

		if ($selectPart)
			array_push ($q, ' %i', $selectPartNumber, ' AS selectPartOrder, %s', $selectPart, ' AS selectPart,');

		array_push ($q, ' persons.fullName AS authorFullName');
		array_push ($q, ' FROM [wkf_docs_documents] AS docs');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON docs.author = persons.ndx');
		array_push ($q, ' WHERE 1');

		$this->qryFolder($q, $selectPart);

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, 'docs.[title] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR docs.[text] LIKE %s', '%'.$fts.'%');

			array_push ($q, ' OR EXISTS (',
				'SELECT persons.fullName FROM [e10_base_doclinks] AS docLinks, e10_persons_persons AS p',
				' WHERE docs.ndx = srcRecId AND srcTableId = %s', 'wkf.docs.documents',
				' AND dstTableId = %s', 'e10.persons.persons', ' AND docLinks.dstRecId = p.ndx',
				' AND p.fullName LIKE %s)', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->qryDocState($q, $mqId, $fts, $selectPart);
		$this->qryClassification ($q);
		$this->qryOrder($q, $selectPart);
	}

	function qryDocState(&$q, $mqId, $fts, $selectPart)
	{
		if ($mqId === 'active')
		{
			array_push($q, ' AND (');
			if ($fts === '')
				array_push($q, 'docs.[docStateMain] <= %i', 2);
			else
				array_push($q, ' docs.[docStateMain] IN %in', [1, 2, 5]);
			array_push($q,' OR (docs.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
				' OR docs.[docState] = 8000'
			);
			array_push($q, ')');
		}
		elseif ($mqId === 'archive')
			array_push($q, ' AND (docs.[docStateMain] = %i)', 5);
		elseif ($mqId === 'trash')
			array_push($q, ' AND (docs.[docStateMain] = %i)', 4);

		if ($mqId === 'all')
		{
			array_push($q, ' AND (docs.[docStateMain] != %i', 0,
				' OR (docs.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
				')');
		}
	}

	public function qryFolder(&$q, $selectPart)
	{
		if ($this->treeMode)
		{
			if ($this->folderNdx === 0)
			{
				$ef = array_keys($this->usersFolders['all']);
				if (count($ef))
					array_push ($q, ' AND [docs].[folder] IN %in', $ef);
				else
					array_push ($q, ' AND 0');
			}
			else
			if (isset($this->usersFolders['top'][$this->folderNdx]))
			{
				if (isset($this->usersFolders['top'][$this->folderNdx]['esf']))
					$ef = $this->usersFolders['top'][$this->folderNdx]['esf'];
				else
					$ef = [$this->folderNdx];
				array_push ($q, ' AND [docs].[folder] IN %in', $ef);
			}
			else
				array_push ($q, ' AND [docs].[folder] = %i', $this->folderNdx);
		}
		else
			array_push ($q, ' AND [docs].[folder] = %i', $this->folderNdx);
	}

	public function qryClassification (&$q)
	{
		$qv = $this->queryValues ();

		if (isset($qv['clsf']))
		{
			foreach ($qv['clsf'] as $grpId => $grpItems)
			{
				array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE issues.ndx = recid AND tableId = %s', 'wkf.docs.documents');
				array_push($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
				array_push ($q, ')');
			}
		}

		/*
		if (isset($qv['userRelated']['author']))
		{
			array_push ($q, ' AND docs.author = %i', $this->thisUserId);
		}
		*/
	}

	protected function qryOrder (&$q, $selectPart)
	{
		//array_push ($q, ' ORDER BY [docStateMain], [dateCreate] DESC, [ndx] DESC ');
		array_push ($q, ' ORDER BY [docs].[docStateMain], [dateCreate]');
	}

	protected function qryOrderAll (&$q)
	{
		array_push ($q, ' ORDER BY selectPartOrder, dateCreate');
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks, 'e10-small');
		$this->atts = UtilsBase::loadAttachments ($this->app(), $this->pks, $this->table->tableId());

		$this->classification = UtilsBase::loadClassification ($this->app(), $this->table->tableId(), $this->pks);

		/*
		$this->issuesMarks = new \lib\docs\Marks($this->app());
		$this->issuesMarks->setMark(101);
		$this->issuesMarks->loadMarks('wkf.core.issues', $this->pks);
		*/
	}

	function messageBodyContent ($d)
	{
		/*
		if ($d['issueType'] === TableIssues::mtInbox)
			return ['type' => 'text', 'subtype' => 'auto', 'text' => $d['text'], 'class' => 'pageText',
				'iframeUrl' => $this->app()->urlRoot.'/api/call/e10pro.wkf.messagePreview/'.$d['ndx']];
		*/

		$this->textRenderer->render ($d ['text']);
		return ['class' => 'pageText', 'code' => $this->textRenderer->code];
	}

	function renderPane (&$item)
	{
		$this->checkViewerGroup($item);
		$folder = isset($this->usersFolders['all'][$item['folder']]) ? $this->usersFolders['all'][$item['folder']] : NULL;

		$ndx = $item ['ndx'];

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

		if (($this->folderCfg && $this->folderCfg['isAdmin']) || $folder && $folder['isAdmin'])
		{
			/*
			$seb = [
				'type' => 'action', 'action' => 'addwizard', 'data-table' => 'wkf.core.issues', 'data-pk' => strval($ndx),
				'text' => '', 'title' => 'Rychlé úpravy', 'data-class' => 'wkf.core.forms.SmartEdit', 'icon' => 'icon-magic',
				'element' => 'span', 'class' => 'pull-right e10-small', 'actionClass' => '', 'btnClass' => '',
			];
			$title[] = $seb;
			*/
		}

		$title[] = ['class' => 'id pull-right'.$msgTitleClass, 'text' => '#'.$item['documentId']];


		$title[] = ['class' => 'h2', 'text' => $item['title'], 'icon' => $this->table->tableIcon($item, 1)];
		$title[] = ['text' => utils::datef ($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => 'e10-off break'];

		//if (!$this->simpleHeaders)
		{
//			if ($item['issueType'] !== TableIssues::mtInbox)
//				if ($item ['authorFullName'])
//					$title[] = ['icon' => 'system/iconUser', 'text' => $item ['authorFullName'], 'class' => 'e10-off'];

				/*
			if ($item['issueType'] === TableIssues::mtInbox && isset ($this->linkedPersons [$ndx]['wkf-issues-from']))
				$title[] = $this->linkedPersons [$ndx]['wkf-issues-from'];
			elseif ($item['issueType'] === TableIssues::mtOutbox && isset ($this->linkedPersons [$ndx]['wkf-issues-to']))
				$title[] = $this->linkedPersons [$ndx]['wkf-issues-to'];
				*/
		}

		//$this->addDeadlineDate ($item, $title);

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


		if (($item['folder'] && !$this->topFolderNdx))
		{
			$tgLabel = ['text' => $folder['sn'], 'class' => 'label label-default', 'icon' => $folder['icon']];

			if ($folder['parentFolder'])
			{
				$topFolder = $this->tableDocuments->topFolder ($item['folder']);
				$tgLabel['suffix'] = $topFolder['sn'];
			}
			$title[] = $tgLabel;
		}

		// -- labels
		if (isset ($this->classification [$ndx]))
		{
			forEach ($this->classification [$ndx] as $clsfGroup)
				$title = array_merge($title, $clsfGroup);
		}

		// -- mark
		/*
		$title[] = [
			'text' => '', 'docAction' => 'mark', 'mark' => 101, 'table' => 'wkf.core.issues', 'pk' => $ndx,
			'value' => isset($this->issuesMarks->marks[$ndx]) ? $this->issuesMarks->marks[$ndx] : 0,
			'actionClass' => 'pull-right', 'class' => '', 'mark-st' => 'i',
		];
		*/

		if (isset($this->atts[$ndx]))
			$title[] = ['text' => utils::nf($this->atts[$ndx]['count']), 'icon' => 'icon-paperclip', 'class' => 'e10-off pull-right'];


		$titleClass = '';

		if ($this->withBody)
			$titleClass .= ' e10-ds '.$item ['docStateClass'];

		$item ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title, 'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'wkf.docs.documents'
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
			if ($this->viewerStyle != self::dvsPanesMicro)
			{

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

	function addSubFoldersToPanel ($panel, &$qry)
	{
		if ($this->subFolders)
			$qry[] = ['style' => 'params', 'params' => $this->subFoldersParam];
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
		$this->addSubFoldersToPanel($panel, $qry);
		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createPanelContentRight (TableViewPanel $panel)
	{
		$panel->activeMainItem = $this->panelActiveMainId('right');
		$qry = [];
		$params = new \E10\Params ($panel->table->app());

		if ($this->treeMode)
		{
			$viewModes = [
				'2' => ['text' => '', 'icon' => 'system/dashboardModeTilesSmall'],
				'1' => ['text' => '', 'icon' => 'system/dashboardModeRows'],
				//'3' => ['text' => '', 'icon' => 'icon-square'],
				'0' => ['text' => '', 'icon' => 'system/dashboardModeTilesBig'],
			];
			$viewParams = new \E10\Params ($panel->table->app());

			$viewParams->addParam('switch', 'viewer-mode', ['defaultValue' => '2', 'justified' => 1, 'switch' => $viewModes, 'radioBtn' => 1, 'cccplace' => 'panel']);
			$qry[] = ['style' => 'params', 'ctitle' => 'Pokus', 'params' => $viewParams];
			$viewParams->detectValues();
		}

		$this->createPanelContentRight_Tags($panel,$params, $qry);

		$folderInfo = [];
		$this->tableFolders->folderInfo($this->folderNdx, $folderInfo, $this->queryParam('widgetId'), $this->vid);
		if (count($folderInfo))
			$qry[] = ['style' => 'e10-small', 'pane' => 'padd5 e10-off', 'line' => $folderInfo];

		$qry[] = ['style' => 'e10-small', 'pane' => 'padd5 e10-off pull-right-absolute', 'line' => ['text' => '', 'class' => '']];

		if (count($qry))
			$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	function createPanelContentRight_Tags (TableViewPanel $panel, &$params, &$qry)
	{
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);
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
			if ($this->folderCfg && isset($this->folderCfg['parentFolder']))
				$btnParams['topFolder'] = $this->folderCfg['parentFolder'];
		}
		else
			$btnParams['topFolder'] = $this->topFolderNdx;

		$btnParams['folder'] = $this->folderNdx;

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
						'url-preview' => $this->app()->dsRoot.'/imgs/-w1200/att/'.$a['path'].$a['filename'],
						'popup-id' => 'wdbd', 'with-shift' => 'tab' /* 'popup' */
					];
				$links[] = $l;
			}
		}

		return $links;
	}

	public function createTopMenuSearchCode () {return '';}

	function createCoreSearchCode($toolbarClass = 'e10-sv-search-toolbar-default')
	{
		$fts = utils::es($this->fullTextSearch ());
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$placeholder = 'hledat ⏎';

		/*
		if ($this->projectsGroup)
		{
			$projectGroup = $this->app()->cfgItem ('e10pro.wkf.projectsGroups.'.$this->projectsGroup, NULL);
			if ($projectGroup && isset ($projectGroup['topic']) && $projectGroup['topic'] !== '')
				$placeholder = $projectGroup['topic'];
		}
		*/

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

		if ($this->folderNdx)
		{
			$addButtonsEnabled = 1;

			if ($this->treeMode && isset($this->folderCfg['subFolders']) && count($this->folderCfg['subFolders']))
				$addButtonsEnabled = 0;

			if ($addButtonsEnabled)
			{
				$btnParams = $this->addButtonsParams();
				$this->tableDocuments->addDashboardButtons($addButtons, $btnParams);
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
		$c = $this->createCoreSearchCode();
		$this->objectData ['staticContent'] = $c;
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
