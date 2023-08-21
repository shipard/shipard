<?php

namespace Shipard\Viewer;
use \e10\ContentRenderer;
use \Shipard\Table\DbTable;
use \Shipard\Application\DataModel;
use \translation\dicts\e10\base\system\DictSystem;

use \e10\utils;
use \e10\uiutils;
use \e10\base\libs\UtilsBase;

class TableViewDetail
{
	/** @var \Shipard\Base\DbTable */
	var $table;
	var $item;
	var $ok = 0;
	var $objectData = [];
	var $detailId;
	var $dataHeader;
	var $accessLevel = 0;
	var $content = [];
	var $header = NULL;
	var $viewDefinition = NULL;

	/** @return \E10\Application */
	public function app() {return $this->table->app();}
	public function db() {return $this->table->db();}
	public function table() {return $this->table;}

	public function __construct ($table, $viewId)
	{
		$this->table = $table;
		$this->viewDefinition = $this->table->viewDefinition ($viewId);
		$this->accessLevel = $this->table->app()->checkAccess (array ('object' => 'viewer', 'table' => $table->tableId(), 'viewer' => $viewId));
	}

	public function addContent ($contentPart)
	{
		if ($contentPart === FALSE)
			return;

		$this->content[] = $contentPart;
	}

	public function addContentAttachments ($toRecId, $tableId = FALSE, $title = FALSE, $downloadTitle = FALSE)
	{
		if ($tableId === FALSE)
			$tableId = $this->table->tableId();

		if ($title === FALSE)
			$title = ['icon' => 'system/formAttachments', 'text' => 'Přílohy'];
		if ($downloadTitle === FALSE)
			$downloadTitle = ['icon' => 'system/actionDownload', 'text' => 'Soubory ke stažení'];

		$files = UtilsBase::loadAttachments ($this->table->app(), array($toRecId), $tableId);
		if (isset($files[$toRecId]))
			$this->content[] = array ('type' => 'attachments', 'attachments' => $files[$toRecId], 'title' => $title, 'downloadTitle' => $downloadTitle);
	}

	public function addContentViewer ($tableId, $viewerId, $params)
	{
		$this->content [] = array ('type' => 'viewer', 'table' => $tableId, 'viewer' => $viewerId, 'params' => $params);
	}

	public function addDocumentCard ($cardClassId)
	{
		$card = $this->app()->createObject($cardClassId);
		$card->setDocument($this->table(), $this->item);
		$card->createContent();

		if (isset($card->content['body']))
		{
			foreach ($card->content['body'] as $cp)
				$this->addContent($cp);
		}
		if ($card->header)
			$this->header = $card->header;
	}

	public function checkDetailContent ()
	{
		// -- classic content
		forEach ($this->content as &$cp)
		{
			if ($cp === FALSE)
				continue;

			if (isset ($cp['table']) && isset ($cp['header']))
			{
				$newHeader = array ();
				forEach ($cp['header'] as $hkey => $htext)
					$newHeader[] = array ('col'=>$hkey, 'label'=>$htext);
				$cp['header'] = $newHeader;
			}
		}
	}

	public function createDetailCode ()
	{
		$cr = new ContentRenderer ($this->app());
		$cr->setViewerDetail($this);
		return $cr->createCode();
	}

	public function createDetailContent ()
	{
	}

	public function createHeaderCode ()
	{
		if ($this->header)
			return $this->defaultHedearCode ($this->header);
		$h = $this->table->createHeader ($this->item, DbTable::chmViewer);
		return $this->defaultHedearCode ($h);
	}

	public function createToolbar ()
	{
		$toolbar = array();
		$item = $this->item;
		$tableOptions = $this->app()->model()->tableProperty ($this, 'options');

		if ($this->accessLevel > 1)
		{
			if ($this->table->app()->testGetParam('embeddedViewer') === '1' || $tableOptions & DataModel::toDisableCopyRecords || $this->accessLevel === 20)
				$toolbar [] = ['type' => 'action', 'action' => 'editform', 'text' => DictSystem::text(DictSystem::diBtn_Open), 'data-table' => $this->tableId(), 'data-pk' => $item['ndx']];
			else
			$toolbar [] = [
				'type' => 'action', 'action' => 'editform', 'text' => DictSystem::text(DictSystem::diBtn_Open), 'data-table' => $this->tableId(), 'data-pk' => $item['ndx'],
				'subButtons' => [
					[
						'type' => 'action', 'action' => 'newform', 'icon' => 'system/actionCopy', 'title' => DictSystem::text(DictSystem::diBtn_Copy),
						'data-table' => $this->tableId(), 'data-copyfrom' => $item['ndx'], 'btnClass' => 'btn-primary'
					]
				]
			];

			$deleted = false;
			$trash = $this->table->app()->model()->tableProperty ($this, 'trash');
			if ($trash != FALSE)
			{
				$trashColumn = $trash ['column'];

				if (isset ($trash ['value']))
					$trashValue = $trash ['value'];
				else
					$trashValue = 1;

				if ($item [$trashColumn] == $trashValue)
					$deleted = true;
			}

			if ($trash != FALSE)
			{
				if ($deleted)
					$toolbar [] = ['type' => 'action', 'action' => 'undeleteform', 'text' => 'Vzít z koše zpět', 'data-table' => $this->tableId(), 'data-pk' => $item['ndx']];
				else
					$toolbar [] = ['type' => 'action', 'action' => 'deleteform', 'text' => 'Smazat', 'data-table' => $this->tableId(), 'data-pk' => $item['ndx']];
			}

			if (isset($this->viewDefinition['type']) && $this->viewDefinition['type'] === 'form')
			{
				$toolbar [] = ['type' => 'action', 'action' => 'deleteform', 'text' => '', 'data-table' => $this->tableId(), 'data-pk' => $item['ndx'], 'title' => 'Smazat řádek'];

				$toolbar [] = ['type' => 'action', 'action' => 'moveDown', 'text' => '', 'data-table' => $this->tableId(), 'data-pk' => $item['ndx'], 'title' => 'Posunout dolů'];
				$toolbar [] = ['type' => 'action', 'action' => 'moveUp', 'text' => '', 'data-table' => $this->tableId(), 'data-pk' => $item['ndx'], 'title' => 'Posunout nahoru'];
			}
		}

		$this->table->createPrintToolbar ($toolbar, $this->item);
		$this->table->createDocumentToolbar ($toolbar, $this->item);

		return $toolbar;
	} // createToolbar

	public function createToolbarCode ()
	{
		$c = '';
		$tlbr = $this->createToolbar ();
		foreach ($tlbr as $btn)
			$c .= $this->app()->ui()->actionCode($btn);
		return $c;
	}

	public function defaultHedearCode ($headerInfo, $xtitle='', $xinfo='')
	{
		if (is_string ($headerInfo))
		{
			$info = array ('icon' => $headerInfo, 'title' => $xtitle, 'info' => $xinfo);
			error_log ("#WARNING: old defaultHedearCode style used!");
		}
		else
			$info = $headerInfo;
		$this->dataHeader = $info;

		$class = ' ';
		$icon = 'e10-docstyle-off';
		$iconClass = '';
		$docState = $this->table->getDocumentState ($this->item);
		if ($docState)
		{
			$docStateClass = $this->table->getDocumentStateInfo ($docState ['states'], $this->item, 'styleClass');
			if ($docStateClass)
			{
				$iconClass = 'e10-docstyle-on';
				$class = ' '.$docStateClass;
				$class .= ' e10-ds-block';
				$stateIcon = $this->table->getDocumentStateInfo ($docState ['states'], $this->item, 'styleIcon');
				$stateText = \E10\es ($this->table->getDocumentStateInfo ($docState ['states'], $this->item, 'name'));
			}
		}
		$headerCode = "<div class='content-header$class'>";
		$headerCode .= "<table><tr>";

		if (isset ($info ['image']))
		{
			$headerCode .= "<td class='content-header-img-new' style='background-image: url({$info['image']});'>";
			$headerCode .= '</td>';
		}
		elseif (isset($info ['emoji']))
		{
			$headerCode .= "<td class='content-header-emoji $iconClass'><span>".utils::es($info ['emoji'])."</span></td>";
		}
		else
		{
			$iconClass = '';
			if (isset($headerInfo['!error']))
				$iconClass .= 'e10-error';
			$icon = $this->app()->ui()->icon($info ['icon'], $iconClass, 'span');
			$headerCode .= "<td class='content-header-icon-new'>$icon</td>";
		}

		// info
		$headerCode .= "<td class='content-header-info-new'>";
		if (isset ($headerInfo ['info']) && is_string($info ['info']))
		{ // old compatibility mode
			$headerCode .= "<span class='txt'>{$info ['info']}</span>";
			$headerCode .= "<h1>{$info ['title']}</h1>";
			if (isset ($stateText))
				$headerCode .= "<span class='docState'>".$this->app()->ui()->icon($stateIcon)." $stateText</span>";
		}
		else
		{
			if (isset ($headerInfo ['info']))
				forEach ($headerInfo ['info'] as $info)
				{
					$headerCode .= "<div class='{$info ['class']}'>";
					$headerCode .= $this->app()->ui()->composeTextLine ($info ['value']);
					$headerCode .= '</div>';
				}
		}
		$headerCode .= "</td>";

		// sum info
		if (is_array ($headerInfo) && isset ($headerInfo ['sum']))
		{
			$headerCode .= "<td class='sum-header-info'>";
			forEach ($headerInfo ['sum'] as $sum)
			{
				$headerCode .= "<div class='{$sum ['class']}'>";
				if (isset($sum ['prefix']))
					$headerCode .= "<span class='pre'>" . utils::es ($sum ['prefix']) . '</span>';
				if (isset ($sum ['value']) && $sum ['value'] !== '')
					$headerCode .= "<span class='val'>" . $this->app()->ui()->composeTextLine ($sum ['value'], '') . '</span>';
				if (isset($sum ['suffix']))
					$headerCode .= "<span class='suf'>" . utils::es ($sum ['suffix']) . '</span>';
				$headerCode .= '</div>';
			}
			$headerCode .= "</td>";
		}

		if (isset ($info ['image']))
		{
			$headerCode .= "<td class='content-header-img-new' style='background-image: url({$info['image']});'>";
			$headerCode .= '</td>';
		}


		// label
		$headerCode .= "<td class='content-header-btns'>";
		$headerCode .= "<button class='df2-action-trigger e10-close-detail' data-action='close-lv-detail'>&times;</button>";
		$headerCode .= "<br/>";
		$headerCode .= "<button class='df2-action-trigger e10-close-detail' style='font-size: 100%; position:relative; top: .75em;' data-action='print-lv-detail'>".$this->app()->ui()->icon('system/actionPrint')."</button>";
		$headerCode .= "</td>";

		$headerCode .= "</table>";
		$headerCode .= "</div>";

		return $headerCode;
	}

	public function doIt ()
	{
		$this->createDetailContent ();

		$this->objectData ['htmlContent'] = $this->createDetailCode ();
		$this->objectData ['htmlHeader'] = $this->createHeaderCode ();
		$this->objectData ['htmlButtons'] = $this->createToolbarCode ();
	}

	public function setViewer ($tableId, $viewerClass, $queryParams = NULL)
	{
		$c = '';
		$v = $this->table->app()->table ($tableId)->getTableView ($viewerClass, $queryParams);

		$v->renderViewerData ('');
		$c .= $v->createViewerCode ('', TRUE);

		$this->objectData ['htmlCodeToolbarViewer'] = $v->createToolbarCode ();
		$this->objectData ['detailViewerId'] = $v->vid;

		return $c;
	}

	public function tableId ()
	{
		return $this->table->tableId ();
	}

	public function runQuery ($sql, $args)
	{
		$args = func_get_args();
		$r = $this->table->dbmodel->db->query ($args);
		//error_log ($this->table->dbmodel->db->test ($args));
		$this->item = $r->fetch ();
		$this->ok = 1;
	}
}

