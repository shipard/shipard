<?php

namespace Shipard\UI\Core;
use \Shipard\Utils\Utils;
use \Shipard\Viewer\TableView;


class ContentRenderer extends \Shipard\Base\BaseObject
{
	var $content;
	var $report = NULL;
	var $viewerDetail = NULL;
	var $viewerPanel = NULL;
	var $widget = NULL;
	var $form = NULL;
	var $mobile = FALSE;

	var $contentPartNumber = 0;

	var $srcObjectType = '';
	var $srcObjectId = '';

	public function setDocumentCard ($documentCard)
	{
		//$this->viewerPanel = $viewerPanel;
		$this->content = $documentCard->content;
	}

	public function setForm ($form)
	{
		$this->form = $form;
		$this->content = $form->content;
	}

	public function setReport ($report)
	{
		$this->report = $report;
		$this->content = $report->content;
	}

	public function setViewerDetail ($viewerDetail)
	{
		$this->viewerDetail = $viewerDetail;
		$this->content = $viewerDetail->content;
	}

	public function setViewerPanel ($viewerPanel)
	{
		$this->viewerPanel = $viewerPanel;
		$this->content = $viewerPanel->content;
	}

	public function setWidget ($widget)
	{
		$this->widget = $widget;
		$this->content = $widget->content;
	}

	public function setContent ($content)
	{
		$this->content = $content;
	}

	public function elementActionParams ($element, &$class)
	{
		$t = Utils::elementActionParams($element, $class);
		if ($this->widget)
			$t .= "data-srcobjecttype='widget' data-srcobjectid='{$this->widget->widgetId}'";
		else
		{
			if ($this->srcObjectType !== '')
				$t .= "data-srcobjecttype='{$this->srcObjectType}' ";
			if ($this->srcObjectId !== '')
				$t .= "data-srcobjectid='{$this->srcObjectId}' ";
		}
		return $t;
	}

	public function createCode ($part = '')
	{
		if ($part !== '' && !isset($this->content[$part]))
			return '';

		$content = ($part === '') ? $this->content : $this->content[$part];
		$c = '';

		// -- special mode: single viewer
		if (isset ($content[0]['type']) && $content[0]['type'] === 'viewer')
		{
			$cp = $content[0];
			return $this->createCodeViewer($cp);
		}

		if ($this->report !== NULL)
			$c .= "<div class='e10-reportContent'>" . $this->report->createReportContentHeader (NULL);
		else
		if ($this->viewerDetail !== NULL)
		{
			if (count($content) === 1 && isset($content[0]['fullsize']))
				$c .= "<div class='e10-reportContent' style='padding: 0px;'>";
			else
				$c .= "<div class='e10-reportContent'>";
		}
		elseif ($this->viewerPanel)
		{
			if ($this->viewerPanel->activeMainItem)
				$c .= "<input type='hidden' name='panel-main-item-{$this->viewerPanel->panelId}' value='".Utils::es($this->viewerPanel->activeMainItem)."'>";
		}

		$c .= $this->createCodeBody($content);

		if ($this->report !== NULL)
			$c .= $this->report->createReportContentNotes () . '</div>';
		if ($this->viewerDetail !== NULL)
			$c .= '</div>';

		return $c;
	}

	public function createCodeBody ($content)
	{
		$c = '';

		$this->contentPartNumber = 0;
		forEach ($content as $cp)
		{
			if ($cp === FALSE)
				continue;

			if (isset ($cp['openCell']))
				$c .= "<div class='{$cp['openCell']}'>";

			if (isset($cp['details']))
				$c .= "<details class='{$cp['details']}'>";
			if (isset ($cp['detailsTitle']))
				$c .= '<summary>'.$this->app()->ui()->composeTextLine ($cp ['detailsTitle']).'</summary>';

			if (isset($cp['pane']))
				$c .= "<div class='{$cp['pane']}'>";
			if (isset ($cp['paneTitle']))
				$c .= $this->app()->ui()->composeTextLine ($cp ['paneTitle']);

			if (isset ($cp['type']) && $cp['type'] === 'viewer')
				$c .= $this->createCodeViewer ($cp);
			else
			if (isset ($cp['table']))
				$c .= $this->createCodeTable ($cp);
			else
			if (isset ($cp['tables']))
			{
				if (isset ($cp['title']) && $cp['title'])
					$c .= '<div class="subtitle">' . $this->app()->ui()->composeTextLine ($cp['title']) . '</div>';

				foreach ($cp['tables'] as $table)
				{
					if (isset ($table['title']) && $table['title'])
						$c .= "<div class='subtitle'>" . $this->app()->ui()->composeTextLine ($table['title']) . '</div>';

					$c .= \E10\renderTableFromArray ($table['table'], $table['header'], isset ($table['params']) ? $table['params'] : [], $this->app);
				}
			}
			else
			if (isset ($cp['text']) /*&& $cp['text'] !== ''*/)
				$c .= $this->createCodeText($cp);
			else
			if (isset ($cp['url']))
				$c .= "<iframe src='{$cp['url']}' frameborder='0' style='width: 100%;' class='e10-wsh-h2p'></iframe>";
			else
			if (isset ($cp['attachments']))
				$c .= $this->createCodeAttachments ($cp, $this->app);
			else
			if (isset ($cp['type']) && $cp['type'] === 'grid')
				$c .= $this->createCodeGrid ($cp);
			else
			if (isset ($cp['type']) && $cp['type'] === 'widget')
				$c .= $this->createCodeWidget ($cp);
			if (isset ($cp['type']) && $cp['type'] === 'properties')
				$c .= $this->createCodeProperties ($cp);
			else
			if (isset ($cp['type']) && $cp['type'] === 'tiles')
				$c .= $this->createCodeTiles ($cp);
			else
			if (isset ($cp['type']) && $cp['type'] === 'tags')
				$c .= $this->createCodeTags ($cp['tiles']);
			else
			if (isset ($cp['query']))
				$c .= $this->createCodeQuery ($cp['query']);
			else
			if (isset ($cp['type']) && $cp['type'] === 'graph')
				$c .= $this->createCodeGraph($cp);
			else
			if (isset ($cp['tabs']))
				$c .= $this->createCodeTabs ($cp);
			else
			if (isset ($cp['line']))
				$c .= $this->app()->ui()->composeTextLine($cp['line']);
			elseif (isset ($cp['list']))
				$c .= $this->createCodeList($cp);
			else
			if (isset ($cp['reportHeader']))
				$c .= uiutils::createReportContentHeader($this->app, $cp['reportHeader']);
			else
			if (isset ($cp['type']) && $cp['type'] === 'content')
				$c .= $this->createCodeBody ($cp['content']);
			else
			if (isset ($cp['type']) && $cp['type'] === 'map')
				$c .= $this->createCodeMap ($cp['map']);
			elseif (isset ($cp['sumTable']))
				$c .= $this->createCodeSumTable ($cp);
			elseif (isset ($cp['content']))
			{
				if (isset ($cp['title']) && $cp['title'])
					$c .= '<div class="subtitle" style="margin-bottom: 1ex; display: inline-block;width: 100%;padding-bottom: .3ex;">' . $this->app()->ui()->composeTextLine ($cp['title']) . '</div>';
				$crr = new ContentRenderer($this->app());
				$crr->content = $cp['content'];
				$c .= $crr->createCode();
			}
			if (isset($cp['pane']))
				$c .= '</div>';

			if (isset($cp['details']))
				$c .= '</details>';

			if (isset ($cp['closeCell']))
				$c .= '</div>';

			$this->contentPartNumber++;
		}

		return $c;
	}

	public function createCodeViewer ($cp)
	{
		$v = $this->app->table ($cp['table'])->getTableView ($cp['viewer'], $cp['params']);
		if (isset ($cp['vid']))
			$v->vid = $cp['vid'];
		if (isset ($cp['inlineSourceElement']))
			$v->inlineSourceElement = $cp['inlineSourceElement'];

		if ($this->viewerDetail /*|| $this->widget*/)
			$v->objectSubType = TableView::vsDetail;

		$c = '';

		if (isset ($cp['params']['elementClass']))
			$c .= "<div class='{$cp['params']['elementClass']}'>";

		$v->renderViewerData ('');
		$c .= $v->createViewerCode ('', TRUE);

		if (isset ($cp['receiver']))
			$cp['receiver']->broadcast ('viewer-created', $v);

		if (isset ($cp['params']['elementClass']))
			$c .= '</div>';

		if ($this->report)
		{
			$this->report->objectData ['htmlCodeToolbarViewer'] = $v->createToolbarCode ();
			$this->report->objectData ['detailViewerId'] = $v->vid;
			$c .= "<script>initViewer ('{$v->vid}');</script>";
		}
		else
		if ($this->viewerDetail)
		{
			$this->viewerDetail->objectData ['htmlCodeToolbarViewer'] = $v->createToolbarCode ();
			$this->viewerDetail->objectData ['detailViewerId'] = $v->vid;
			$c .= "<script>initViewer ('{$v->vid}');</script>";
		}
		else
		if (isset($cp['params']['forceInitViewer']))
			$c .= "<script>initViewer ('{$v->vid}');</script>";

		$v->appendAsSubObject();
		return $c;
	}

	public function createCodeTable ($cp)
	{
		$params = isset ($cp['params']) ? $cp['params'] : [];

		$c = '';

		if (isset ($params['newPage']))
		{
			$doIt = FALSE;
			if ($params['newPage'] === 1)
				$doIt = TRUE;
			elseif ($params['newPage'] === 2 && $this->contentPartNumber !== 0)
				$doIt = TRUE;

			if ($doIt)
				$c .= "<div class='pageBreakBefore'>&nbsp;</div>";
		}

		if (isset ($cp['reportHeader']))
			$c .= $this->report->createReportContentHeader ($cp);

		if (isset ($cp['title']) && $cp['title'])
			$c .= "<div class='subtitle'>" . $this->app()->ui()->composeTextLine ($cp['title']) . '</div>';

		if (isset ($cp['main']) || isset ($params['fixedHeader']))
			$params['tableClass'] = isset($params['tableClass']) ? $params['tableClass'].' main' : 'main';

		$c .= \E10\renderTableFromArray ($cp['table'], $cp['header'], $params, $this->app);

		return $c;
	}

	public function createCodeSumTable ($cp)
	{
		$params = isset ($cp['params']) ? $cp['params'] : [];

		$objectId = isset($cp['sumTable']['objectId']) ? $cp['sumTable']['objectId'] : '';
		if ($objectId === '')
		{
			return '';
		}

		/** @var \lib\core\ui\SumTable $o */
		$o = $this->app->createObject($objectId);
		if (!$o)
		{
			return '';
		}
		$o->renderAll = 1;
		if (isset($cp['sumTable']['queryParams']))
			$o->setQueryParams($cp['sumTable']['queryParams']);
		$o->init();
		$o->loadData();
		$o->renderCode();

		return $o->code;
	}

	public function createCodeAttachments ($cp, $app)
	{
		$attachments = $cp['attachments'];
		if (!$attachments['count'])
			return '';

		$c = '';

		// -- files
		if (isset ($attachments['hasDownload']))
		{
			$c .= "<div class='e10-pane e10-pane-table mt1'>";
			if (isset($cp['downloadTitle']) && $cp['downloadTitle'])
			{
				$c .= '<div class="subtitle">' . $this->app()->ui()->composeTextLine($cp['downloadTitle']) . '</div>';
			}
			$c .= "<div class='e10-attbox'>";
			forEach ($attachments['files'] as $a)
			{
				$fileUrl = \E10\Base\getAttachmentUrl ($app, $a);
				if ($this->mobile)
				{
					$mimeType = mime_content_type('att/'.$a['path'].$a['filename']);
					$c .= "<span class='link btn btn-default' data-file-url='$fileUrl' data-mime-type='$mimeType'>" . Utils::es($a ['filename']) . '</span>';
				}
				else
					$c .= "<a href='$fileUrl' target='_new' class='btn btn-default'>" . Utils::es ($a ['filename']) . '</a>';
			}
			$c .= '</div>';
			$c .= '</div>';
		}

		// -- images
		$fullSizeTreshhold = 4;
		if (isset ($cp['fullSizeTreshold']))
			$fullSizeTreshhold = $cp['fullSizeTreshold'];
		if (isset ($attachments['images']) && count ($attachments['images']))
		{
			$itemClass = 'e10-attbox-item';
			$thumbSize = 800;

			if (count($attachments['images']) < $fullSizeTreshhold)
			{
				$itemClass = 'e10-attbox-one';
				$thumbSize = 1200;
			}
			if (isset($cp['title']) && $cp['title'])
				$c .= '<div class="subtitle">'.$this->app()->ui()->composeTextLine($cp['title']).'</div>';
			$c .= "<div class='e10-attbox'>";
			forEach ($attachments['images'] as $a)
			{
				$thumbUrl = \E10\Base\getAttachmentUrl ($app, $a, $thumbSize, 2 * $thumbSize);
				$fileUrl = \E10\Base\getAttachmentUrl ($app, $a);
				$mimeType = mime_content_type('att/'.$a['path'].$a['filename']);
				$thumbTitle = \E10\es ($a ['name']);

				if ($this->mobile)
				{
					$c .= "<span class='$itemClass'>".
							"<div>příloha se načítá...</div>".
							"<img class='link e10-img-loading' src='$thumbUrl' title=\"$thumbTitle\" onload=\"e10.imgLoaded($(this))\" data-file-url='$fileUrl' data-mime-type='$mimeType'>".
							'</span>';
				}
				else
				{
					/*
					$c .= "<span class='$itemClass'>".
							"<a href='$fileUrl' target='_new'><img src='$thumbUrl' title=\"$thumbTitle\"></a>".
							'</span>';
					*/
					$c.= "<span class='$itemClass'>";
					$c .= "<span class='df2-action-trigger' data-url-download='$fileUrl' data-action='open-link' data-popup-id='vdatt' data-with-shift='tab'>".
						"<img src='$thumbUrl' title=\"$thumbTitle\">".
						'</span>';
					$c .= '</span>';
				}
			}
			$c .= '</div>';
		}

		return $c;
	}

	public function createCodeGrid ($cp)
	{
		$c = '';

		if ($cp['cmd'] === 'rowOpen')
		{
			$c .= "<div class='e10-gs-row'>";
		}
		else
		if ($cp['cmd'] === 'colOpen')
		{
			$c .= "<div class='e10-gs-col e10-gs-col{$cp['width']}'>";
		}
		else
		if ($cp['cmd'] === 'rowClose' || $cp['cmd'] === 'colClose' || $cp['cmd'] === 'fxClose')
			$c .= '</div>';
		else
			$c .= "<div class='{$cp['cmd']}'>";

		return $c;
	}

	public function createCodeList ($cp)
	{
		$c = '';

		$c .= "<div class='e10-tl'>";
		if (isset($cp['list']['title']))
		{
			$c .= $this->createCodePaneBlock(NULL, $cp['list']['title'], 'title');
		}

		$c .= "<div class='body'>";
		$dsClass = isset($cp['list']['docStateClass']) ? $cp['list']['docStateClass'] : 'e10-ds';

		foreach ($cp['list']['rows'] as $d)
		{
			if (isset($d['ndx']))
			{
				$c .= "<div class='e10-tl-doc e10-document-trigger' data-action='edit' data-table='{$cp['list']['table']}' data-pk='{$d['ndx']}'>";
				$c .= "<div class='$dsClass {$d['docStateClass']}'>";
			}
			else
			{
				$rowClass = isset($d['class']) ? ' '.$d['class'] : '';
				$c .= "<div class='e10-tl-row$rowClass'>";
				$c .= "<div class='e10-ds header'>";
			}

			if (isset($d['title']))
			{
				$c .= "<div class='title'>";
				$c .= $this->app()->ui()->composeTextLine($d['title']);
				$c .= '</div>';
			}
			$c .= '</div>';
			if (isset($d['info']))
			{
				foreach ($d['info'] as $i)
				{
					if (isset($i['infoClass']))
						$c .= "<div class='{$i['infoClass']}'>";
					if (isset($i['texy']))
					{
						$c .= $i['texy'];
					}
					elseif (isset($i['value']))
						$c .= $this->app()->ui()->composeTextLine($i['value']);
					else
						$c .= $this->app()->ui()->composeTextLine($i);
					if (isset($i['infoClass']))
						$c .= '</div>';
				}
			}
			if (isset($d['attachments']))
				$c .= $this->createCodeAttachments ($d['attachments'], $this->app);;
			$c .= '</div>';
		}
		$c .= '</div>';

		$c .= '</div>';

		return $c;
	}

	public function createCodeProperties ($properties)
	{
		$c = '';

		if (isset ($properties['params']))
		{
			if ($properties['title'] !== '')
				$c .= '<div class="subtitle">' . Utils::es ($properties['title']) . '</div>';
			$c .= "<table class='properties fullWidth'>";

			forEach ($properties['params'] as $paramGroup)
			{
				$c .= "<tr class='header'><td colspan='2'>" . Utils::es ($paramGroup['title']) . '</td></tr>';
				forEach ($paramGroup['rows'] as $row)
				{
					$c .= "<tr><td style='width: 30%;'>" . Utils::es ($row ['title']) . '</td><td>' .
					$this->app()->ui()->composeTextLine($row ['value']) . '</td></tr>';
				}
			}
			$c .= "</table>";
		}

		if (isset ($properties['texts']))
		{
			forEach ($properties['texts'] as $t)
			{
				$c .= '<div class="subtitle">' . Utils::es ($t ['title']) . '</div>';
				$c .= "<div class='pageText' style='padding: 1ex; border: 1px solid #666; border-radius: 2px;background-color: #FAFAFA;'>".
					$t ['text'].'</div>';
			}
		}

		return $c;
	}

	public function createCodeText ($cp)
	{
		$c = '';

		$subtype = isset($cp['subtype']) ? $cp['subtype'] : 'auto';
		if ($subtype === 'auto')
		{
			if (strstr ($cp['text'], '<html') !== FALSE || strstr ($cp['text'], '<span') !== FALSE || strstr ($cp['text'], '<div') !== FALSE || strstr ($cp['text'], '<p') !== FALSE)
				$subtype = 'html';
			else
				$subtype = 'plain';
		}
		$class = 'e10-msg-text';
		if (isset($cp['class']))
			$class .= ' '.$cp['class'];
		switch ($subtype)
		{
			case 'code':
				$c .= '<pre>'.Utils::es ($cp['text']).'</pre>';
				break;
			case 'plain':
				$c .= "<pre class='$class'>".Utils::es ($cp['text']).'</pre>';
				break;
			case 'html':
				if (isset($cp['iframeUrl']))
					$c .= "<iframe sandbox='' frameborder='0' height='100%' width='100%' style='background-color: #fafafa; width:100%;height:80%;min-height:70vh;' src='{$cp['iframeUrl']}'></iframe>";
				else
				{
					$myString = base64_encode($cp['text']);
					$c .= "<iframe sandbox='' frameborder='0' height='100%' width='100%' style='background-color: #fafafa; width:100%;height:80%;' src='data:text/html;base64;charset=utf-8," . $myString . "'></iframe>";
				}
				break;
			case 'rawhtml':
				$c .= $cp['text'];
				break;
		}

		return $c;
	}

	public function createCodeTiles ($tiles)
	{
		if ($tiles['class'] === 'coverImages')
			return $this->createCodeTiles_CoverImages ($tiles);
		else
			if ($tiles['class'] === 'panes')
				return $this->createCodeTiles_Panes ($tiles['tiles'], $this->app);

		return $this->createCodeTiles_Documents ($tiles);
	}

	public function createCodeTiles_Documents ($tiles)
	{
		$c = '';
		$c .= "<div>";
		$tileIndex = 0;

		if (isset ($tiles['title']) && $tiles['title'])
		{
			$c .= '<div class="subtitle">' . $this->app()->ui()->composeTextLine ($tiles['title']) . '</div>';
		}

		forEach ($tiles['tiles'] as $t)
		{
			$c .= "<div style='padding: .5ex; width: 50%; display: inline-block;'>";

			$params = '';
			$class = '';

			$style = "border: 1px solid #aaa; height: 9ex; position: relative;";

			if (isset ($t['coverImage']))
				$style .= "background-image:url(\"" . $t['coverImage'] . "\"); background-size: 9ex auto; background-repeat: no-repeat;";
			$c .= "<div class='$class'$params style='$style'>";

			/*
			if ($this->docCheckBoxes !== 0)
			{
				$checked = (($this->docCheckBoxes === 1 && $tileIndex === 1) || ($this->docCheckBoxes === 2)) ? " checked='checked'" : '';
				$c .= "&nbsp;<input type='checkbox' name='docActionData.rows.$tileIndex.enabled' value='1'$checked/>";
			}*/

			$c .= "<div style='position: absolute; bottom: 0px; right: 0px; background-color: rgba(0,0,0,.1); width: 80%; height: 100%; padding: 3px;'>";
			$c .= "<h4>".$this->app()->ui()->composeTextLine($t['t1']);
			if (isset($t['i1']))
				$c .= "<small style='float: right; color: #333;'>".$this->app()->ui()->composeTextLine($t['i1']).'</small>';
			$c .= '</h4>';

			if (isset ($t['t2']))
				$c .= $this->app()->ui()->composeTextLine($t['t2']);
			if (isset ($t['t3']))
				$c .= '<br/>'.$this->app()->ui()->composeTextLine($t['t3']);
			$c .= '</div>';

			$c .= '</div>';

			if (isset ($t['docActionData']))
			{
				forEach ($t['docActionData'] as $ddId => $ddValue)
				{
					$c .= "<input type='hidden' name='docActionData.rows.$tileIndex.{$ddId}' value='$ddValue'/>";
				}
			}

			$c .= '</div>';

			$tileIndex++;
		}

		$c .= '</div>';
		return $c;
	}

	public function createCodeTiles_CoverImages ($tiles)
	{
		$c = '';
		if (isset ($tiles['title']) && $tiles['title'])
		{
			$c .= '<div class="subtitle">' . $this->app()->ui()->composeTextLine ($tiles['title']) . '</div>';
		}

		$tileWidth = '33.333%';
		$cntTiles = 0;

		forEach ($tiles['tiles'] as $t)
		{
			if ($cntTiles === 6)
				$tileWidth = '25%';
			$c .= "<div style='padding: 1ex; width: $tileWidth; display: inline-block;'>";

			$params = '';
			$class = '';

			if (isset ($t['docAction']))
				$params .= $this->elementActionParams ($t, $class);

			$style = "border: 1px solid #333; cursor: pointer; position: relative;";

			$c .= "<div class='$class'$params style='$style'>";

			if (isset ($t['coverImage']))
				$c .= "<img src='{$t['coverImage']}' style='width:100%; min-height: 11em;'>";

			if (isset ($t['badge-lt']) && $t['badge-lt'] !== '')
				$c .= "<span class='badge badge-error' style='position: absolute; left: 1ex; top: 1ex; font-size: 133%; border: 2px solid white;'>".\E10\es($t['badge-lt'])."</span>";

			$c .= "<div style='position: absolute; bottom: 0px; left: 0px; background-color: rgba(0,0,0,.5); color: white; width: 100%; min-height: 6ex; padding: 3px; text-shadow: 1px 1px 2px #333;'>";
			$c .= $this->app()->ui()->composeTextLine($t['t1']).'<br/>';
			if (isset($t['t2']))
				$c .= $this->app()->ui()->composeTextLine($t['t2']);
			$c .= '</div>';
			$c .= '</div>';
			$c .= '</div>';

			$cntTiles++;
		}

		return $c;
	}

	function createCodeTiles_Panes ($tiles, $app)
	{
		$c = '';

		forEach ($tiles as $t)
		{
			if (!isset($t['info']))
			{
				$c.= $this->createCodePane ($t);
				continue;
			}

			$class = '';
			$params = '';
			if (isset ($t['class']))
				$class = $t['class'];
			else
				$class = 'e10-pane e10-pane-table';

			if (isset($t['image']) && $t['image'] !== FALSE)
				$class .= ' image';
			else
			if (isset($t['icon']))
				$class .= ' icon';

			if (isset ($t['docAction']))
				$params .= $this->elementActionParams ($t, $class);
			if (isset ($t['css']))
				$params .= ' style="'.$t['css'].'"';

			$c .= "<div class='$class'$params>";

			if (isset($t['image']) && $t['image'] !== FALSE)
			{
				if (isset ($t['cover']))
				{
					$c .= "<div class='paneImage' style='background-image:url({$t['image']}); background-size: cover;'></div>";
				}
				else
					$c .= "<div class='paneImage'><img src='{$t['image']}'></div>";
			}
			else
			if (isset($t['icon']))
			{
				$c .= "<div class='paneIcon'>".$this->app()->ui()->icon($t['icon'])."</div>";
			}

			$c .= '<div>';

			foreach ($t['info'] as $info)
			{
				$class = (isset ($info ['class'])) ? $info ['class'] : '';
				$c .= '<div ';

				if (isset ($info['docAction']))
					$c .= $this->elementActionParams ($info, $class);
				if ($class !== '')
					$c .= " class='$class'";
				$c .= '>';

				if (isset ($info ['code']))
					$c .= $info ['code'];
				else
				if (isset ($info ['attachments']))
				{
					$c .= $this->createCodeAttachments ($info, $app);
				}
				else
				if (isset ($info ['list']))
				{
					$c .= $this->createCodeList ($info);
				}
				else
				if (isset ($info['table']))
				{
					if (isset ($info['title']))
						$c .= $this->app()->ui()->composeTextLine ($info ['title']);
					$c .= $this->app()->ui()->renderTableFromArray ($info['table'], $info['header'], isset ($info['params']) ? $info['params'] : []);
				}
				else
					$c .= $this->app()->ui()->composeTextLine ($info ['value']);
				$c .= '</div>';
			}

			$c .= '</div>';
			$c .= '</div>';

		}

		return $c;
	}

	function createCodePane ($pane)
	{
		$c = '';

		if (isset ($pane['class']))
			$class = $pane['class'];
		else
			$class = 'e10-pane e10-pane-table';

		$params = '';
		if (isset ($pane['css']))
			$params .= ' style="'.$pane['css'].'"';

		if (isset ($pane['data']))
			$params .= Utils::dataAttrs($pane);

		$c .= "<div class='$class'{$params}>";

		if (isset($pane['title']))
			$c .= $this->createCodePaneBlock ($pane, $pane['title'], 'title');
		if (isset($pane['body']) && count($pane['body']))
			$c .= $this->createCodePaneBlock ($pane, $pane['body'], 'body');
		if (isset($pane['footer']))
			$c .= $this->createCodePaneBlock ($pane, $pane['footer'], 'footer');

		$c .= '</div>';

		return $c;
	}

	function createCodePaneBlock ($pane, $block, $blockClass)
	{
		$c = '';

		$bc = $blockClass;
		$c .= "<div class='$bc'>";

		foreach ($block as $info)
		{
			$class = (isset ($info ['class'])) ? $info ['class'] : '';
			$c .= '<div ';

			if (isset ($info['docAction']))
				$c .= $this->elementActionParams ($info, $class);
			if ($class !== '')
				$c .= " class='$class'";
			$c .= '>';

			if (isset ($info ['code']))
				$c .= $info ['code'];
			else
			if (isset ($info ['attachments']))
				$c .= $this->createCodeAttachments ($info, $this->app);
			else
			if (isset ($info ['list']))
				$c .= $this->createCodeList ($info);
			else
			if (isset ($info['table']))
			{
				if (isset ($info['title']))
					$c .= $this->app()->ui()->composeTextLine ($info ['title']);
				$c .= \E10\renderTableFromArray ($info['table'], $info['header'], isset ($info['params']) ? $info['params'] : []);
			}
			else
			if (isset ($info ['text']))
				$c .= $this->createCodeText ($info);
			else
			if (isset ($info['content']))
				$c .= $this->createCodeBody ($info['content']);
			else
			if (isset($info ['value']))
				$c .= $this->app()->ui()->composeTextLine ($info ['value']);
			/*else
				$c .= $this->app()->ui()->composeTextLine ($info);*/

			$c .= '</div>';
		}

		$c .= '</div>';

		return $c;
	}

	public function createCodeTags ($tiles)
	{
		$c = '';
		$c .= "<div class='e10-viewerDetail-tags'>";

		forEach ($tiles as $t)
		{
			$c .= "<span class='tag tag-contact'>".$this->app()->ui()->composeTextLine($t).'</span> ';
		}

		$c .= '</div>';
		return $c;
	}

	protected function createCodeQuery ($q)
	{
		$c = "<div class='queryWidget'>";

		forEach ($q as $queryGroup)
		{
			if ($queryGroup['style'] === 'params')
			{
				if (isset($queryGroup['title']))
				{
					$title = $this->app()->ui()->composeTextLine($queryGroup['title']);
					$c .= "<div class='e10-pane-params'>";
					$c .= "<h3>" . $title . '</h3>';
				}
				if (isset($queryGroup['class']))
					$c .= "<div class='{$queryGroup['class']}'>";

				$c .= $queryGroup['params']->createCode();

				if (isset($queryGroup['class']))
					$c .= "</div>";

				if (isset($queryGroup['title']))
					$c .= '</div>';
			}
			else
			{
				$c .= $this->createCodeBody([$queryGroup]);
			}
		}

		$c .= '</div>';
		return $c;
	}

	protected function createCodeGraph ($cp)
	{
		$gid = 'GRPH'.mt_rand(10000, 9999999);

		$elementClass = isset($cp['elementClass']) ? $cp['elementClass'] : 'e10-graph';

		$c = '';

		if ($this->app()->printMode)
		{
			$c .= "\n<script>"."
			function e10nf (n, c){
			var
				c = isNaN(c = Math.abs(c)) ? 0 : c,
				d = ',',
				t = ' ',
				s = n < 0 ? \"-\" : \"\",
				i = parseInt(n = Math.abs(+n || 0).toFixed(c)) + \"\",
				j = (j = i.length) > 3 ? j % 3 : 0;
			return s + (j ? i.substr(0, j) + t : \"\") + i.substr(j).replace(/(\d{3})(?=\d)/g, \"$1\" + t) + (c ? d + Math.abs(n - i).toFixed(c).slice(2) : \"\");
			}
			</script>";

			$beginUrl = ($this->app()->remote !== '') ? 'https://' . $this->app()->remote : $this->app()->scRoot();

			$c .= "\n<script src='{$beginUrl}/libs/js/jquery/jquery-2.2.4.min.js'></script>";
			$c .= "\n<script src='{$beginUrl}/libs/js/d3/d3.min.js'></script>";
			$c .= "\n<script src='{$beginUrl}/libs/js/c3/c3.min.js'></script>";
			$c .= "\n<div id='$gid' style='height: 30em; width: 18cm;'></div> ";
		}
		else
		{
			if ($this->app->clientType [1] === 'cordova')
			{
				$c .= "<script src='js/d3.min.js'></script>";
				$c .= "<script src='js/c3.min.js'></script>";
			}
			else
			{
				$beginUrl = ($this->app()->remote !== '') ? 'https://' . $this->app()->remote : $this->app()->scRoot();
				$c .= "<script src='{$beginUrl}/libs/js/d3/d3.min.js'></script>";
				$c .= "<script src='{$beginUrl}/libs/js/c3/c3.min.js'></script>";
			}

			if (isset($cp['title']))
				$c .= $this->app()->ui()->composeTextLine($cp['title']);

			$c .= "\n<div id='$gid' class='$elementClass' style='width: 100%;'></div> ";
		}


		$graphDefinition = ['data' => [], 'axis' => ['x' => ['type' => 'category']]];
		$graphDefinition ['bindto'] = '#'.$gid;

		$columnsData = [];
		$rowNum = 1;

		if ($cp['graphType'] === 'pie')
		{
			$graphDefinition['data']['columns'] = $cp['graphData'];
			$graphDefinition['data']['type'] = 'pie';
			$graphDefinition['legend']['position'] = 'right';
		}
		else
		if ($cp['graphType'] === 'bar' || $cp['graphType'] === 'line' || $cp['graphType'] === 'spline')
		{
			$cullingX = 12;
			if (isset ($cp['cullingX']))
				$cullingX = $cp['cullingX'];
			if ($cullingX !== 0)
				$graphDefinition['axis']['x']['tick'] = ['culling' => ['max' => 12]];
			$graphDefinition['axis']['y']['ticks'] = 5;

			$graphDefinition['axis']['y']['tick'] = [];


			$columnsData[] = [];
			foreach ($cp['graphData'] as $oneRow)
			{
				$newRow = [];
				foreach ($cp['header'] as $colId => $colName)
				{
					if (isset ($cp['disabledCols']) && in_array($colId, $cp['disabledCols']))
						continue;

					if ($colId === $cp['XKey'])
					{
						$graphDefinition['axis']['x']['categories'][] = $oneRow[$colId];
						continue;
					}

					if ($rowNum === 1)
						$columnsData[0][] = Utils::tableHeaderColName($colName);

					if (isset ($oneRow[$colId]))
						$newRow[] = $oneRow[$colId];
					else
						$newRow[] = 0;
				}
				$columnsData[] = $newRow;
				$rowNum++;
			}

			$graphDefinition['data']['rows'] = $columnsData;
		}
		if ($cp['graphType'] === 'bar')
		{
			if (isset ($cp['stacked']) && $cp['stacked'])
				$graphDefinition['data']['groups'] = [$columnsData[0]];
		}
		$graphDefinition['data']['type'] = $cp['graphType'];
		if (isset($cp['graphColors']))
			$graphDefinition['data']['colors'] = $cp['graphColors'];

		$c .= "\n<script>\n";
		$c .= "var g{$gid}"." = ".json_encode($graphDefinition, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";

		if ($cp['graphType'] === 'bar' || $cp['graphType'] === 'line' || $cp['graphType'] === 'spline')
			$c .= "g{$gid}".".axis.y.tick.format = e10nf;";

		$c .= "setTimeout (function () {c3.generate (g".$gid.");}, 150);";

		$c .= "\n</script>\n";

		return $c;
	}

	public function createCodeWidget ($cp)
	{
		$c = '';

		$widgetId = 'RW-'.mt_rand(1000000, 999999999);

		$class = 'e10-remote-widget';
		if (isset($cp['class']))
			$class .= ' '.$cp['class'];

		$params = '';
		if (isset($cp['params'])) {
			$pv = [];
			forEach ($cp['params'] as $paramId => $paramValue) {
				$pv [$paramId] = $paramId . '=' . $paramValue;
			}
			$params = " data-widget-params='" . implode('&', $pv) . "'";
		}

		$c .= "<div class='{$class}' data-widget-class='{$cp['id']}' id='$widgetId'{$params}>";
		$c .= '...';
		$c .= '</div>';

		return $c;
	}

	protected function createCodeMap ($cp)
	{
		$mapId = 'map-canvas-'.time().'_'.mt_rand(100000, 9999999);
		$c = '';

		$c .= "<div id='$mapId'";

		if (isset($cp['mapDefId']))
			$c .= " data-map-def-id='{$cp['mapDefId']}'";

		$c .= " style='height: 100%; border-radius: 4px;'></div>";

		//$c .= "<div id='map-canvas-loading' style='position: absolute; top: 50%; left: 10%; width: 50%; padding: 4px; border-radius: 4px; text-align: center; background-color: #5bc0de; border: 1px solid #333;'>";
		$c .= "<div id='{$mapId}-loading' style='position: absolute; top: 50%; left: 20%; width: 60%; padding: 4px; border-radius: 4px; text-align: center; background-color: #5bc0de; border: 1px solid #333;'>";
		$c .= 'mapa se načítá, čekejte prosím...';
		$c .= '</div>';

		$c .= "<script>$(function () {initGMap('{$mapId}')});</script>";

		return $c;
	}

	protected function createCodeTabs ($cp)
	{
		$activeTab = isset($cp['selectedTab']) ? intval ($cp['selectedTab']) : 0;

		if ($this->app()->printMode)
		{
			$c = $this->createCodeBody($cp['tabs'][$activeTab]['content']);
			return $c;
		}

		$tid = 'TABS'.mt_rand(10000, 9999999);

		if ($this->app->mobileMode)
			$c = "<ul class='e10-widget-tabs' id='{$tid}'>";
		else
			$c = "<ul class='nav nav-pills pull-right' id='{$tid}'>";

		$active = '';
		$tn = 1;
		foreach ($cp['tabs'] as $tab)
		{
			if (($tn - 1) === $activeTab)
				$active = " class='active'";

			$params = '';
			if (isset ($cp['tabsId']) && $this->report !== FALSE)
				$params = " data-inputelement='e10-tm-viewerbuttonbox' data-inputname='{$cp['tabsId']}' data-inputvalue='$tn'";

			if ($this->app->mobileMode)
				$c .= "<li$active id='{$tid}-{$tn}'$params>".$this->app()->ui()->composeTextLine ($tab['title'])."</li>";
			else
				$c .= "<li$active id='{$tid}-{$tn}'$params><a href='#'>".$this->app()->ui()->composeTextLine ($tab['title'])."</a></li>";
			$active = '';
			$tn++;
		}
		$c .= '</ul>';

		$tn = 1;
		foreach ($cp['tabs'] as $tab)
		{
			$c .= "<div style='clear: both; padding-top: 1ex;' id='{$tid}-{$tn}-tc'>".$this->createCodeBody($tab['content']).'</div>';
			$tn++;
		}

		if (!$this->app->mobileMode)
		{
			$c .= "<script>initTabs('$tid');</script>";
			$c .= "<br style='clear: both;'/>";
		}
		return $c;
	}
}
