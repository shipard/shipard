<?php

namespace Shipard\UI\ng\renderers;
use \Shipard\UI\ng\renderers\Renderer;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;


/**
 * class TableViewRenderer
 */
class TableViewRenderer extends Renderer
{
  var ?\Shipard\Viewer\TableView $viewer = NULL;

  public function setViewer (\Shipard\Viewer\TableView $viewer)
  {
    $this->viewer = $viewer;
  }

  public function objectId()
  {
    return $this->viewer->vid;
  }


  function createViewerCode ()
	{
    $fullCode = 1;
		//if ($this->mobile)
		//	return $this->createViewerCodeMobile ($format, $fullCode, $jsinit);

		$this->viewer->toolbarElementId = 'e10-tm-detailbuttonbox';
		if ($this->viewer->objectSubType == TableView::vsDetail)
			$this->viewer->toolbarElementId = 'mainBrowserRightBarButtonsEdit';
		if ($this->viewer->fullWidthToolbar)
			$this->viewer->toolbarElementId = $this->viewer->vid.'FWTEditButtons';
		elseif ($this->viewer->paneMode)
			$this->viewer->toolbarElementId = 'NONE';
		elseif ($this->viewer->type === 'inline' || $this->viewer->type === 'form')
			$this->viewer->toolbarElementId = $this->viewer->vid.'_Toolbar';

		$detailCode = '';

		$detailCode .= "<div style='display: none;' class='e10-mv-ld' id='{$this->viewer->vid}Details'>";

		//if ($this->viewer->paneMode && $this->viewer->objectSubType !== TableView::vsDetail)
		//	$detailCode .= $this->viewer->createDetailsCode ();

		$detailCode .=
			"<div class='e10-mv-ld-header'></div>" .
			"<div class='e10-mv-ld-content' data-e10mxw='1'></div>" .
		"</div>";

		$reportCode =
		"<div style='display: none;' class='e10-mv-lr' id='{$this->viewer->vid}Report'>" .
				//$this->createPanelsCode () .
				"<div class='e10-mv-lr-content'>{$this->viewer->report ()}</div>" .
				"</div>
		</div>";

		$viewerClass = "df2-viewer e10-viewer-{$this->viewer->table->tableId()} e10-{$this->viewer->objectSubType}";
		$viewerClass .= ' e10-viewer-type-'.$this->viewer->type;
		if ($this->viewer->objectSubType == TableView::vsDetail && !$this->viewer->enableDetailSearch)
			$viewerClass .= ' e10-viewer-nosearch';
		if (!isset ($this->viewer->bottomTabs))
			$viewerClass .= ' e10-viewer-noBottomTabs';

		if (isset ($this->viewer->topParams))
			$viewerClass .= ' e10-viewer-maingrid';

		if ($this->viewer->paneMode)
			$viewerClass .= ' e10-viewer-pane';
		else
			$viewerClass .= ' e10-viewer-classic';

		if ($this->viewer->usePanelLeft)
			$viewerClass .= ' e10-viewer-panel-left';

		if ($this->viewer->fullWidthToolbar)
			$viewerClass .= ' e10-viewer-fw-toolbar';

		if (isset ($this->viewer->classes))
			$viewerClass .= ' '.implode (' ', $this->viewer->classes);

		$c = "<data-viewer style='display: none;' ";
    $c .= "data-object-type='data-viewer'";
    $c .= "class='$viewerClass' id='{$this->viewer->vid}' data-viewer='{$this->viewer->vid}' data-object='viewer'";
		$c .=	"data-viewertype='{$this->viewer->objectSubType}' data-table='" . $this->viewer->table->tableId () . "' data-viewer-view-id='" . $this->viewer->viewId () . "' ";
		$c .= "data-addparams='{$this->viewer->addParams ()}' data-queryparams='{$this->viewer->queryParams()}' data-lineswidth='{$this->viewer->linesWidth}' ";
		$c .= "data-toolbar='{$this->viewer->toolbarElementId}' data-mode='{$this->viewer->mode}' data-type='{$this->viewer->type}'";

		if ($this->viewer->inlineSourceElement)
		{
			foreach ($this->viewer->inlineSourceElement as $key => $value)
				$c .= " data-inline-source-element-{$key}='" . Utils::es($value) . "'";
		}

		//if ($this->mode === 'panes')
		//	$c .= " data-panes-columns='{$this->panesColumns}'";

		if ($this->app()->remote !== '')
			$c .= " data-remote='".$this->app()->remote."'";

		if ($this->viewer->comboSettings)
		{
			forEach ($this->viewer->comboSettings as $csKey => $csValue)
				$c .= ' data-combo-'.$csKey."='".$csValue."'";
		}

		if ($fullCode)
		{
			$flowParams = $this->viewer->flowParams();
			if ($flowParams)
				$c .= " data-flow-params='".base64_encode(json_encode($flowParams))."'";
		}

		$c .= ">";

		// -- toolbar?
		if ($this->viewer->type === 'inline')
		{
			$c .= "<div id='{$this->viewer->toolbarElementId}__Main' style='width:70%; background-color: red;'>A";
			$c .= $this->viewer->createToolbarCode ();
			$c .= "<div id='{$this->viewer->toolbarElementId}' style='display: inline-block; padding-left: 1em;'>";
			$c .= '</div>';
			$c .= '</div>';
		}

		$c .= $this->createViewerBodyCode();

		if ($this->viewer->objectSubType == TableView::vsMain)
		{
			$c .= $detailCode . $reportCode;
		}
		$c .= "</data-viewer>";

//		if ($jsinit)
//			$c .= "<script type='text/javascript'>jQuery(function tst (){initViewer ('$this->viewer->vid')});</script>";

    return $c;
	}

  function createViewerBodyCode ()
	{
		$c = '';

		$listClass = ' '.$this->viewer->objectSubType;
		if ($this->viewer->comboSettings)
			$listClass .= ' e10-viewer-combo';
		if ($this->viewer->htmlRowsElementClass !== '')
			$listClass .= ' '.$this->viewer->htmlRowsElementClass;

		$c .= $this->viewer->createLeftPanelCode();

		if ($this->viewer->fullWidthToolbar)
			$c .= $this->viewer->createFullWidthToolbarCode();

		$c .= "<div class='body'>";

		if (!$this->viewer->fullWidthToolbar)
			$c .= $this->viewer->createTopMenuSearchCode ();

      $c .= "<div class='rows'>";


		$c .= "<{$this->viewer->htmlRowsElement} style='z-index: 499;' class='rows df2-viewer-list e10-viewer-list$listClass' id='{$this->viewer->vid}Items' data-rowspagenumber='0'".
					"data-viewer='{$this->viewer->vid}' data-rowelement='{$this->viewer->htmlRowElement}'>";

		$c .= $this->viewer->rows ();



		$c .= "</{$this->viewer->htmlRowsElement}>";

    $c .= "</div>";


		$c .= $this->viewer->createBottomTabsCode ();
		$c .= '</div>';

		$c .= $this->viewer->createRightPanelCode();

		return $c;
	}


  public function render()
  {
    $this->renderedData['hcFull'] = $this->createViewerCode();
  }
}
