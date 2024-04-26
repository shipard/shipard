<?php

namespace Shipard\UI\ng\renderers;
use \Shipard\UI\ng\renderers\Renderer;
use \Shipard\Viewer\TableView;
use \Shipard\Utils\Utils;
use \Shipard\UI\Core\ContentRenderer;

/**
 * class TableViewRenderer
 */
class TableViewRenderer extends Renderer
{
  var ?\Shipard\Viewer\TableView $viewer = NULL;
	var $isModal = 0;

  public function setViewer (\Shipard\Viewer\TableView $viewer)
  {
    $this->viewer = $viewer;
    $this->viewer->ngRenderer = $this;
  }

  public function objectId()
  {
    return $this->viewer->vid;
  }

  function createViewerCode ()
	{
    $fullCode = 1;

    /*
		$this->viewer->toolbarElementId = 'e10-tm-detailbuttonbox';
		if ($this->viewer->objectSubType == TableView::vsDetail)
			$this->viewer->toolbarElementId = 'mainBrowserRightBarButtonsEdit';
		if ($this->viewer->fullWidthToolbar)
			$this->viewer->toolbarElementId = $this->viewer->vid.'FWTEditButtons';
		elseif ($this->viewer->paneMode)
			$this->viewer->toolbarElementId = 'NONE';
		elseif ($this->viewer->type === 'inline' || $this->viewer->type === 'form')
			$this->viewer->toolbarElementId = $this->viewer->vid.'_Toolbar';
    */


		$detailCode = '';
		if ($this->viewer->objectSubType !== TableView::vsDetail)
			$detailCode = $this->createDetailsCode();

		/*
		$reportCode =
		"<div style='display: none;' class='e10-mv-lr' id='{$this->viewer->vid}Report'>" .
				//$this->createPanelsCode () .
				"<div class='e10-mv-lr-content'>{$this->viewer->report ()}</div>" .
				"</div>
		</div>";
		*/

		$viewerClass = "df2-viewer shp-viewer-{$this->viewer->table->tableId()} shp-{$this->viewer->objectSubType}";
		$viewerClass .= ' shp-viewer-type-'.$this->viewer->type;
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

		$viewerClass .= ' dmPanels';

		//if ($this->viewer->fullWidthToolbar)
		//	$viewerClass .= ' e10-viewer-fw-toolbar';

		if (isset ($this->viewer->classes))
			$viewerClass .= ' '.implode (' ', $this->viewer->classes);
		else
			$viewerClass .= ' appViewer';

		$c = "<data-viewer style='display: none;' ";
		$c .= "data-request-type='dataViewer' ";
    $c .= "data-object-type='data-viewer'";
    $c .= "class='$viewerClass' id='{$this->viewer->vid}' data-viewer='{$this->viewer->vid}' data-object='viewer'";
		$c .=	"data-viewertype='{$this->viewer->objectSubType}' data-table='" . $this->viewer->table->tableId () . "' data-viewer-view-id='" . $this->viewer->viewId () . "' ";

		foreach ($this->viewer->addParams as $apk => $apv)
		{
			if (str_starts_with($apk, ''))
				$c .= "data-form-param-addparam-".substr($apk, 2)."='".Utils::es(strval($apv))."' ";
			else
				$c .= "data-form-param-addparam-{$apk}='".Utils::es(strval($apv))."' ";
		}

		$c .= "data-queryparams='{$this->viewer->queryParams()}' data-lineswidth='{$this->viewer->linesWidth}' ";
		$c .= "data-toolbar='{$this->viewer->toolbarElementId}' data-mode='{$this->viewer->mode}' data-type='{$this->viewer->type}'";

    /*
		if ($this->viewer->inlineSourceElement)
		{
			foreach ($this->viewer->inlineSourceElement as $key => $value)
				$c .= " data-inline-source-element-{$key}='" . Utils::es($value) . "'";
		}
    */

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
		if ($this->viewer->enableToolbar)
		{
			$c .= "<div class='toolbar'>";
				$c .= "<div class='buttons'>";
					if ($this->viewer->enableFullTextSearch)
					{
						$c .= "<span class='fts'>";
							$c .= $this->app()->ui()->icon('system/iconSearch', 'iconSearch');
							$c .= "<input name='fullTextSearch' type='text' class='fulltext e10-viewer-search' autocomplete='off' placeholder='".utils::es($placeholder)."' value=''";
							if ($this->viewer->disableIncrementalSearch)
								$c .= " data-onenter='1'";
							$c .= '/>';
							$c .= $this->app()->ui()->icon('system/actionInputClear', 'iconClear');
						$c .= '</span>';
					}
					$c .= $this->createToolbarCode ();
				$c .= '</div>';

				$fc = $this->createMainQueriesCode();
				if ($fc !== '')
				{
					$c .= "<div class='filters'>";
					$c .= $fc;
					$c .= '</div>';
				}

			$c .= '</div>';
		}

		$c .= $this->createViewerBodyCode();

		//if ($this->viewer->objectSubType == TableView::vsMain)
		{
			$c .= $detailCode;// . $reportCode;
		}

		$c .= $this->createPanelsCode();


		$c .= "</data-viewer>";

    return $c;
	}

	function createDetailsCode()
	{
		$c = '';
		$c .= "<div style='' class='detail' id='{$this->viewer->vid}Details'>";
		$c .= "<div class='header'></div>";
		$c .= "<div class='content' data-e10mxw='1'></div>";


		$details = $this->viewer->createDetails ();
		if (count($details))
		{
			$active = ' active';

			$c .= "<div class='tabs'>\n";
			foreach ($details as $id => $detail)
			{
				$c .= "<span data-detail='$id' class='shp-widget-action$active' data-action='detailSelect'>";
				$c .= $this->app()->ui()->icon ($detail['icon']);
				$c .= "<div>".\E10\es ($detail['title']).'</div>';
				$c .= '</span>';
				$active = '';
			}
			$c .= "</div>\n";
		}

		$c .= "</div>";

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

		$c .= $this->createLeftPanelCode();

		$c .= "<div class='body'>";
			$c .= "<div class='rows'>";
				$c .= "<div style='z-index: 499;' class='rows-list e10-viewer-list$listClass' id='{$this->viewer->vid}Items' data-rowspagenumber='0'".
							"data-viewer='{$this->viewer->vid}' data-rowelement='{$this->viewer->htmlRowElement}'>";
					$c .= $this->viewer->rows ();
				$c .= "</div>";
			$c .= "</div>";
			$c .= $this->createBottomTabsCode ();
		$c .= '</div>';

		return $c;
	}

	public function createLeftPanelCode()
	{
		if (!$this->viewer->usePanelLeft)
			return '';

		$this->viewer->panelLeft = $this->viewer->panel('left');
		$this->viewer->createPanelContent ($this->viewer->panelLeft);

		$c = '';

		$c .= "<div class='sidebar' id='{$this->viewer->vid}PanelLeft'>";
		$c .= $this->viewer->panelLeft->createCode();
		$c .= '</div>';

		return $c;
	}


	public function createPanelsCode()
	{
		if ($this->viewer->panels === FALSE)
			return '';

		$c = '';

		$c .= "<div class='panels'>";
			$c .= $this->createPanelsTabsCode();
			$c .= "<div class='activePanelContent'>".$this->viewer->report ()."</div>";
			$c .= "</div>";
		$c .= "</div>";

		return $c;
	}

	public function createPanelsTabsCode()
	{
		if ($this->viewer->panels === FALSE)
			return '';
		$c = '';

		$title = isset ($this->viewer->viewerDefinition['title']) ? $this->viewer->viewerDefinition['title'] : $this->viewer->table->tableName ();

		$c .= "<div class='viewerPanelsTabs'>";

		if ($title !== '')
			$c .= "<span class='title'>". Utils::es ($title) . '</span>';
		$c .= "<span class='tabs'>";
		$activeTabId = $this->viewer->panels[0]['id'];
		forEach ($this->viewer->panels as $q)
		{
			$txt = Utils::es ($q ['title']);
			$c .= "<span class='tab shp-widget-action";

			if ($q['id'] === $activeTabId)
				$c .= ' active';

			$c .= "' data-id='{$q['id']}' data-action='viewerPanelTab'>";
			$c .= $txt;
			$c .= '</span>';
		}
		$c .= '</span>';
		$c .= '</div>';
		return $c;
	}

	public function createToolbarCode ()
	{
		$c = '';
		$tlbr = $this->viewer->createToolbar ();

		/*
    if ($this->viewer->fullWidthToolbar)
		{
			$c .= $this->app()->ui()->composeTextLine($tlbr);
			return $c;
		}
		*/


		$btnClass = 'btn-large';
		if ($this->viewer->objectSubType == TableView::vsMini)
			$btnClass = 'btn-small';

		foreach ($tlbr as $btn)
		{
			$buttonsParams = [];
			$buttonsParams['data-action-param-table'] = $this->viewer->tableId();

			if ($btn['type'] == 'code')
			{
				$c .= $btn['code'];
			}
			else
			{
				$class = ' shp-widget-action';

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
													//$class .= ' e10-document-trigger';
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
					//$btnParams .= "data-class='{$btn['data-class']}' ";
					$buttonsParams['data-action-class'] = $btn['data-class'];

				if (isset ($btn['data-addparams']))
//					$btnParams .= "data-addparams='{$btn['data-addparams']}' ";
					$buttonsParams['data-action-add-params'] = $btn['data-addparams'];

				$btnText = $btn['text'];

				foreach ($buttonsParams as $bpk => $bpv)
					$btnParams.= ' '.$bpk."='".Utils::es($bpv)."'";

				if (isset ($btn['subButtons']) || isset ($btn['dropdownMenu']))
					$c .= "<div class='btn-group'>";

				if ($btn['action'] === '')
					$c .= "<button type='button' class='$class $btnClass dropdown-toggle' data-toggle='dropdown'>{$icon}&nbsp;{$btnText}XX &nbsp;<span class='caret'></span></button>";
				else
					$c .= "<button class='btn {$btnClass}$class e10-sv-tlbr-btn-{$btn['action']}' {$dataTable}data-action='{$btn['action']}' data-viewer='{$this->viewer->vid}' $btnParams>{$icon}&nbsp;{$btnText}</button>";
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

	public function createMainQueriesCode()
	{
		$c = '';
		if (!isset ($this->viewer->mainQueries))
			return '';


		$c .= "<div class='viewerQuerySelect'>";
		$c .= "<input name='mainQuery' type='hidden' value='{$this->viewer->mainQueries[0]['id']}'/>";
		$idx = 0;

		$code = ['left' => '', 'right' => ''];

		forEach ($this->viewer->mainQueries as $q)
		{
			$txt = Utils::es ($q ['title']);

			if ($idx === 0 || (isset($q['side']) && $q['side'] === 'left'))
			{
				if ($idx == 0)
					$code['left'] .= "<span class='q shp-widget-action active left' data-action='viewerTabsReload' data-value='{$q['id']}'>$txt</span>";
				else
					$code['left'] .= "<span class='q shp-widget-action left' data-action='viewerTabsReload' data-value='{$q['id']}'>$txt</span>";
			}
			else
			{
				$code['right'] .= "<span class='q shp-widget-action right' data-action='viewerTabsReload' data-value='{$q['id']}'>$txt</span>";
			}
			$idx++;
		}

		if ($code['left'] !== '')
			$c .= $code['left'];
		if ($code['right'] !== '')
			$c .= $code['right'];

		$c .= '</div>';

		return $c;
	}

	public function createBottomTabsCode ()
	{
		if (!isset ($this->viewer->bottomTabs))
			return;

		$h = "";

		$activeTab = 0;
		$idx = 0;
		forEach ($this->viewer->bottomTabs as $q)
		{
			if ($q['active'])
			{
				$activeTab = $idx;
				break;
			}
			$idx++;
		}

		$h .= "<div class='viewerBottomTabs'>";
		$h .= "<input name='bottomTab' type='hidden' value='{$this->viewer->bottomTabs[$activeTab]['id']}'/>";

		$activeTab = 0;
		$idx = 0;
		forEach ($this->viewer->bottomTabs as $q)
		{
			if ($q['active'])
			{
				$activeTab = $idx;
				break;
			}
			$idx++;
		}

		$idx = 0;
		forEach ($this->viewer->bottomTabs as $q)
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

			$active = ($idx == $activeTab) ? ' active' : '';
			$h .= "<span class='q shp-widget-action $active' data-action='viewerTabsReload' data-value='{$q['id']}' data-addparams='$addParams'>".Utils::es($q['title']).'</span>';
			$idx++;
		}
		$h .= '</div>';

		return $h;
	}

	public function rowHtml ($listItem)
	{
		if (isset ($listItem ['groupName']))
		{
			$codeLine = '<div'." class='g'>" . $this->app()->ui()->renderTextLine($listItem ['groupName']) . '</div>';
			return $codeLine;
		}

		$class = "r";
		//if (isset ($listItem ['txt']))
		//	$class .= " t";
		if (isset ($listItem['class']))
			$class .= " {$listItem['class']}";
		if ($this->viewer->htmlRowElementClass !== '')
			$class .= ' '.$this->viewer->htmlRowElementClass;

		if (isset($listItem ['pk']))
			$codeLine = '<'.'div'." class='$class' data-pk='{$listItem ['pk']}'";
		else
		if (isset($listItem ['ndx']))
			$codeLine = '<'.'div'." class='$class' data-pk='{$listItem ['ndx']}'";
		else
			$codeLine = '<'.'div'." class='$class'";

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
				$codeLine .= " data-popup-id='NEW-TAB'";
		}

		$codeLine .= ">";

		$codeLine .= $this->rowHtmlContent ($listItem);
		//$codeLine .= "<div class='vrd'></div>";
		$codeLine .= '</'.'div'.'>';

		return $codeLine;
	}

  public function rowHtmlContent ($listItem)
	{
		if ($this->viewer->paneMode)
		{
			$aa = $this->viewer->contentRenderer->createCodeTiles_Panes ([$listItem ['pane']], $this->app());
			//error_log("__: `{$aa}`");
			return $aa;
		}
		//if ($this->mobile)
		//	return $this->rowHtmlContentMobile ($listItem);

		$codeLine = '';

		if ($this->viewer->uiSubTemplate !== '')
		{
			$templateStr = $this->uiRouter->uiTemplate->subTemplateStr($this->viewer->uiSubTemplate);

			$this->uiRouter->uiTemplate->data['listRow'] = $listItem;

			$code = $this->uiRouter->uiTemplate->render($templateStr);
			return $code;
		}

		if (isset($listItem['pane']))
		{
			if (!$this->viewer->contentRenderer)
				$this->viewer->contentRenderer = new ContentRenderer($this->app());
			return $this->viewer->contentRenderer->createCodeTiles_Panes ([$listItem ['pane']], $this->app());
		}

		if (isset($listItem['card']))
		{
			if (!$this->viewer->contentRenderer)
				$this->viewer->contentRenderer = new ContentRenderer($this->app());

			return $this->viewer->contentRenderer->createCodeCard ($listItem ['card']);
		}


		$itemLevel = 0;
		if (isset ($listItem ['level']))
			$itemLevel = intval ($listItem ['level']);

		$codeLine .= "<div class='df2-list-item-recnum lnr df2-list-item-level$itemLevel'>";
		if ($this->viewer->checkboxes)
			$codeLine .= "<span><input type='checkbox' name='vchbx_{$listItem['pk']}' value='{$listItem ['pk']}'/></span>";
		if ($this->viewer->onlyOneRec === 0)
			$codeLine .= "<span class='recNum'>".strval ($this->viewer->lineRowNumber + $this->viewer->rowsFirst)."</span>";
		else
			$codeLine .= '*';
		$codeLine .= '</div>';

		if ((isset ($listItem ['icon'])) && ($listItem ['icon'] != ''))
		{
			$icon = $this->app()->ui()->icon($listItem ['icon'], $listItem['!error'] ?? '', 'span');
			$codeLine .= "<div class='df2-list-item-icon icon'>{$icon}</div>";
		}
		else
		if (isset ($listItem ['image']))
		{
			$codeLine .= "<div class='df2-list-item-image icon'>";
			if ($listItem ['image'] !== '')
				$codeLine .= "<img src='{$listItem ['image']}'>";
			$codeLine .= '</div>';
		}
		elseif ((isset ($listItem ['emoji'])))
		{
			$codeLine .= "<div class='df2-list-item-emoji icon'><span>{$listItem ['emoji']}</span></div>";
		}
		elseif ((isset ($listItem ['svgIcon'])) && ($listItem ['svgIcon'] != ''))
		{
			$codeLine .= "<div class='df2-list-item-icon icon'><img style='width:100%;' src='{$listItem ['svgIcon']}'></div>";
		}

		if (isset($listItem['code']))
		{
			$codeLine .= '<div>'.$listItem['code'].'</div>';

			return $codeLine;
		}

		$t1 = (isset($listItem ['t1'])) ? $this->app()->ui()->composeTextLine ($listItem ['t1']) : '';
		if ($t1 === '' && isset($listItem ['tt']))
			$t1 = $this->app()->ui()->composeTextLine ($listItem ['tt']);
		if ($t1 === '')
			$t1 = "&nbsp;";

		$t2 = isset ($listItem ['t2']) ? $this->app()->ui()->composeTextLine ($listItem ['t2']) : '&nbsp;';

		$i2 = '';
		if (isset ($listItem ['i2']))
			$i2 = $this->app()->ui()->composeTextLine ($listItem['i2']);

		//$codeLine .= "<div class='row-content'>";


		if (isset($listItem ['tt']))
		{
			if (isset ($listItem ['i1']))
				$codeLine .= "<span class='ml1 df2-list-item-i1'>" . $this->app()->ui()->composeTextLine ($listItem['i1']) . '</span>';
			$codeLine .= "<div class='df2-list-item-tt'>$t1</div>";
		}
		elseif (isset ($listItem ['t1']) || (!isset ($listItem ['t1']) && !isset ($listItem ['txt'])))
		{
			$codeLine .= "<div class='df2-list-item-t1 t1'>$t1</div>";
			if (isset ($listItem ['i1']))
				$codeLine .= "<div class='df2-list-item-i1 i1'>" . $this->app()->ui()->composeTextLine ($listItem['i1']) . '</div>';
		}
		if ($i2 !== '')
			$codeLine .= "<div class='i2'>" . $i2 . '</div>';

		if (isset ($listItem ['t2']) || (!isset ($listItem ['t2']) && !isset ($listItem ['txt'])))
			$codeLine .= "<div class='df2-list-item-t2 t2'>$t2</div>";

		if (isset($listItem ['t3']))
			$codeLine .= "<div class='df2-list-item-t3 t3'>" . $this->app()->ui()->composeTextLine ($listItem['t3']) . '</div>';

		if (isset ($listItem ['txt']))
			$codeLine .= "<div class='pageText'>{$listItem ['txt']}</div>";

    /*
		if (isset($listItem['content']))
		{
			if (!$this->viewer->contentRenderer)
				$this->viewer->contentRenderer = new ContentRenderer($this->app());

			$this->viewer->contentRenderer->setContent ($listItem ['content']);
			$codeLine .= "<div class='content'>".$this->contentRenderer->createCode ()."</div>";;
		}
    */

		//$codeLine .= "</div>";

    /*
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
    */


		return $codeLine;
	}

  public function addEndMark ()
	{
		$txt = ($this->viewer->rowsLoadNext) ? 'Načítají se další řádky' : $this->viewer->endMark (($this->viewer->rowsPageNumber === 0 && $this->viewer->countRows === 0));
		if ($this->viewer->mode === 'panes')
		{
			$this->viewer->objectData['endMark'] = $this->app()->ui()->composeTextLine($txt);
		}
		else
		{
			$cls = ($this->viewer->rowsLoadNext) ? 'e10-viewer-list-endNext' : 'e10-viewer-list-endEnd';
			$h = "<div class='$cls'>".$this->app()->ui()->composeTextLine($txt)."</div>";
			$this->viewer->addHtmlItem($h);
		}
	}

  public function render()
  {
    $this->renderedData['hcFull'] = $this->createViewerCode();
		if ($this->isModal)
		{
			$this->renderedData['hcBackIcon'] = $this->app()->ui()->icon('user/arrowLeft');
		}
  }
}
