<?php

namespace helpdesk\core;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Utils;



/**
 * Class TableTicketsComments
 */
class TableTicketsComments extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('helpdesk.core.ticketsComments', 'helpdesk_core_ticketsComments', 'Komentáře k požadavkům');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['dateCreate']) || self::dateIsBlank ($recData ['dateCreate']))
			$recData ['dateCreate'] = new \DateTime();

		if (!isset($recData ['dateTouch']) || utils::dateIsBlank($recData ['dateTouch']) || !isset($recData['activateCnt']) || !$recData['activateCnt'])
			$recData ['dateTouch'] = new \DateTime();

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->userNdx();

    /*
		if (isset($recData['inReplyToIssue']))
		{
			$issueRecData = $this->app()->loadItem($recData['inReplyToIssue'], 'wkf.core.issues');
			if ($issueRecData)
				$recData['text'] = $this->makeCitationText($issueRecData['text']);

			unset ($recData['inReplyToIssue']);
		}


		if (isset($recData['inReplyToComment']))
		{
			$commentRecData = $this->loadItem($recData['inReplyToComment']);
			if ($commentRecData)
				$recData['text'] = $this->makeCitationText($commentRecData['text']);

			unset ($recData['inReplyToComment']);
		}
    */
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => 'TEST'];

		return $hdr;
	}

	public function checkDocumentState (&$recData)
	{
		parent::checkDocumentState ($recData);

		// -- activating
		if (!isset ($recData['activateCnt']))
			$recData['activateCnt'] = 0;
		if ($recData['docStateMain'] >= 1)
			$recData['activateCnt']++;
	}

	public function docsLog ($ndx)
	{
		$recData = parent::docsLog($ndx);

    /*
		if ($recData['docStateMain'] === 2)
		{
			$tableIssues = $this->app()->table('wkf.core.issues');
			$issueRecData = $tableIssues->loadItem($recData['issue']);
			$tableIssues->doNotify($issueRecData, 1, $recData);
		}
    */

		return $recData;
	}

	function makeCitationText($src)
	{
		$c = '';

		$rows = preg_split("/\\r\\n|\\r|\\n/", $src);
		foreach ($rows as $r)
		{
			$c .= '> '.$r."\n";
		}

		return $c;
	}
}


/**
 * class ViewTicketsComments
 */
class ViewTicketsComments extends TableView
{
  var $ticketNdx = 0;
  var $ticketRecData = NULL;
  var \lib\core\texts\Renderer $textRenderer;

	public function init ()
	{
    $this->setPaneMode(1);

		parent::init();

    $this->ticketNdx = intval($this->queryParam('ticketNdx'));
    if ($this->ticketNdx)
      $this->ticketRecData = $this->app()->loadItem($this->ticketNdx, 'helpdesk.core.tickets');

    $this->textRenderer = new \lib\core\texts\Renderer($this->app());

		$this->objectSubType = TableView::vsMini;
    $this->enableDetailSearch = FALSE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{

		$listItem ['pk'] = $item ['ndx'];
    $this->setItemDocState($this->table, $item, $listItem);

		$ndx = $item['ndx'];

    $listItem ['pane'] = ['class' => 'e10-pane e10-pane-row', 'title' => [], 'body' => []];
    $listItem ['pane']['class'] .= ' e10-ds '.$listItem ['class'];
		$title = [];

    $title[] = ['text' => $item['authorName'].$listItem ['docStateClass'], 'icon' => 'system/iconUser', 'class' => 'label label-default'];
    $title[] = ['text' => Utils::datef($item['dateCreate'], '%S%t'), 'icon' => 'system/iconClock', 'class' => 'label label-default'];

		$titleClass = '';
		$listItem ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title,
      'pk' => $ndx, 'docAction' => 'edit', 'data-table' => 'helpdesk.core.ticketsComments'
		];

		$this->textRenderer->renderAsArticle ($item ['text'], $this->table);
		$listItem ['pane']['body'][] = [
			'type' => 'text', 'subtype' => 'rawhtml', 'class' => 'padd5 bt1 e10-fs1r pageText',
			'text' => $this->textRenderer->code,
		];


		return $listItem;
	}

	function renderPane (&$item)
	{
		$item ['pk'] = $item ['ndx'];
    //$this->setItemDocState($this->table, $item, $listItem);

		$ndx = $item['ndx'];

    $item ['pane'] = ['class' => 'e10-pane e10-pane-row', 'title' => [], 'body' => []];
    $item ['pane']['class'] .= ' e10-ds '.$item ['docStateClass'];
		$title = [];

    $title[] = ['text' => $item['authorName'].$item ['docStateClass'], 'icon' => 'system/iconUser', 'class' => 'label label-default'];
    $title[] = ['text' => Utils::datef($item['dateCreate'], '%S%t'), 'icon' => 'system/iconClock', 'class' => 'label label-default'];

		$titleClass = '';
		$item ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title,
      'pk' => $ndx, 'docAction' => 'edit', 'data-table' => 'helpdesk.core.ticketsComments'
		];

		$this->textRenderer->renderAsArticle ($item ['text'], $this->table);
		$item ['pane']['body'][] = [
			'type' => 'text', 'subtype' => 'rawhtml', 'class' => 'padd5 bt1 e10-fs1r pageText',
			'text' => $this->textRenderer->code,
		];


		//return $listItem;

  }

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [comments].*,');
    array_push ($q, ' [authors].fullName AS authorName');
    array_push ($q, ' FROM [helpdesk_core_ticketsComments] AS [comments]');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [authors] ON [comments].[author] = [authors].ndx');
		array_push ($q, ' WHERE 1');

    array_push ($q, ' AND ticket = %i', $this->ticketNdx);


		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[comments].', ['[ndx]']);
		$this->runQuery ($q);
	}

	public function zeroRowCode ()
	{
		$c = '';

		//$c .= "<div class='padd5 pageText'>";

      $c .= "<div class='e10-pane e10-pane-table pageText'>";
      $this->textRenderer->renderAsArticle ($this->ticketRecData ['text'], $this->table);
      $c .= $this->textRenderer->code;
      $c .= '</div>';
		//$c .= '</div>';



    $addButtons = [];

    $addButtons[] = ['text' => 'Komentáře', 'icon' => 'tables/wkf.core.comments', 'class' => 'h2'];

    $addParams = '__ticket='.$this->queryParam('ticketNdx');
    $addButtons[] = [
      'action' => 'new', 'data-table' => 'helpdesk.core.ticketsComments', 'icon' => 'system/actionAdd',
      'text' => 'Nový komentář', 'type' => 'button', 'actionClass' => 'btn btn-xs',
      'class' => 'pull-right', 'btnClass' => 'btn-success',
      'data-addParams' => $addParams,
    ];

    $c .= "<div class='bb1 bt1 padd5 e10-bg-t6 pt1 pb1'>";
    $c .= $this->app()->ui()->composeTextLine($addButtons);
    $c .= '</div>';

		return $c;
	}
}


/**
 * class FormTicketComment
 */
class FormTicketComment extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
