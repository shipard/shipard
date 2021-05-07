<?php

namespace lib\core\attachments\viewers;

use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class PanesCore
 * @package lib\core\attachments\viewers
 */
class PanesCore extends TableView
{
	var $viewerStyle = 0;
	var $withBody = TRUE;
	var $paneClass= 'e10-pane';
	var $simpleHeaders = FALSE;
	CONST dvsPanes = 0, dvsPanesMini = 2, dvsPanesOneCol = 3, dvsRows = 1, dvsPanesMicro = 7;

	/** @var  \e10\base\TableAttachments */
	var $tableAttachments;

	var $trayNdx = 0;
	var $attTableId = '';
	var $attRecId = 0;

	var $selectParts = NULL;

	/** @var null \lib\geoTags\GeoTagsLabels */
	var $geoTags = NULL;


	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		//$this->usePanelRight = 1;
		$this->enableDetailSearch = TRUE;

		if ($this->enableDetailSearch)
		{
			/*
			$mq [] = ['id' => 'active', 'title' => 'K řešení', 'icon' => 'icon-bolt'];
			$mq [] = ['id' => 'done', 'title' => 'Hotovo', 'icon' => 'icon-check'];
			$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'icon-archive'];
			$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'icon-toggle-on'];
			if ($this->app()->hasRole('pwuser'))
				$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'icon-trash'];
			$this->setMainQueries($mq);
			*/
		}

		$this->tableAttachments = $this->app->table ('e10.base.attachments');

		$this->trayNdx = intval($this->queryParam('tray'));
		if ($this->trayNdx)
		{
			$this->attTableId = 'wkf.base.trays';
			$this->attRecId = $this->trayNdx;
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
				$this->setPaneMode(4);
				break;
			case self::dvsPanesOneCol:
				$this->setPaneMode(2);
				break;
			case self::dvsPanesMini :
				$this->setPaneMode(5, 17);
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

		$q [] = 'SELECT attachments.* ';

		if ($selectPart)
			array_push ($q, ' %i', $selectPartNumber, ' AS selectPartOrder, %s', $selectPart, ' AS selectPart,');

		array_push ($q, ' FROM [e10_attachments_files] AS attachments');
		array_push ($q, ' WHERE 1');


		array_push ($q, ' AND [tableid] = %s', $this->attTableId, ' AND [recid] = %i', 1);


		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, 'attachments.[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR attachments.[perex] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->qryOrder($q, $selectPart);
	}

	protected function qryOrder (&$q, $selectPart)
	{
		array_push ($q, ' ORDER BY attachments.[ndx]');
	}

	protected function qryOrderAll (&$q)
	{
	}

	public function selectRows2 ()
	{
		$this->geoTags = new \lib\geoTags\GeoTagsLabels($this->app());

		if (!count ($this->pks))
			return;

		$this->geoTags->setSrcRecs($this->tableAttachments, $this->pks);
		$this->geoTags->run();
	}

	function renderPane (&$item)
	{
		$this->checkViewerGroup($item);

		$ndx = $item ['ndx'];

		$item['pk'] = $ndx;
		$item ['pane'] = ['title' => [], 'body' => [], 'class' => $this->paneClass];
		//if (!$this->withBody)
		//	$item ['pane']['class'] .= ' e10-ds '.$item ['docStateClass'];

		$title = [];

		//$title[] = ['class' => 'id pull-right'.$msgTitleClass, 'text' => utils::nf($item['ndx']), 'icon' => 'icon-hashtag'];
		$title[] = ['class' => 'e10-bold', 'text' => $item['name'], 'XXicon' => $this->table->tableIcon($item, 1)];
		//$title[] = ['text' => utils::datef ($item['dateCreate'], '%D, %T'), 'icon' => $this->sourcesIcons[$item['source']], 'class' => 'e10-off break'];

		$title[] = ['text' => '', 'class' => 'block'];

		$attInfo = $this->table->attInfo($item);
		if ($item['fileKind'] !== 0)
			$title = array_merge($title, $attInfo['labels']);


		$attThumbnailURL = $this->app->dsRoot.'/imgs/-w512/att/'.$item['path'].$item['filename'];
		$imgCode = "<img src='$attThumbnailURL' style='width:100%'>";
		$item ['pane']['body'][] = ['code' => $imgCode, 'class' => 'block'];


		$geoTags = $this->geoTags->recLabels($ndx);
		if ($geoTags)
		{
			$gtl = array_merge([['text' => '', 'icon' => 'icon-map-marker']], $geoTags);
			$item ['pane']['body'][] = ['class' => 'padd5', 'value' => $gtl];
		}

		$item ['pane']['title'][] = [
			'class' => 'padd5',//$titleClass,
			'value' => $title, 'pk' => $item['ndx'], 'docAction' => 'edit', 'data-table' => 'e10.base.attachments'
		];
	}

	function checkViewerGroup (&$item)
	{
	}

	function panelActiveMainId ($panelId)
	{
		$id = '';

		if ($panelId === 'right')
		{
		}

		return $id;
	}

	public function createTopMenuSearchCode ()
	{
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
		/*
		forEach ($this->mainQueries as $q)
		{
			if ($mqId === $q['id'])
				$active = ' active';
			$txt = utils::es ($q ['title']);
			$c .= "<span class='q$active' data-mqid='{$q['id']}' title='$txt'>".$this->app()->ui()->renderTextLine($q)."</span>";
			$idx++;
			$active = '';
		}
		*/
		$c .= '</div>';
		$c .= '</td>';

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
