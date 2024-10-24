<?php

namespace wkf\base\libs\dc;

use \e10\utils, \e10\json, wkf\core\TableDocuments;
use \e10\base\libs\UtilsBase;

/**
 * class Document
 */
class Project extends \e10\DocumentCard
{
	//var $info = [];

	//var $linkedPersons;
	//var $connectedIssues;
	//var $connectedIssues2;

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody();
	}

	function loadData()
	{
		//$this->linkedPersons = UtilsBase::linkedPersons2 ($this->table->app(), $this->table, $this->recData['ndx'], 'label label-default');
	}

	public function createContentBody ()
	{
		$this->createContentProjectText();
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	function createContentProjectText()
	{
		if ($this->recData ['text'] !== '' && $this->recData ['text'] !== '0')
		{
			$textRenderer = new \lib\core\texts\Renderer($this->app());
			$textRenderer->render($this->recData ['text']);
			$this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'e10-pane e10-pane-table pageText']);
		}
	}
}
