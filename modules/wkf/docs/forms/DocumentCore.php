<?php

namespace wkf\docs\forms;

use \e10\utils, \E10\TableForm;
use translation\dicts\e10\base\system\DictSystem;


/**
 * Class DocumentCore
 * @package wkf\docs\forms
 */
class DocumentCore extends TableForm
{
	CONST askYes = 1, askSettings = 0, askNone = 2;
	CONST askUsersMembers = 0, askUsersAdmins = 1;
	var $content = [];
	var $issueKind;

	/** @var \wkf\docs\TableFolders */
	var $tableFolders;
	var $userAccessLevel = 0;

	public function renderForm ()
	{
		$this->tableFolders = $this->app()->table ('wkf.docs.folders');
		$this->userAccessLevel = $this->tableFolders->userAccessToFolder($this->recData['folder']);

		if ($this->renderFormReadOnly ())
			return;

		$this->renderFormReadWrite();
	}

	function renderFormReadWrite ()
	{
		$this->documentKind = $this->app()->cfgItem('wkf.docs.kinds.'.$this->recData['documentKind']);
		$topFolder = $this->table->topFolder($this->recData['folder']);


		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Obsah', 'icon' => 'icon-pencil-square-o'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-sliders'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$bigTextMode = 0;
		//if ($askPersons !== self::askYes && $askDeadline !== self::askYes && $askDateIncoming !== self::askYes && $askWorkOrder !== self::askYes)
		//	$bigTextMode = 1;

		$this->openForm ();
			$this->addColumnInput ('title');
			$this->openTabs ($tabs);
				$this->openTab();
					$this->addList('doclinks', '', TableForm::loAddToFormLayout);

					$this->addColumnInput('validFrom');
					$this->addColumnInput('validTo');

					$this->addSubColumns('data');
					$this->addList('clsf', '', TableForm::loAddToFormLayout);
					$this->addTextInput2($bigTextMode);
				$this->closeTab();
				$this->openTab();
					$this->addColumnInput('documentKind');
					$this->addColumnInput('folder');
				$this->closeTab();
				$this->openTab(TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}

	function addTextInput2 ($bigTextMode)
	{
		if (1 /*$this->recData['source'] === TableIssues::msHuman*/)
		{
			//$this->addInputMemo('text', 'Text');
			if ($bigTextMode)
				$this->addInputMemo('text', NULL, TableForm::coFullSizeY);
			else
				$this->addColumnInput('text');
			return;
		}

		$this->content = [];

		$textRenderer = new \lib\core\texts\Renderer($this->app());
		$textRenderer->render($this->recData ['text']);
		$this->content [] = ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'pageText e10-pane-table e10-pane-vitem'];

		$this->addAttachments ($this->recData ['ndx']);

		$cr = new \E10\ContentRenderer ($this->app());
		$cr->setForm($this);

		$c = "<div class='e10-reportContent'>";
		$c .= $cr->createCode();
		$c .= '</div>';

		$this->appendElement($c);
	}

	public function renderFormReadOnly ()
	{
		if (!$this->readOnly)
			return FALSE;

		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('sidebarWidth', '0.45');
		$this->setFlag ('maximize', 1);

		$this->openForm (TableForm::ltNone);
			$this->addColumnInput ('title', TableForm::coHidden);
			$this->renderFormContent ();
		$this->closeForm ();

		return TRUE;
	}

	public function renderFormContent ()
	{
		$this->addDocumentCard('wkf.docs.dc.Document');
	}

	public function addContent2 ($contentPart)
	{
		if ($contentPart === FALSE)
			return;

		$this->content[] = $contentPart;
	}

	public function addAttachments ($toRecId, $tableId = FALSE, $title = FALSE, $downloadTitle = FALSE)
	{
		if ($tableId === FALSE)
			$tableId = $this->table->tableId();

		if ($title === FALSE)
			$title = ['icon' => 'icon-paperclip', 'text' => 'Přílohy'];
		if ($downloadTitle === FALSE)
			$downloadTitle = ['icon' => 'icon-download', 'text' => 'Soubory ke stažení'];

		$files = \E10\Base\loadAttachments ($this->table->app(), array($toRecId), $tableId);
		if (isset($files[$toRecId]))
			$this->content[] = ['type' => 'attachments', 'attachments' => $files[$toRecId], 'title' => $title, 'downloadTitle' => $downloadTitle];
	}

	public function addContentViewer ($tableId, $viewerId, $params)
	{
		$this->content [] = ['type' => 'viewer', 'table' => $tableId, 'viewer' => $viewerId, 'params' => $params];
	}

	public function createToolbar ()
	{
		if (!$this->readOnly)
			return parent::createToolbar();

		$b = [
			'type' => 'action', 'action' => 'saveform', 'text' => DictSystem::text(DictSystem::diBtn_Seen), 'docState' => '99000',
			'style' => 'stateSave', 'stateStyle' => 'done', 'icon' => 'icon-eye', 'buttonClass' => 'btn-default'
		];

		$toolbar [] = $b;

		$toolbar = array_merge ($toolbar, parent::createToolbar());
		return $toolbar;
	}
}
