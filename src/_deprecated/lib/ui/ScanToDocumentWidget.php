<?php

namespace lib\ui;


use \E10\uiutils, \E10\str, \E10\TableForm, \E10\DbTable, E10\Window;

/**
 * Class ScanToDocumentWidget
 * @package lib\ui
 */
class ScanToDocumentWidget extends \E10\widgetPane
{
	public function addContentAttachments ($toRecId, $tableId, $title = FALSE, $downloadTitle = FALSE)
	{
		if ($title === FALSE)
			$title = ['icon' => 'icon-paperclip', 'text' => 'Přílohy'];
		if ($downloadTitle === FALSE)
			$downloadTitle = ['icon' => 'system/actionDownload', 'text' => 'Soubory ke stažení'];

		$files = \E10\Base\loadAttachments ($this->app, [$toRecId], $tableId);
		if (isset($files[$toRecId]))
			$this->content[] = array ('type' => 'attachments', 'attachments' => $files[$toRecId], 'title' => $title, 'downloadTitle' => $downloadTitle);
	}

	public function createContent ()
	{
		$tableId = $this->app->testGetParam('table');
		$recId = intval($this->app->testGetParam('pk'));
		$this->addContentAttachments($recId, $tableId);
	}

	public function title () {return FALSE;}
}
