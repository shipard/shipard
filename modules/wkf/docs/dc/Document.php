<?php

namespace wkf\docs\dc;

use \e10\utils, \e10\json, wkf\core\TableDocuments;
use \e10\base\libs\UtilsBase;

/**
 * Class Document
 * @package wkf\docs\dc
 */
class Document extends \e10\DocumentCard
{
	var $info = [];

	var $linkedPersons;
	var $connectedIssues;
	var $connectedIssues2;
	var $systemInfo = NULL;

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody();
		//$this->createHeader();
	}

	public function createHeader ()
	{
		/*
		$this->header = [];
		$this->header['icon'] = $this->table->tableIcon($this->recData);
		$this->header['info'][] = ['class' => 'title', 'value' => [['text' => $this->recData ['fullName']], ['text' => '#'.$this->recData ['id'], 'class' => 'pull-right id']]];
		if (count($this->ids))
			$this->header['info'][] = ['class' => 'info', 'value' => $this->ids];
		if (count($this->groups))
			$this->header['info'][] = ['class' => 'info', 'value' => $this->groups];

		$image = \E10\Base\getAttachmentDefaultImage ($this->app(), $this->table->tableId(), $this->recData ['ndx']);
		if (isset($image ['smallImage']))
			$this->header['image'] = $image ['smallImage'];

		*/
	}

	function loadData()
	{
		$this->linkedPersons = UtilsBase::linkedPersons2 ($this->table->app(), $this->table, $this->recData['ndx'], 'label label-default');
	}

	public function createContentBody ()
	{
		$this->createContentIssueProperties();
		$this->createContentIssueText();
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	function createContentIssueProperties()
	{
		$ndx = $this->recData['ndx'];

		$t = [];

		// -- linkedPersons
		if (isset ($this->linkedPersons [$ndx]))
		{
			forEach ($this->linkedPersons [$ndx] as $linkId => $linkContent)
			{

				$t [] = [
					'c1' => ['text' => $linkContent['name'], /*'icon' => $linkContent['icon'],*/ 'class' => 'nowrap'],
					'c2' => $linkContent['labels'],
				];
			}
		}

		if (count($t))
		{
			$h = ['c1' => 'c1', 'c2' => 'c2'];
			$this->addContent('body', [
				'pane' => 'e10-pane e10-pane-table e10-pane-top', 'type' => 'table', 'table' => $t, 'header' => $h,
				'params' => ['forceTableClass' => 'dcInfo dcInfoB fullWidth', 'hideHeader' => 1]
			]);
		}
	}

	function createContentIssueText()
	{
		if ($this->recData ['text'] !== '' && $this->recData ['text'] !== '0')
		{
			$textRenderer = new \lib\core\texts\Renderer($this->app());
			$textRenderer->render($this->recData ['text']);
			$this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'e10-pane e10-pane-table pageText']);
		}
	}
}
