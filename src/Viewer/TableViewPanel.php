<?php

namespace Shipard\Viewer;
use \e10\ContentRenderer;


class TableViewPanel
{
	/** @var  \e10\DbTable */
	var $table;
	var $objectData;
	var $viewer;
	var $panelId;
	var $activeMainItem = '';
	var $content = array();

	public function __construct ($viewer, $panelId)
	{
		$this->viewer = $viewer;
		$this->table = $viewer->table;
		$this->panelId = $panelId;
	}

	public function addContent ($contentPart)
	{
		if ($contentPart === FALSE)
			return;

		$this->content[] = $contentPart;
	}

	public function addContentViewer ($tableId, $viewerId, $params)
	{
		$this->content [] = array ('type' => 'viewer', 'table' => $tableId, 'viewer' => $viewerId, 'params' => $params);
	}

	public function createCode ()
	{
		$cr = new ContentRenderer ($this->table->app());
		$cr->setViewerPanel($this);
		return $cr->createCode();
	}

	public function createContent ()
	{
		$this->viewer->createPanelContent ($this);
	}

	public function doIt ()
	{
		$this->createContent ();
		$this->objectData ['htmlContent'] = $this->createCode ();
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

	function setContent ($classId)
	{
		$o = $this->table->app()->createObject($classId);
		$o->create();
		foreach ($o->content['body'] as $cp)
			$this->addContent($cp);
	}
}

