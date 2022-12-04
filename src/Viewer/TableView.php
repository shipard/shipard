<?php

namespace Shipard\Viewer;
use \translation\dicts\e10\base\system\DictSystem;
use \e10\ContentRenderer;
use \e10\utils;
use \e10\uiutils;


class TableView extends \Shipard\Base\BaseObject
{
	/** @var \Shipard\Table\DbTable */
	var $table;

	protected $db;
	var $ok = 0;
	var $queryRows;
	var $countRows = 0;
	var $lineRowNumber = 0;
	var $enableFullTextSearch = TRUE;
	var $disableFullTextSearchInput = FALSE;
	var $disableIncrementalSearch = FALSE;
	var $fullWidthToolbar = FALSE;
	var $toolbarTitle = NULL;
	var $enableToolbar = TRUE;
	var $fullTextSearch = "";
	var $viewId;
	var $viewerDefinition = NULL;
	var $objectSubType;
	var $checkboxes;
	var $enableDetailSearch = FALSE;
	var $objectData = array ();
	var $pks = array ();
	var $addParams = array ();
	var $vid;
	var $docState = NULL;
	var $mainQueries;
	var $bottomTabs;
	var $topTabs;
	var $topParams;
	var $topParamsValues;
	var $panels = FALSE;
	var $linesWidth = 40;
	var $mode = 'classic';
	var $type = 'normal';
	var $toolbarElementId = '';
	var $inlineSourceElement = NULL;
	var $htmlRowElement;
	var $htmlRowElementClass = '';
	var $htmlRowsElement;
	var $htmlRowsElementClass = '';
	var $rowAction = FALSE;
	var $rowActionClass = FALSE;
	var $accessLevel = 0;
	var $fastSearch = FALSE;
	var $paneMode = FALSE;
	var $panesColumns = 0;
	var $panesColumnWidth = 30;

	var $usePanelLeft = FALSE;
	var $usePanelRight = 0;
	var $panelLeft = NULL;
	var $panelRight = NULL;

	var $classes;
	var $onlyOneRec = 0;

	var $rowsFirst;
	var $rowsPageSize;
	var $rowsLoadNext;
	var $rowsPageNumber;
	var $refreshRowsPageNumber = 0;

	var $gridStruct;
	protected $gridColClasses;
	protected $gridTableHeaderCode;
	var $gridTableRenderer;
	protected $gridEditable = FALSE;
	var $info = [];

	/**@var \E10\ContentRenderer */
	protected $contentRenderer;

	var $comboSettings;
	var $saveAs = '';

	static $vidCounter = 1;

	var $queryParams;

	const vsMain = 'mainViewer', vsMini = 'miniViewer', vsDetail = 'detailViewer';
	const sptHelp 	= 0x0001,
				sptQuery 	= 0x0002,
				sptReview = 0x0004;

	var $mobile = FALSE;

	public function __construct ($table, $viewId, $queryParams = NULL)
	{
		parent::__construct ($table->app());

		if ($this->app->testGetParam ('mobile'))
			$this->mobile = 1;

		$this->table = $table;
		$this->db = $table->dbmodel->db;
		$this->viewId = $viewId;
		$this->objectSubType = TableView::vsMain;
		$this->queryParams = $queryParams;

		$this->saveAs = $this->app()->testGetParam ('saveas');
		$this->rowsPageNumber = intval($this->app()->testGetParam ('rowsPageNumber'));

		$this->rowsPageSize = 50;
		$this->rowsFirst = $this->rowsPageNumber * $this->rowsPageSize;
		$this->rowsLoadNext = 0;

		$this->refreshRowsPageNumber = intval($this->app()->testGetParam ('refreshRowsPageNumber'));
		if ($this->refreshRowsPageNumber)
		{
			$this->rowsPageSize = 50 * ($this->refreshRowsPageNumber + 1);
			$this->rowsFirst = 0;
			$this->rowsLoadNext = 0;
			$this->refreshRowsPageNumber = intval($this->rowsPageSize / 50) - 1;
		}

		$this->objectData ['name'] = $this->table->tableName ();
		$this->objectData ['dataItems'] = array ();
		$this->objectData ['htmlReport'] = "";
		$this->checkboxes = FALSE;

		$this->htmlRowsElement = 'ul';
		$this->htmlRowElement = 'li';

		if (isset ($_POST ['fullTextSearch']))
		{
			$this->fullTextSearch = trim($_POST ['fullTextSearch']);
		}
		else
			$this->fullTextSearch = trim($this->app()->testGetParam ('fullTextSearch'));

		self::$vidCounter++;
		$vid = $this->app()->testGetParam ('newElementId');
		if ($vid == '')
			$vid = 'mainListView' . mt_rand () . '_' . self::$vidCounter;
		$this->vid = $vid;

		$this->accessLevel = $this->table->app()->checkAccess (array ('object' => 'viewer', 'table' => $table->tableId(), 'viewer' => $viewId));
	}

	public function app() {return $this->table->app();}
	public function db() {return $this->db;}

	public function addAddParam ($column, $value)
	{
		$colParamName = '__' . $column;
		$this->addParams [$colParamName] = $value;
	}

	public function appendAsSubObject ()
	{
		$this->app()->response->addSubObject($this->vid, 'viewer');
		if ($this->mode === 'panes')
		{
			$this->app()->response->addSubObjectPart($this->vid, 'htmlItems', $this->objectData['htmlItems']);
			$this->app()->response->addSubObjectPart($this->vid, 'viewer', $this->objectData['viewer']);
			if (isset($this->objectData['staticContent']))
				$this->app()->response->addSubObjectPart($this->vid, 'staticContent', $this->objectData['staticContent']);
			if (isset($this->objectData['viewerGroups']))
				$this->app()->response->addSubObjectPart($this->vid, 'viewerGroups', $this->objectData['viewerGroups']);
			if (isset($this->objectData['endMark']))
				$this->app()->response->addSubObjectPart($this->vid, 'endMark', $this->objectData['endMark']);
		}

//		if (isset($this->objectData['htmlPanelRight']))
//			$this->app()->response->addSubObjectPart($this->vid, 'viewer', $this->objectData['htmlPanelRight']);
	}

	public function addChangedPanels ()
	{

		if ($this->usePanelRight)
		{
			$panelMainItemId = $this->queryParam('panel-main-item-right');
			$ccc = $this->panelActiveMainId ('right');
			if ($this->panelActiveMainId ('right') !== $panelMainItemId)
			{
				$this->panelRight = $this->panel('right');
				$this->createPanelContent($this->panelRight);

				$c = $this->panelRight->createCode();

				$this->objectData['htmlPanelRight'] = $c;
			}
		}
	}

	public function flowParams()
	{
		return NULL;
	}

	function panelActiveMainId ($panelId)
	{
		return '';
	}

	public function addParams ()
  {
		return http_build_query ($this->addParams);
	}

	public function addGroupHeader ($name)
	{
		$this->objectData ['dataItems'][] = ['groupName' => $name];
	}

	public function addListItem ($listItem)
	{
		$this->objectData ['dataItems'][] = $listItem;
		if (isset ($listItem ['pk']))
			$this->pks [] = $listItem ['pk'];
		else
		if (isset ($listItem ['ndx']))
			$this->pks [] = $listItem ['ndx'];

		$this->countRows++;
	}

	public function addListItem2 ($listItem)
	{
		$h = $this->rowHtml ($listItem);
		$this->addHtmlItem($h, $listItem);
	}


	public function addEndMark ()
	{
		$txt = ($this->rowsLoadNext) ? 'Načítají se další řádky' : $this->endMark (($this->rowsPageNumber === 0 && $this->countRows === 0));
		if ($this->mode === 'panes')
		{
			$this->objectData['endMark'] = $this->app()->ui()->composeTextLine($txt);
		}
		else
		{
			$cls = ($this->rowsLoadNext) ? 'e10-viewer-list-endNext' : 'e10-viewer-list-endEnd';
			$h = "<{$this->htmlRowElement} class='$cls'>".$this->app()->ui()->composeTextLine($txt)."</{$this->htmlRowElement}>";
			$this->addHtmlItem($h);
		}
	}

	function addHtmlItem ($item, $listItem = NULL)
	{
		if ($this->mode === 'panes')
		{
			$hi = ['code' => $item];

			if ($listItem && isset($listItem['vgId']))
				$hi['vgId'] = $listItem['vgId'];

			if ($listItem && isset($listItem['columnNumber']))
				$hi['columnNumber'] = $listItem['columnNumber'];

			$this->objectData ['htmlItems'][] = $hi;
		}
		else
			$this->objectData ['htmlItems'] .= $item;
	}

	public function endMark ($blank)
	{
		return ['icon' => 'system/iconViewerEnd', 'text' => 'To je všechno'];
	}

	public function bottomTabId ()
	{
		if (isset ($_POST ['bottomTab']))
			return $_POST ['bottomTab'];
		if (isset($this->bottomTabs))
			forEach ($this->bottomTabs as $q)
				if ($q['active'])
					return $q['id'];

		return '';
	}

	public function checkBlankResult ()
	{
	}

	public function checkFastSearch ()
	{
		$fts = $this->fullTextSearch ();
		if ($fts === '')
			return;

		$this->fastSearch = array ();
		$words = explode (' ', $fts);
		forEach ($words as $w)
		{
			$this->fastSearch[] = $w;
		}
	}

	public function topTabId ()
	{
		if (isset ($_POST ['topTab']))
			return $_POST ['topTab'];
		if (isset($this->topTabs))
			forEach ($this->topTabs as $q)
				if ($q['active'])
					return $q['id'];

		return '';
	}

	public function countRows ()
	{
		return $this->countRows;
	}

	function createViewerCode ($format, $fullCode, $jsinit = FALSE)
	{
		if ($this->mobile)
			return $this->createViewerCodeMobile ($format, $fullCode, $jsinit);

		$this->toolbarElementId = 'e10-tm-detailbuttonbox';
		if ($this->objectSubType == TableView::vsDetail)
			$this->toolbarElementId = 'mainBrowserRightBarButtonsEdit';
		if ($this->fullWidthToolbar)
			$this->toolbarElementId = $this->vid.'FWTEditButtons';
		elseif ($this->paneMode)
			$this->toolbarElementId = 'NONE';
		elseif ($this->type === 'inline' || $this->type === 'form')
			$this->toolbarElementId = $this->vid.'_Toolbar';

		$detailCode = '';

		//$detailCode .= $this->createDetailsCode ();

		$detailCode .= "<div style='display: none;' class='e10-mv-ld' id='{$this->vid}Details'>";

		if ($this->paneMode && $this->objectSubType !== TableView::vsDetail)
			$detailCode .= $this->createDetailsCode ();

		$detailCode .=
			"<div class='e10-mv-ld-header'></div>" .
			"<div class='e10-mv-ld-content' data-e10mxw='1'></div>" .
		"</div>";

		$reportCode =
		"<div style='display: none;' class='e10-mv-lr' id='{$this->vid}Report'>" .
				$this->createPanelsCode () .
				"<div class='e10-mv-lr-content'>{$this->report ()}</div>" .
				"</div>
		</div>";

		$viewerClass = "df2-viewer e10-viewer-{$this->table->tableId()} e10-{$this->objectSubType}";
		$viewerClass .= ' e10-viewer-type-'.$this->type;
		if ($this->objectSubType == TableView::vsDetail && !$this->enableDetailSearch)
			$viewerClass .= ' e10-viewer-nosearch';
		if (!isset ($this->bottomTabs))
			$viewerClass .= ' e10-viewer-noBottomTabs';

		if (isset ($this->topParams))
			$viewerClass .= ' e10-viewer-maingrid';

		if ($this->paneMode)
			$viewerClass .= ' e10-viewer-pane';
		else
			$viewerClass .= ' e10-viewer-classic';

		if ($this->usePanelLeft)
			$viewerClass .= ' e10-viewer-panel-left';

		if ($this->fullWidthToolbar)
			$viewerClass .= ' e10-viewer-fw-toolbar';

		if (isset ($this->classes))
			$viewerClass .= ' '.implode (' ', $this->classes);

		$c =
				"<div style='display: none;' class='$viewerClass' id='{$this->vid}' data-viewer='{$this->vid}' data-object='viewer'
					data-viewertype='{$this->objectSubType}' data-table='" . $this->table->tableId () . "' data-viewer-view-id='" . $this->viewId () . "' " .
					"data-addparams='{$this->addParams ()}' data-queryparams='{$this->queryParams()}' data-lineswidth='{$this->linesWidth}' ".
					"data-toolbar='{$this->toolbarElementId}' data-mode='{$this->mode}' data-type='{$this->type}'";

		if ($this->inlineSourceElement)
		{
			foreach ($this->inlineSourceElement as $key => $value)
				$c .= " data-inline-source-element-{$key}='" . utils::es($value) . "'";
		}

		//if ($this->mode === 'panes')
		//	$c .= " data-panes-columns='{$this->panesColumns}'";

		if ($this->app()->remote !== '')
			$c .= " data-remote='".$this->app()->remote."'";

		if ($this->comboSettings)
		{
			forEach ($this->comboSettings as $csKey => $csValue)
				$c .= ' data-combo-'.$csKey."='".$csValue."'";
		}

		if ($fullCode)
		{
			$flowParams = $this->flowParams();
			if ($flowParams)
				$c .= " data-flow-params='".base64_encode(json_encode($flowParams))."'";
		}

		$c .= ">";

		// -- toolbar?
		if ($this->type === 'inline')
		{
			$c .= "<div id='{$this->toolbarElementId}__Main' style='width:70%; background-color: red;'>A";
			$c .= $this->createToolbarCode ();
			$c .= "<div id='{$this->toolbarElementId}' style='display: inline-block; padding-left: 1em;'>";
			$c .= '</div>';
			$c .= '</div>';
		}

		$c .= $this->createViewerBodyCode();

		if ($this->objectSubType == TableView::vsMain)
		{
			$c .= $detailCode . $reportCode;
		}
		$c .= "</div>";
		if ($jsinit)
			$c .= "<script type='text/javascript'>jQuery(function tst (){initViewer ('$this->vid')});</script>";
		return $c;
	}

	function createViewerCodeMobile ($format, $fullCode, $jsinit = FALSE)
	{
		$btCode = '';
		$btInputCode = '';
		if (isset($this->bottomTabs))
		{
			$activeTab = 0;
			$idx = 0;
			forEach ($this->bottomTabs as $q)
			{
				if ($q['active'])
				{
					$activeTab = $idx;
					break;
				}
				$idx++;
			}

			$btInputCode = "<input name='bottomTab' type='hidden' value='{$this->bottomTabs[$activeTab]['id']}'/>";

			$idx = 0;
			$btCode .= "<ul class='e10-viewer-tabs' id='e10-page-footer'>";
			forEach ($this->bottomTabs as $q)
			{
				if ($idx === $activeTab)
					$btCode .= "<li class='active' data-id='{$q['id']}'>";
				else
					$btCode .= "<li data-id='{$q['id']}'>";
				$btCode .= utils::es($q ['title']);
				$btCode .= '</li>';
				$idx++;
			}
			$btCode .= '</ul>';
		}


		$c = '';

		$c .= "<div class='e10-viewer'";
		$c .= " data-table='" . $this->table->tableId () . "' data-viewer='{$this->vid}' data-viewer-view-id='" . $this->viewId () . "'";
		$c .= " id='{$this->vid}' data-object='viewer' data-rowspagenumber='0'";

		if ($this->rowAction)
			$c .= " data-rowaction='{$this->rowAction}'";
		if ($this->rowActionClass)
			$c .= " data-rowactionclass='{$this->rowActionClass}'";

		$c .= ">";

		if (isset($this->comboSettings))
		{
			$c .= "<table class='e10-form-combo-viewer-title' id='e10-form-combo-viewer-title' style='width: 100%; background-color: #00aa00;'>";

			$c .= "<tr>";
			$c .= "<td>";

			$c .= "<div class='e10-viewer-search'>";
			$c .= "<input type='search' class='e10-inc-search' name='fullTextSearch' incremental='incremental' data-combo='1'>";
			//$c .= $btInputCode;
			$c .= "</div>";

			$c .= "</td>";
			$c .= "<td class='c e10-trigger-cv'>".$this->app()->ui()->icon('system/actionClose')."</td>";
			$c .= "</tr>";
			$c .= "</table>";
		}
		else
		{
			$c .= "<div class='e10-viewer-search off'>";
			$c .= "<input type='search' class='e10-inc-search' name='fullTextSearch' incremental='incremental'>";
			$c .= $btInputCode;
			$c .= "</div>";
		}
		$c .= "<ul class='e10-viewer-list' id='{$this->vid}Items'>";
		$c .= $this->rows ();
		$c .= '</ul>';

		$c .= '</div>';

		$c .= $btCode;

		return $c;
	}

	function createViewerBodyCode ()
	{
		$c = '';

		$listClass = ' '.$this->objectSubType;
		if ($this->comboSettings)
			$listClass .= ' e10-viewer-combo';
		if ($this->htmlRowsElementClass !== '')
			$listClass .= ' '.$this->htmlRowsElementClass;

		$c .= $this->createLeftPanelCode();

		if ($this->fullWidthToolbar)
			$c .= $this->createFullWidthToolbarCode();

		$c .= "<div class='e10-sv-body'>";

		if (!$this->fullWidthToolbar)
			$c .= $this->createTopMenuSearchCode ();

		$c .= "<{$this->htmlRowsElement} style='z-index: 499;' class='df2-viewer-list e10-viewer-list$listClass' id='{$this->vid}Items' data-rowspagenumber='0'".
					"data-viewer='$this->vid' data-rowelement='{$this->htmlRowElement}'>";
		$c .= $this->rows ();
		$c .= "</{$this->htmlRowsElement}>";
		$c .= $this->createBottomTabsCode ();
		$c .= '</div>';

		$c .= $this->createRightPanelCode();

		return $c;
	}

	public function createLeftPanelCode()
	{
		if (!$this->usePanelLeft)
			return '';

		$this->panelLeft = $this->panel('left');
		$this->createPanelContent ($this->panelLeft);

		$c = '';

		$c .= "<div class='e10-sv-left' id='{$this->vid}PanelLeft'>";
		$c .= $this->panelLeft->createCode();
		$c .= '</div>';

		return $c;
	}

	public function createRightPanelCode()
	{
		if ($this->usePanelRight === 0)
			return '';

		$this->panelRight = $this->panel('right');
		$this->createPanelContent ($this->panelRight);

		$c = '';

		$panelClass = 'e10-sv-right';
		if ($this->usePanelRight === 3)
			$panelClass .= ' floating close';

		$c .= "<div class='$panelClass' id='{$this->vid}PanelRight'>";

		if ($this->usePanelRight === 3)
			$c .= "<div class='tlbr e10-reportPanel-toggle'><i class='fa fa-bars'></i></div><div class='params'>";

		$c .= $this->panelRight->createCode();

		if ($this->usePanelRight === 3)
			$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	public function createToolbar ()
	{
		$toolbar = [];

		if ($this->accessLevel === 2 || $this->accessLevel === 30)
		{
			$this->createToolbarAddButton ($toolbar);
		}

		if ($this->accessLevel === 2 || $this->accessLevel === 30)
		{
			$tableAddWizard = $this->table->app()->model()->tableProperty ($this->table, 'addWizard');
			if (isset ($tableAddWizard[0]['class']))
			{
				foreach ($tableAddWizard as $aw)
					$this->createToolbar_addWizard($toolbar, $aw);
			}
			else
				$this->createToolbar_addWizard($toolbar, $tableAddWizard);

			if (isset($this->viewerDefinition['addWizard']))
			{
				if (isset ($this->viewerDefinition['addWizard'][0]['class']))
				{
					foreach ($this->viewerDefinition['addWizard'] as $aw)
						$this->createToolbar_addWizard($toolbar, $aw);
				}
				else
					$this->createToolbar_addWizard($toolbar, $this->viewerDefinition['addWizard']);
			}
		}

		return $toolbar;
	} // createToolbar

	public function createToolbarAddButton (&$toolbar)
	{
		$toolbar [] = ['type' => 'action', 'action' => 'newform', 'text' => DictSystem::text(DictSystem::diBtn_Insert)];
	}

	public function createToolbar_addWizard (&$toolbar, $addWizard)
	{
		if ($addWizard)
		{
			$newItem = array ('type' => 'action', 'action' => 'addwizard', 'text' => '', 'data-class' => $addWizard ['class']);
			if (isset ($addWizard['icon']))
				$newItem['icon'] = $addWizard['icon'];
			if (isset ($addWizard['text']))
				$newItem['text'] = $addWizard['text'];

			if (isset($addWizard['place']))
			{
				if (isset ($addWizard['enabledCfgItem']) && $this->app()->cfgItem($addWizard['enabledCfgItem'], 0) == 0)
					return;

				$toolbar [0]['dropdownMenu'][] = $newItem;
			}
			else
				$toolbar [] = $newItem;
		}
	}

	public function createViewerTools ()
	{
		$tools = [];

		if (isset($this->viewerDefinition['tools']))
		{
			foreach ($this->viewerDefinition['tools'] as $aw)
			{
				$newItem = ['type' => 'action', 'action' => 'addwizard', 'text' => '', 'data-class' => $aw ['class'], 'btnClass' => ''];
				if (isset ($aw['icon']))
					$newItem['icon'] = $aw['icon'];
				if (isset ($aw['text']))
					$newItem['text'] = $aw['text'];

				$tools[] = $newItem;
			}
		}

		if (count($tools))
			return $tools;

		return FALSE;
	}

	public function createToolbarCode ()
	{
		$c = '';
		$tlbr = $this->createToolbar ();

		if ($this->fullWidthToolbar)
		{
			$c .= $this->app()->ui()->composeTextLine($tlbr);
			return $c;
		}

		$btnClass = 'btn-large';
		if ($this->objectSubType == TableView::vsMini)
			$btnClass = 'btn-small';

		foreach ($tlbr as $btn)
		{
			if ($btn['type'] == 'code')
			{
				$c .= $btn['code'];
			}
			else
			{
				$class = '';

				if (isset ($btn['doubleClick']))
					$class .= ' dblclk';

				$icon = '';
				$dataTable = '';
				switch ($btn['action'])
				{
					case 'newform':	$class .= ' btn-success';
													$icon = $this->app()->ui()->icon('system/actionAdd');
													break;
					case 'addwizard':	$class .= ' btn-success';
														$icon = $this->app()->ui()->icon($btn['icon'] ?? 'system/actionAddWizard');
														break;
					case 'new':
													$class .= ' e10-document-trigger';
													$icon = $this->app()->ui()->icon('system/actionAdd');
													if (isset ($btn ['table']))
														$dataTable = "data-table='{$btn ['table']}' ";
													break;
					case '':				$class .= 'btn btn-success';
													$icon = $this->app()->ui()->icon($btn['icon'] ?? 'system/actionAddWizard');
													break;
			}
				$btnParams = '';
				if (isset ($btn['data-class']))
					$btnParams .= "data-class='{$btn['data-class']}' ";

				if (isset ($btn['data-addparams']))
					$btnParams .= "data-addparams='{$btn['data-addparams']}' ";

				$btnText = $btn['text'];

				if (isset ($btn['subButtons']) || isset ($btn['dropdownMenu']))
					$c .= "<div class='btn-group'>";

				if ($btn['action'] === '')
					$c .= "<button type='button' class='$class $btnClass dropdown-toggle' data-toggle='dropdown'>{$icon}&nbsp;{$btnText}&nbsp;<span class='caret'></span></button>";
				else
					$c .= "<button class='btn {$btnClass}$class df2-{$btn['type']}-trigger e10-sv-tlbr-btn-{$btn['action']}' {$dataTable}data-action='{$btn['action']}' data-viewer='{$this->vid}' $btnParams>{$icon}&nbsp;{$btnText}</button>";
				if (isset ($btn['subButtons']))
				{
					foreach($btn['subButtons'] as $subbtn)
						$c .= $this->app()->ui()->actionCode($subbtn);
				}
				if (isset ($btn['dropdownMenu']))
				{
					if ($btn['action'] != '')
						$c .= '
							<button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown">
								<span class="caret"></span>
							</button>';

					$c .= '<ul class="dropdown-menu" role="menu">';

					foreach($btn['dropdownMenu'] as $subbtn)
						$c .= $this->app()->ui()->actionCode($subbtn, 1);

					$c .= '</ul>';
				}

				if (isset ($btn['subButtons']) || isset ($btn['dropdownMenu']))
					$c .= '</div>';
			}
		}
		return $c;
	}

	public function createViewerToolsCode ()
	{
		$tools = $this->createViewerTools();

		$c = '';

		if ($tools)
		{
			$c .= "<div class='dropdown pull-left'>";
			$c .= "<button class='btn btn-default dropdown-toggle' type='button' id='dropdownMenu1' data-toggle='dropdown' aria-haspopup='true' aria-expanded='true'>";
			$c .= $this->app()->ui()->icon('system/iconSettings').'&nbsp;';
			$c .= utils::es('Nástroje');
			$c .= " <span class='caret'></span>";
			$c .= "</button>";

			$c .= "<ul class='dropdown-menu dropdown-menu-right'>";
			foreach ($tools as $btn)
			{
				$c .= $this->app()->ui()->actionCode($btn, 1);
			}

			$c .= '</ul>';
			$c .= '</div>';
		}

		if (isset($this->viewerDefinition['help']))
		{
			$helpBtn = [
				'type' => 'action', 'action' => 'open-popup', 'text' => '',
				'icon' => 'system/iconHelp', 'style' => 'cancel',
				'data-popup-url' => 'https://shipard.org/'.$this->viewerDefinition['help'],
				'data-popup-width' => '0.5', 'data-popup-height' => '0.8',
				'actionClass' => 'pull-right',
				'title' => 'Nápověda'//DictSystem::text(DictSystem::diBtn_Help)
			];

			$c .= ' ' . $this->app()->ui()->actionCode($helpBtn);
		}

		return $c;
	}

	public function createBottomTabsCode ()
	{
		if (!isset ($this->bottomTabs))
			return;

		$h = "";

		$activeTab = 0;
		$idx = 0;
		forEach ($this->bottomTabs as $q)
		{
			if ($q['active'])
			{
				$activeTab = $idx;
				break;
			}
			$idx++;
		}

		$h .= "<div class='viewerBottomTabs'>";
		$h .= "<input name='bottomTab' type='hidden' value='{$this->bottomTabs[$activeTab]['id']}'/>";

		$activeTab = 0;
		$idx = 0;
		forEach ($this->bottomTabs as $q)
		{
			if ($q['active'])
			{
				$activeTab = $idx;
				break;
			}
			$idx++;
		}

		$idx = 0;
		forEach ($this->bottomTabs as $q)
		{
			$addParams = '';
			if (isset ($q['addParams']))
			{
				forEach ($q['addParams'] as $apCol => $apValue)
				{
					if ($addParams != '')
						$addParams .= '&';
					$addParams .= "__$apCol=$apValue";
				}
			}

			$txt = \E10\es ($q ['title']);
			if ($idx == $activeTab)
				$h .= "<span class='q active' data-mqid='{$q['id']}' data-addparams='$addParams'>$txt</span>";
			else
				$h .= "<span class='q' data-mqid='{$q['id']}' data-addparams='$addParams'>$txt</span>";
			$idx++;
		}
		$h .= '</div>';

		return $h;
	}


	public function createTopTabsCode ()
	{
		if (!isset ($this->topTabs))
			return;

		$h = "";

		$h .= "<div class='viewerTopTabs'>";

		$activeTab = 0;
		$idx = 0;
		forEach ($this->topTabs as $q)
		{
			if ($q['active'])
			{
				$activeTab = $idx;
				break;
			}
			$idx++;
		}
		$h .= "<input name='topTab' type='hidden' value='{$this->topTabs[$activeTab]['id']}'/>";

		$idx = 0;
		forEach ($this->topTabs as $q)
		{
			$addParams = '';
			if (isset ($q['addParams']))
			{
				forEach ($q['addParams'] as $apCol => $apValue)
				{
					if ($addParams != '')
						$addParams .= '&';
					$addParams .= "__$apCol=$apValue";
				}
			}

			$txt = \E10\es ($q ['title']);
			if ($idx == $activeTab)
				$h .= "<span class='q active' data-mqid='{$q['id']}' data-addparams='$addParams'>$txt</span>";
			else
				$h .= "<span class='q' data-mqid='{$q['id']}' data-addparams='$addParams'>$txt</span>";
			$idx++;
		}
		$h .= '</div>';

		return $h;
	}

	public function createFullWidthToolbarCode()
	{
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$c = '';

		$c .= "<div class='e10-sv-fw-toolbar' style='flex-direction: row; align-content: baseline;align-items: center; display: inline-flex; height: 3.5em; position: absolute;'>";
			$c .= "<div class='e10-sv-search'  style='display: inline-flex; padding-left: 3ex; padding-right: 1em;' id='{$this->vid}Search'>";
				$c .= "<span class='fulltext-sjhgjhag' style='display: flex; width: 17em;'>";
					$c .=	"<span class='' style='width: 2em;text-align: center;position: absolute;padding-top: 2ex; opacity: .8;'><icon class='fa fa-search' style='width: 1.1em;'></i></span>";
					$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' placeholder='".utils::es('hledat')."' value='' style='width: 100%; padding: 6px 2em; margin: 8px 0;'/>";
					$c .=	"<span class='df2-background-button df2-action-trigger df2-fulltext-clear' data-action='fulltextsearchclear' id='{$this->vid}Progress' data-run='0' style='margin-left: -2.5em; padding: 6px 2ex 3px 1ex; position:inherit; width: 2.9em; text-align: center;'><icon class='fa fa-fw fa-times' style='width: 1.1em;'></i></span>";
				$c .= '</span>';


				$c .= "<span style='display: flex; justify-content: end; flex-direction: column; padding-bottom:1em;padding-left:1em;'>";
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
				$c .= '</span>';
			$c .= '</div>';

			$c .= "<span class='btns-block' style='display: flex; margin-left:1em;' id='{$this->vid}FWTAddButtons'>";
			$c .= $this->createToolbarCode ();
			$c .= '</span>';

			$c .= "<span class='btns-block' style='display: flex; margin-left:1em;' id='{$this->vid}FWTEditButtons'>";
			$c .= '</span>';
		$c .= "</div>";

		return $c;
	}

	public function createTopMenuSearchCode ()
	{
		$h = '';
		if ($this->enableFullTextSearch || ($this->objectSubType === TableView::vsMini && $this->enableToolbar))
		{
			$h .=	"<table style='width: 100%'><tr>";

			if ($this->type === 'form')
			{
				if ($this->toolbarTitle)
					$h .= "<td class='pr1'>".$this->app()->ui()->composeTextLine($this->toolbarTitle).'</td>';

				$h .= "<td id='{$this->toolbarElementId}__Main' style='right: 2rem; max-width: 70%;'>";
				$h .= $this->createToolbarCode ();
				$h .= "<div id='{$this->toolbarElementId}' style='display: inline-block; padding-left: 1em; padding-right: 1em;'>";
				$h .= '</div>';
				$h .= '</td>';
			}

			if ($this->enableFullTextSearch)
			{
				$style = '';
				$fulltextClass = 'main';
				if (isset ($this->topParams))
				{
					$style .= ' width: 12em;';
					$fulltextClass = 'params';
				}
				if ($this->disableFullTextSearchInput)
					$h .=	"<td style='width: 2em!important; font-size: 40%;'>" .
						"<span  data-action='fulltextsearchclear' id='{$this->vid}Progress' data-run='0'>&nbsp;&nbsp;</span>" .
						'</td>';
				else
				{
					$placeholder = ($this->disableIncrementalSearch) ? 'hledat ⏎' : 'hledat';

					$h .= "<td class='fulltext $fulltextClass' style='$style'>" .
							"<span class='df2-background-button df2-action-trigger df2-fulltext-clear' data-action='fulltextsearchclear' id='{$this->vid}Progress' data-run='0'>".$this->app()->ui()->icon('system/actionInputClear')."</span>";
					$h .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' autocomplete='off' placeholder='".utils::es($placeholder)."' value=''";
					if ($this->disableIncrementalSearch)
						$h .= " data-onenter='1'";
					$h .= '/></td>';
				}
				if (isset ($this->topParams))
				{
					$h .= "<td style='padding-left: 1ex;'>".$this->topParams->createCode().'</td>';
				}

				if (isset ($this->gridStruct) && !$this->inlineSourceElement)
				{
					$h .= "<td style='vertical-align: middle; text-align: right; width: 90px;'>";

					$h .= "<div class='btn-group pull-right'>";
					$h .= "<button class='btn btn-large btn-default df2-action-trigger' data-action='printviewer' data-viewer='{$this->vid}' data-format='pdf'>".$this->app()->ui()->icon('system/actionPrint')."</button>";

					$h .= "<button type='button' class='btn btn-default dropdown-toggle'' data-toggle='dropdown'><span class='caret'></span></button>";
					$h .= '<ul class="dropdown-menu" role="menu">';

					$h .= "<li><a class='df2-action-trigger' data-action='printviewer' data-viewer='{$this->vid}' data-format='csv'>".$this->app()->ui()->icon('system/actionSave')." ".utils::es('Uložit jako CSV soubor')."</a></li>";
					//$h .= "<li><a class='df2-action-trigger' data-action='printviewer' data-viewer='{$this->vid}' data-format='xls'><i class='fa fa-file-excel-o'></i> ".utils::es('Uložit jako Excel')."</a></li>";

					$h .= '</ul>';
					$h .= '</div>';

					$h .= '</td>';
				}
			}
			if ($this->objectSubType === TableView::vsMini && $this->enableToolbar)
				$h .= "<td><span class='e10-sv-search-toolbar'>" . $this->createToolbarCode () . '</span></td>';

			$h .= '</tr></table>';
		}

		$h .= $this->createTopTabsCode();

		if (isset ($this->mainQueries))
		{
			$h .= "<div class='viewerQuerySelect'>";
			$h .= "<input name='mainQuery' type='hidden' value='{$this->mainQueries[0]['id']}'/>";
			$idx = 0;

			$code = array ('left' => '', 'right' => '');

			forEach ($this->mainQueries as $q)
			{
				$txt = \E10\es ($q ['title']);

				if ($idx === 0 || (isset($q['side']) && $q['side'] === 'left'))
				{
					if ($idx == 0)
						$code['left'] .= "<span class='q active' data-mqid='{$q['id']}'>$txt</span>";
					else
						$code['left'] .= "<span class='q' style='margin-left: 1em;' data-mqid='{$q['id']}'>$txt</span>";
				}
				else
				{
					$code['right'] .= "<span class='q' data-mqid='{$q['id']}'>$txt</span>";
				}
				$idx++;
			}

			if ($code['left'] !== '')
				$h .= $code['left'];
			if ($code['right'] !== '')
				$h .= "<span style='float: right'>" . $code['right'] . '</span>';

			$h .= '</div>';
		}

		if ($h !== '')
			$h = "<div class='e10-sv-search' id='{$this->vid}Search'>".$h.'</div>';

		return $h;
	}

	public function defaultQuery (&$q) {}

	function decorateRow (&$item){}

	function renderPane (&$item) {}

	public function formId ()
	{
		return '';
	}

	public function init ()
	{
		$this->rowsFirst = $this->rowsPageNumber * $this->rowsPageSize;
		$this->rowsLoadNext = 0;

		if ($this->table->app()->testGetParam ('sensorType') != '')
			$this->addFromSensor();

		if (isset($this->topParams))
		{
			$this->topParams->inputClass = 'e10-viewer-param';
			$this->topParamsValues = $this->topParams->detectValues();
		}

		if ($this->mode === 'panes')
			$this->objectData ['htmlItems'] = [];
		else
			$this->objectData ['htmlItems'] = '';
	}

	public function report ()
	{
		if ($this->panels === FALSE)
			return '';

		$activePanelId = $this->panels[0]['id'];
		$panel = $this->panel ($activePanelId);
		$panel->createContent();
		$c = $panel->createCode();

		return $c;
	}

	public function setPaneMode ($columns = 0, $columnWidth = 30, $columnsInfo = NULL)
	{
		$this->paneMode = TRUE;
		$this->mode = 'panes';
		$this->panesColumns = $columns;
		$this->panesColumnWidth = $columnWidth;
		//$this->objectSubType = TableView::vsDetail;
		$this->htmlRowElement = 'div';
		$this->htmlRowsElement = 'div';
		$this->contentRenderer = new ContentRenderer($this->app());

		$this->contentRenderer->srcObjectType = 'viewer';
		$this->contentRenderer->srcObjectId = $this->vid;

		$this->objectData['viewer']['panesColumns'] = $this->panesColumns;
		$this->objectData['viewer']['panesColumnWidth'] = $this->panesColumnWidth;
		$this->objectData['viewer']['columnsInfo'] = $columnsInfo;
	}

	public function tableId ()
	{
		return $this->table->tableId ();
	}

	public function setName ($name)
	{
		$this->objectData ['name'] = $name;
	}

	public function name ()
	{
		return $this->objectData ['name'];
	}

	public function rows ()
	{
		if ($this->mode === 'panes')
			return '';
		return $this->objectData ['htmlItems'];
	}

	function queryParam ($paramName)
	{
		if (isset ($this->queryParams [$paramName]))
			return $this->queryParams [$paramName];

		if ($this->app()->testGetParam($paramName) != '')
			return $this->app()->testGetParam($paramName);

		if ($this->app()->testPostParam ($paramName) != '')
			return $this->app()->testPostParam ($paramName);

		return FALSE;
	}

	public function queryParams ()
  {
		if (isset ($this->queryParams))
			return http_build_query ($this->queryParams);
		return '';
	}

	public function rowHtml ($listItem)
	{
		if (isset ($listItem ['groupName']))
		{
			$codeLine = '<'.$this->htmlRowElement." class='g'>" . $this->app()->ui()->renderTextLine($listItem ['groupName']) . '</'.$this->htmlRowElement.'>';
			return $codeLine;
		}

		$class = "r";
		//if (isset ($listItem ['txt']))
		//	$class .= " t";
		if (isset ($listItem['class']))
			$class .= " {$listItem['class']}";
		if ($this->htmlRowElementClass !== '')
			$class .= ' '.$this->htmlRowElementClass;

		if (isset($listItem ['pk']))
			$codeLine = '<'.$this->htmlRowElement." class='$class' data-pk='{$listItem ['pk']}'";
		else
		if (isset($listItem ['ndx']))
			$codeLine = '<'.$this->htmlRowElement." class='$class' data-pk='{$listItem ['ndx']}'";
		else
			$codeLine = '<'.$this->htmlRowElement." class='$class'";

		if (isset ($listItem ['data-cc']))
		{
			foreach($listItem ['data-cc'] as $datai => $datav)
				$codeLine .= " data-cc-{$datai}='{$datai}:".base64_encode($datav)."'";
		}
		if (isset ($listItem ['table']))
			$codeLine .= " data-table='{$listItem ['table']}'";
		if (isset ($listItem ['data-url-download']))
		{
				$codeLine .= " data-url-download='".Utils::es($listItem ['data-url-download'])."'";
				$codeLine .= " data-action='open-link'";
		}

		$codeLine .= ">";

		$codeLine .= $this->rowHtmlContent ($listItem);
		//$codeLine .= "<div class='vrd'></div>";
		$codeLine .= '</'.$this->htmlRowElement.'>';

		return $codeLine;
	} // rowHtml


	public function rowHtmlContent ($listItem)
	{
		if ($this->paneMode)
			return $this->contentRenderer->createCodeTiles_Panes ([$listItem ['pane']], $this->app());
		if ($this->mobile)
			return $this->rowHtmlContentMobile ($listItem);

		$codeLine = '';

		if (isset($listItem['pane']))
		{
			if (!$this->contentRenderer)
				$this->contentRenderer = new ContentRenderer($this->app());
			return $this->contentRenderer->createCodeTiles_Panes ([$listItem ['pane']], $this->app());
		}

		$codeLine .=
		"<table class='df2-viewer-line-content'>" .
		"<tr>";

		$itemLevel = 0;
		if (isset ($listItem ['level']))
			$itemLevel = intval ($listItem ['level']);

		$codeLine .= "<td class='df2-list-item-recnum df2-list-item-level$itemLevel'>";
		if ($this->checkboxes)
			$codeLine .= "<span><input type='checkbox' name='vchbx_{$listItem['pk']}' value='{$listItem ['pk']}'/></span>";
		if ($this->onlyOneRec === 0)
			$codeLine .= "<span class='recNum'>".strval ($this->lineRowNumber + $this->rowsFirst)."</span>";
		else
			$codeLine .= '*';
		$codeLine .= '</td>';

		if ((isset ($listItem ['icon'])) && ($listItem ['icon'] != ''))
		{
			$icon = $this->app()->ui()->icon($listItem ['icon'], $listItem['!error'] ?? '', 'span');
			$codeLine .= "<td class='df2-list-item-icon'>{$icon}</span></td>";
		}
		else
		if (isset ($listItem ['image']))
		{
			$codeLine .= "<td class='df2-list-item-image'>";
			if ($listItem ['image'] !== '')
				$codeLine .= "<img src='{$listItem ['image']}'>";
			$codeLine .= '</td>';
		}
		elseif ((isset ($listItem ['emoji'])))
		{
			$codeLine .= "<td class='df2-list-item-emoji'><span>{$listItem ['emoji']}</span></td>";
		}
		elseif ((isset ($listItem ['svgIcon'])) && ($listItem ['svgIcon'] != ''))
		{
			$codeLine .= "<td class='df2-list-item-icon'><img style='width:100%;' src='{$listItem ['svgIcon']}'></td>";
		}

		if (isset($listItem['code']))
		{
			$codeLine .= '<td>'.$listItem['code'].'</td>';
			$codeLine .= "</tr>";
			$codeLine .= "</table>";

			return $codeLine;
		}

		$t1 = (isset($listItem ['t1'])) ? $this->app()->ui()->composeTextLine ($listItem ['t1']) : '';
		if ($t1 === '' && isset($listItem ['tt']))
			$t1 = $this->app()->ui()->composeTextLine ($listItem ['tt']);
		if ($t1 === '')
			$t1 = "&nbsp;";

		$t2 = isset ($listItem ['t2']) ? $this->app()->ui()->composeTextLine ($listItem ['t2']) : '&nbsp;';
		if (isset ($listItem ['i2']))
			$t2 .= "<span class='i2'>" . $this->app()->ui()->composeTextLine ($listItem['i2']) . '</span>';

		$codeLine .= "<td class='df2-list-item-t'>";

		if (isset($listItem ['tt']))
		{
			if (isset ($listItem ['i1']))
				$codeLine .= "<span class='ml1 df2-list-item-i1'>" . $this->app()->ui()->composeTextLine ($listItem['i1']) . '</span>';
			$codeLine .= "<div class='df2-list-item-tt'>$t1</div>";
		}
		elseif (isset ($listItem ['t1']) || (!isset ($listItem ['t1']) && !isset ($listItem ['txt'])))
		{
			$codeLine .= "<div class='df2-list-item-t1'>$t1</div>";
			if (isset ($listItem ['i1']))
				$codeLine .= "<span class='df2-list-item-i1'>" . $this->app()->ui()->composeTextLine ($listItem['i1']) . '</span>';
		}

		if (isset ($listItem ['t2']) || (!isset ($listItem ['t2']) && !isset ($listItem ['txt'])))
			$codeLine .= "<div class='df2-list-item-t2'>$t2</div>";

		if (isset($listItem ['t3']))
			$codeLine .= "<div class='df2-list-item-t3'>" . $this->app()->ui()->composeTextLine ($listItem['t3']) . '</div>';

		if (isset ($listItem ['txt']))
			$codeLine .= "<div class='pageText'>{$listItem ['txt']}</div>";

		if (isset($listItem['content']))
		{
			if (!$this->contentRenderer)
				$this->contentRenderer = new ContentRenderer($this->app());

			$this->contentRenderer->setContent ($listItem ['content']);
			$codeLine .= "<div class='content'>".$this->contentRenderer->createCode ()."</div>";;
		}

		$codeLine .= "</td>";

		if (isset ($listItem ['rightImage']))
		{
			$imgCellClass = $listItem ['rightImage']['cellClass'] ?? 'df2-list-item-image';

			$params = '';
			if (isset($listItem['rightImage']['image']))
			{
				$params = " data-action='open-popup' data-popup-id='XYZ' data-popup-url='{$listItem['rightImage']['image']}' with-shift='tab'";
				$imgCellClass .= ' df2-action-trigger';
			}

			$codeLine .= "<td class='$imgCellClass' $params>";
			if (isset($listItem ['rightImage']['thumb']))
				$codeLine .= "<img src='{$listItem ['rightImage']['thumb']}' style='max-width: 100%; margin-left: 6px; padding: 2px;'>";
			$codeLine .= '</td>';
		}

		$codeLine .= "</tr>";
		$codeLine .= "</table>";

		return $codeLine;
	} // rowHtmlContent


	public function rowHtmlContentMobile ($listItem)
	{

		$codeLine = '';

		$itemLevel = 0;
		if (isset ($listItem ['level']))
			$itemLevel = intval ($listItem ['level']);

		if ((isset ($listItem ['icon'])) && ($listItem ['icon'] != ''))
		{
			$icon = $this->app()->ui()->icon($listItem ['icon'], '', 'span');
			$codeLine .= "<span class='icon'>{$icon}</span></span>";
		}
		else
		if ((isset ($listItem ['image'])) && ($listItem ['image'] !== ''))
		{
			$codeLine .= "<span class='df2-list-item-image'><img src='{$listItem ['image']}'></span>";
		}

		$t1 = (isset($listItem ['t1'])) ? $this->app()->ui()->composeTextLine ($listItem ['t1']) : '';
		if ($t1 === '')
			$t1 = "&nbsp;";

		$t2 = isset ($listItem ['t2']) ? $this->app()->ui()->composeTextLine ($listItem ['t2']) : '&nbsp;';
		if (isset ($listItem ['i2']))
			$t2 .= "<span class='i2'>" . $this->app()->ui()->composeTextLine ($listItem['i2']) . '</span>';

		$codeLine .= "<div class='txt'>";

		if (isset ($listItem ['t1']) || (!isset ($listItem ['t1']) && !isset ($listItem ['txt'])))
		{
			$codeLine .= "<span class='t1'>$t1</span>";
			if (isset ($listItem ['i1']))
				$codeLine .= "<span class='i1'>" . $this->app()->ui()->composeTextLine ($listItem['i1']) . '</span>';
		}

		if (isset ($listItem ['t2']) || (!isset ($listItem ['t2']) && !isset ($listItem ['txt'])))
			$codeLine .= "<div class='t2'>$t2</div>";

		if (isset($listItem ['t3']))
			$codeLine .= "<div class='t3'>" . $this->app()->ui()->composeTextLine ($listItem['t3']) . '</div>';

//		if (isset ($listItem ['txt']))
//			$codeLine .= "<div class='txt'>{$listItem ['txt']}</div>";

		$codeLine .= "</div>";

		return $codeLine;
	}

	public function selectRows ()
	{
		$q = "SELECT * FROM " . $this->table->sqlName ();

		$q .= " ORDER BY ndx";
		$q .= $this->sqlLimit ();

		$this->runQuery ($q, '');
	}

	public function selectRows2 ()
	{
	}

	public function createDetails ()
	{
		$details = array ();
		if ($this->viewerDefinition && isset ($this->viewerDefinition ['details']))
			$details = $this->viewerDefinition ['details'];

		$globalDetails = $this->table->app()->cfgItem('e10.global.viewerDetails', FALSE);
		if ($globalDetails)
		{
			foreach ($globalDetails as $gdid => $gd)
			{
				if (!$this->globalDetailEnabled($gdid, $gd))
					continue;

				if (count ($details) === 0)
					$details ['default'] = [
						'title' => 'Detail', 'icon' => 'system/detailDetail', 'order' => 1,
						'class' => $this->viewerDefinition ['detail']
					];

				$details [$gdid] = $gd;
			}
		}

		return \E10\sortByOneKey($details, 'order', true);
	}

	function globalDetailEnabled($detailId, $detailCfg)
	{
		return TRUE;
	}

	public function createDetailsCode ()
	{
		$c = "";

		$details = $this->createDetails ();
		if (count($details))
		{
			$firstClass = " class='active'";

			if ($this->paneMode)
			{
				$c .= "<div class='e10-mv-ld-tabs' style='display: inline-block; width:100%;'>
				<ul class='nav nav-pills e10-mv-ld-tabs' data-viewer='{$this->vid}'>\n";
				foreach ($details as $id => $detail)
				{
					$c .= "<li data-detail='$id'$firstClass><a href='#'>";
					$c .= $this->app()->ui()->icon($detail['icon']);
					$c .= \E10\es ($detail['title']);
					$c .= '</a></li>';
					$firstClass = '';
				}

				$c .= '</ul></div>';
			}
			else
			{
				$c .= "<ul class='df2-detail-menu viewer' id='mainViewerDetailMenu' style='display: none;' data-viewer='{$this->vid}'>\n";
				foreach ($details as $id => $detail)
				{
					$c .= "<li data-detail='$id'$firstClass>";
					$c .= $this->app()->ui()->icon ($detail['icon'], '', 'div');
					$c .= \E10\es ($detail['title']);
					$c .= '</li>';
					$firstClass = "";
				}
			}
			$c .= "</ul>\n";
		}

		return $c;
	}

	public function setPanels ($panels)
	{
		if (is_array($panels))
		{
			$this->panels = $panels;
			return;
		}

		$this->panels = array ();
		if ($panels & TableView::sptQuery)
			$this->panels [] = array ('id' => 'qry', 'title' => 'Hledání');
		if ($panels & TableView::sptReview)
			$this->panels [] = array ('id' => 'review', 'title' => 'Přehled');
		if ($panels & TableView::sptHelp)
			$this->panels [] = array ('id' => 'help', 'title' => 'Nápověda');
	}

	public function createPanelsCode ()
	{
		if ($this->panels === FALSE)
			return '';
		$c = '';

		$title = isset ($this->viewerDefinition['title']) ? $this->viewerDefinition['title'] : $this->table->tableName ();

		$c .= "<div class='viewerPanelsTabs'>";
		$c .= "<input name='panelTab' type='hidden' value='{$this->panels[0]['id']}'/>";
		if ($title === '')
		{
			$c .= "<span>";
		}
		else
		{
			$c .= "<span class='l'>". \E10\es ($title) . '</span>';
			$c .= "<span style='float: right; padding-right: 1ex;'>";
		}
		$activeTabId = $this->panels[0]['id'];
		forEach ($this->panels as $q)
		{
			$txt = \E10\es ($q ['title']);
			if ($q['id'] === $activeTabId)
				$c .= "<span class='q active' data-id='{$q['id']}'>$txt</span>";
			else
				$c .= "<span class='q' data-id='{$q['id']}'>$txt</span>";
		}
		$c .= '</span>';
		$c .= '</div>';
		return $c;
	}


	public function panel ($panelId)
	{
		$panel = new TableViewPanel ($this, $panelId);
		return $panel;
	}

	public function createPanelContent (TableViewPanel $panel)
	{
		switch ($panel->panelId)
		{
			case 'left': $this->createPanelContentLeft ($panel); break;
			case 'right': $this->createPanelContentRight ($panel); break;
			case 'qry': $this->createPanelContentQry ($panel); break;
			case 'review': $this->createPanelContentReview ($panel); break;
			case 'help': $this->createPanelContentHelp ($panel); break;
		}
	}

	public function createPanelContentQry (TableViewPanel $panel) {}
	public function createPanelContentReview (TableViewPanel $panel) {}
	public function createPanelContentLeft (TableViewPanel $panel) {}
	public function createPanelContentRight (TableViewPanel $panel) {}

	public function createPanelContentHelp (TableViewPanel $panel)
	{
		$panel->addContent(array ('type' => 'text', 'subtype' => 'code', 'text' => 'tady bude nápověda'));
	}

	public function qryPanelAddCheckBoxes ($panel, &$qry, $enum, $queryId, $queryTitle, $nameKey = FALSE)
	{
		if ($enum !== FALSE && count($enum) !== 0)
		{
			$chbxs = [];
			forEach ($enum as $id => $name)
			{
				if ($nameKey !== FALSE)
					$chbxs[$id] = ['title' => $name[$nameKey], 'id' => $id];
				elseif (is_array($name))
					$chbxs[$id] = $name;
				else
					$chbxs[$id] = ['title' => $name, 'id' => $id];
			}

			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.'.$queryId, ['items' => $chbxs]);
			$qry[] = ['id' => $id, 'style' => 'params', 'title' => $queryTitle, 'params' => $params];
		}
	}

	public function sqlLimit ()
	{
		if ($this->onlyOneRec !== 0)
			return '';

		return " LIMIT {$this->rowsFirst}, " . strval($this->rowsPageSize + 1);
	}

	public function header ()
	{
		if ($this->header != "")
			return $this->header;
		return $this->table->tableName ();
	}


	protected function queryValues ()
	{
		$qv = array ();
		forEach ($_POST as $qryId => $qryValue)
		{
			$parts = explode ('_', $qryId);
			if ($parts[0] === 'query')
			{
				if (isset($parts[3]))
					$qv[$parts[1]][$parts[2]][$parts[3]] = $qryValue;
				else
					$qv[$parts[1]][$parts[2]] = $qryValue;
			}
		}
		if (count($qv) === 0)
		{
			forEach ($_GET as $qryId => $qryValue)
			{
				$parts = explode('_', $qryId);
				if ($parts[0] === 'query')
				{
					if (isset($parts[3]))
						$qv[$parts[1]][$parts[2]][$parts[3]] = $qryValue;
					else
						$qv[$parts[1]][$parts[2]] = $qryValue;
				}
			}
		}
		return $qv;
	}

	public function renderRow ($item)
	{
		return $item;
	}

	public function saveRow ($item)
	{
		return $this->renderRow($item);
	}

	public function saveRowCode ($format, $listItem)
	{
		return $this->gridTableRenderer->renderRow ($listItem, 0, 'td');
	}

	public function addFromSensor ()
	{
		$rec = array ('_addFromSensor' => array('type' => $this->table->app()->testGetParam ('sensorType'), 'value' => $this->table->app()->testGetParam ('sensorValue')));
		$this->table->checkNewRec ($rec);
		$this->table->checkBeforeSave ($rec);
		if (!isset ($rec['_addFromSensor']))
			$this->onlyOneRec = $this->table->dbInsertRec ($rec);
		else
			$this->onlyOneRec = -1;
	}

	public function renderViewerData ($format)
	{
		if ($this->saveAs !== '')
		{
			$this->saveViewerData();
			return;
		}

		if ($this->paneMode && $this->rowsPageNumber === 0)
		{
			$zrc = $this->zeroRowCode();
			if ($zrc !== '')
				$this->addHtmlItem($zrc);
		}

		$this->rowsLoadNext = 0;
		$this->lineRowNumber = 0;
		foreach ($this->queryRows as $item)
		{
			if ($this->lineRowNumber == $this->rowsPageSize)
			{
				$this->rowsLoadNext = 1;
				break;
			}

			$this->lineRowNumber++;

			if ($this->paneMode)
				$listItem = (array)$item;
			else
				$listItem = $this->renderRow ((array)$item);

			$this->setItemDocState($this->table, $item, $listItem);

			$this->addListItem ($listItem);
		}

		$this->selectRows2 ();

		unset ($item);
		$this->lineRowNumber = 0;

		if ($this->objectData ['htmlItems'] === '' && $this->rowsPageNumber === 0)
		{
			$this->addHtmlItem($this->zeroRowCode());
		}

		if ($this->paneMode && $this->rowsPageNumber === 0)
		{
			$this->createStaticContent();
		}

		if ($this->countRows === 0 && $this->rowsPageNumber === 0)
			$this->checkBlankResult ();

		forEach ($this->objectData ['dataItems'] as &$item)
		{
			//if ($this->lineRowNumber == $this->rowsPageSize)
			//	break;

			if (!isset ($item ['groupName']))
				$this->lineRowNumber++;

			if ($this->paneMode)
				$this->renderPane($item);
			else
				$this->decorateRow ($item);
			$this->addListItem2 ($item);
		}

		if ($this->onlyOneRec === 0)
			$this->addEndMark ();

		$this->objectData ['table'] = $this->table->tableId ();
		$this->objectData ['viewerId'] = $this->viewId ();

		$this->objectData ['linesWidth'] = $this->linesWidth;
		if (isset ($this->mainQueries))
			$this->objectData ['mainQueries'] = $this->mainQueries;
		$this->objectData ['bottomTabs'] = $this->bottomTabs;

		$this->objectData ['rowsFirst'] = $this->rowsFirst;
		$this->objectData ['rowsCount'] = $this->countRows;
		$this->objectData ['rowsPageSize'] = $this->rowsPageSize;
		if ($this->refreshRowsPageNumber)
			$this->objectData ['rowsPageNumber'] = $this->refreshRowsPageNumber;
		else
			$this->objectData ['rowsPageNumber'] = $this->rowsPageNumber;
		$this->objectData ['rowsLoadNext'] = $this->rowsLoadNext;
	}

	function setItemDocState($rowTable, $item, &$listItem)
	{
		$this->docState = $rowTable->getDocumentState ($item);
		if ($this->paneMode)
		{
			if ($this->docState)
			{
				$docStateClass = $rowTable->getDocumentStateInfo ($this->docState ['states'], $item, 'styleClass');
				if ($docStateClass)
					$listItem ['docStateClass'] = $docStateClass;
			}
		}
		else
		{
			if ($this->docState)
			{
				$docStateClass = $rowTable->getDocumentStateInfo ($this->docState ['states'], $item, 'styleClass');
				if ($docStateClass)
				{
					if (isset ($listItem ['class']))
						$listItem ['class'] .= ' '.$docStateClass;
					else
						$listItem ['class'] = $docStateClass;
				}
			}
		}
	}

	public function saveViewerData ()
	{
		$saveViewer = new \lib\core\SaveViewer($this->app());
		$saveViewer->setViewer($this);
		return $saveViewer->saveViewerData();
	}

	public function runQuery ($sql)
	{
		if ($sql === NULL)
		{
			$this->queryRows = array();
			$this->ok = 1;
			return;
		}
		$args = func_get_args();
		$this->queryRows = $this->db->query ($args);
		$this->ok = 1;

		if ($this->fullWidthToolbar)
		{
			$this->objectData ['htmlCodeFullWidthToolbarAddButtons'] = $this->createToolbarCode();
		}
	}

	public function setBottomTabs ($tabs)
	{
		$this->bottomTabs = $tabs;
	}

	public function setGrid (array $gridStruct)
	{
		$this->gridStruct = $gridStruct;
		$this->gridTableHeaderCode = '<thead><tr>';

		if ($this->gridEditable)
			$this->gridTableHeaderCode .= '<th class="e10-icon"></th>';

		foreach ($this->gridStruct as $cn => $ch)
		{
			$this->gridColClasses [$cn] = '';
			if ($ch === '')
				$ct = '';
			else
			if ($ch [0] == '+')
			{
				$ct = substr ($ch, 1);
				$this->gridColClasses [$cn] = 'number';
			}
			else if ($ch [0] == '*')
			{
				$ct = substr ($ch, 1);
				$this->gridColClasses [$cn] = 'e10-icon';
			}
			else if ($ch [0] == ' ')
			{
				$ct = substr ($ch, 1);
				$this->gridColClasses [$cn] = 'number';
			}
			else if ($ch [0] == '_')
			{
				$ct = substr ($ch, 1);
				$this->gridColClasses [$cn] = 'nowrap';
			}
			else if ($ch == '#')
			{
				$ct = $ch;
				$this->gridColClasses [$cn] = 'number';
			}
			else
				$ct = $ch;
			$this->gridTableHeaderCode .= "<th class='{$this->gridColClasses [$cn]}'>".$this->app()->ui()->composeTextLine($ct).'</th>';
		}
		$this->gridTableHeaderCode .= '</tr></thead>';
	}

	public function setInfo ($infoId, $p1, $p2 = '')
	{
		if ($p2 === '')
			$this->info [$infoId] = $p1;
		else
			$this->info [$infoId][$p1] = $p2;
	}

	public function setMainQueries ($queries = NULL)
	{
		if ($queries === NULL)
			$this->mainQueries = [
				['id' => 'active', 'title' => 'Aktivní', 'icon' => 'system/filterActive'],
				['id' => 'archive', 'title' => 'Archív', 'icon' => 'system/filterArchive'],
				['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'],
				['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash']
			];
		else
			$this->mainQueries = $queries;
	}

	public function setTopTabs ($tabs)
	{
		$this->topTabs = $tabs;
	}

	public function mainQueryId ()
	{
		if (isset ($_POST ['mainQuery']))
			return $_POST ['mainQuery'];
		if (isset($this->mainQueries[0]['id']))
			return $this->mainQueries[0]['id'];
		return '';
	}

	public function queryMain (&$q, $tablePrefix = '', $order = NULL, $forceArchive = FALSE)
	{
		$mainQuery = $this->mainQueryId ();

		// -- active
		if ($mainQuery === 'active' || $mainQuery === '')
		{
			if ($forceArchive)
				array_push($q, " AND {$tablePrefix}[docStateMain] != 4");
			else
				array_push($q, " AND {$tablePrefix}[docStateMain] < 4");
		}

		// -- archive
		if ($mainQuery === 'archive')
			array_push ($q, " AND {$tablePrefix}[docStateMain] = 5");

		// trash
		if ($mainQuery === 'trash')
			array_push ($q, " AND {$tablePrefix}[docStateMain] = 4");

		// trash
		if ($mainQuery === 'invalid')
			array_push ($q, " AND {$tablePrefix}[docStateMain] = 6");

		if ($order !== NULL)
		{
			if ($mainQuery === 'all')
				array_push ($q, ' ORDER BY ', implode(', ', $order), $this->sqlLimit ());
			else
				array_push ($q, " ORDER BY {$tablePrefix}[docStateMain], ", implode(', ', $order), $this->sqlLimit ());
		}
	}

	function createStaticContent() {}
	public function fullTextSearch () {return $this->fullTextSearch;}
	public function viewId () {return $this->viewId;}
	public function zeroRowCode () {return '';}

}

