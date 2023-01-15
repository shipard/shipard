<?php

namespace helpdesk\core;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \translation\dicts\e10\base\system\DictSystem;


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

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Komentář'];

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

		if ($recData['docStateMain'] === 2)
		{
			/** @var \helpdesk\core\TableTickets */
			$tableTickets = $this->app()->table('helpdesk.core.tickets');
			$ticketRecData = $tableTickets->loadItem($recData['ticket']);
			$tableTickets->doNotify($ticketRecData, 1, $recData);
		}

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

	var $notifyPks = [];
	var $othersNotifications = [];

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

	function renderPane (&$item)
	{
		$item ['pk'] = $item ['ndx'];

		$ndx = $item['ndx'];

		$paneClass = 'e10-pane-core ';
		$titleClass = '';

		if ($item['systemComment'])
		{
			$paneClass .= ' e10-pane-mini e10-pane-table e10-row-info';
			$titleClass = 'lh16';
		}
		else
			$paneClass .= 'e10-pane e10-pane-row e10-ds '.$item ['docStateClass'];

		if (in_array($item['ndx'], $this->notifyPks))
			$paneClass .= ' e10-block-notification';

    $item ['pane'] = ['class' => $paneClass, 'title' => [], 'body' => []];

		$title = [];

    $title[] = ['text' => $item['authorName'], 'icon' => 'system/iconUser', 'class' => 'label label-default'];
    $title[] = ['text' => Utils::datef($item['dateCreate'], '%S%t'), 'icon' => 'system/iconClock', 'class' => 'label label-default'];

		if (!$item['systemComment'])
		{
			$title[] = [
				'text' => '', 'icon' => 'system/actionOpen',
				'pk' => $ndx, 'docAction' => 'edit', 'data-table' => 'helpdesk.core.ticketsComments',
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
				'class' => 'pull-right'
			];
		}

		if (isset($this->othersNotifications[$ndx]))
		{
			$title[] = ['text' => 'Přečetli:', 'class' => ''];
			foreach ($this->othersNotifications[$ndx] as $urn)
			{
				if ($urn['state'])
					$title[] = ['text' => $urn['personName'], 'class' => 'label label-default lh16', 'icon' => 'system/iconCheck'];
				else
					$title[] = ['text' => $urn['personName'], 'class' => 'label label-info lh16', 'icon' => 'user/ban'];
			}
		}

		if (in_array($item['ndx'], $this->notifyPks))
		{
			$readButton = [
				'text' => DictSystem::text(DictSystem::diBtn_Seen),

				'action' => 'viewer-inline-action',
				'class' => 'pull-right',

				'icon' => 'user/eye',
				'btnClass' => 'btn-xs',
				'actionClass' => 'df2-action-trigger',
				'data-object-class-id' => 'helpdesk.core.libs.TicketsBulkAction',
				'data-action-type' => 'markCommentAsRead',
				'data-action-param-comment-ndx' => $item['ndx'],
				'data-action-param-ticket-ndx' => $item['ticket'],
			];
			$title[] = $readButton;
		}

		$item ['pane']['title'][] = [
			'class' => $titleClass,
			'value' => $title,
		];

		if ($item['systemComment'])
		{
			$data = Json::decode($item['text']);
			$dest = '';
			$this->renderSystemComment($data, $dest);
			$item ['pane']['body'][] = [
				'type' => 'text', 'subtype' => 'rawhtml', 'class' => 'mt1 e10-fs1r___ pageText',
				'text' => $dest,
			];
		}
		else
		{
			$this->textRenderer->renderAsArticle ($item ['text'], $this->table);
			$item ['pane']['body'][] = [
				'type' => 'text', 'subtype' => 'rawhtml', 'class' => 'padd5 __bt1 e10-fs1r pageText',
				'text' => $this->textRenderer->code,
			];
		}
  }

	public function renderSystemComment($data, &$dest)
	{
		$c = '';
		if (isset($data['changes']['changedColumns']))
		{
			if (isset($data['changes']['changedColumns']['ticketState']))
			{
				$old = $this->app()->cfgItem('helpdesk.ticketStates.'.$data['changes']['changedColumns']['ticketState']['valueFrom']);
				$new = $this->app()->cfgItem('helpdesk.ticketStates.'.$data['changes']['changedColumns']['ticketState']['valueTo']);
				$c .= '- Změna stavu požadavku z **'.($old['sn'] ?? '---') .'**'.' na **'.($new['sn'] ?? '').'**'."\n";
			}
			if (isset($data['changes']['changedColumns']['priority']))
			{
				$old = $this->app()->cfgItem('helpdesk.ticketPriorities.'.$data['changes']['changedColumns']['priority']['valueFrom']);
				$new = $this->app()->cfgItem('helpdesk.ticketPriorities.'.$data['changes']['changedColumns']['priority']['valueTo']);
				$c .= '- Změna priority z **'.$old['sn'].'**'.' na **'.$new['sn'].'**'."\n";
			}

			if (isset($data['changes']['changedColumns']['proposedPrice']))
			{
				if (!$data['changes']['changedColumns']['proposedPrice']['valueFrom'])
				{
					$c .= '- Navrhovaná cena nastavena na **'.Utils::nf($data['changes']['changedColumns']['proposedPrice']['valueTo'], 0).'**'."\n";
				}
				else
				{
					$c .= '- Změna navrhované ceny z **'.
					Utils::nf($data['changes']['changedColumns']['proposedPrice']['valueFrom'], 0).'**'.
					' na '.
					'**'.Utils::nf($data['changes']['changedColumns']['proposedPrice']['valueTo'], 0).'**'.
					"\n";
				}
			}

			if (isset($data['changes']['changedColumns']['estimatedManHours']))
			{
				if (!$data['changes']['changedColumns']['estimatedManHours']['valueFrom'])
				{
					$c .= '- Odhadovaná časová náročnost nastavena na **'.Utils::nf($data['changes']['changedColumns']['estimatedManHours']['valueTo'], 0).'** hodin'."\n";
				}
				else
				{
					$c .= '- Změna odhadované časové náročnosti z **'.
					Utils::nf($data['changes']['changedColumns']['estimatedManHours']['valueFrom'], 0).'**'.
					' na '.
					'**'.Utils::nf($data['changes']['changedColumns']['estimatedManHours']['valueTo'], 0).'** hodin'.
					"\n";
				}
			}

			if (isset($data['changes']['changedColumns']['proposedDeadline']))
			{
				if (Utils::dateIsBlank($data['changes']['changedColumns']['proposedDeadline']['valueFrom']))
				{
					$c .= '- Navrhovaný termín nastaven na **'.Utils::datef($data['changes']['changedColumns']['proposedDeadline']['valueTo']).'**'."\n";
				}
				elseif (Utils::dateIsBlank($data['changes']['changedColumns']['proposedDeadline']['valueTo']))
				{
					$c .= '- Navrhovaný termín **'.
					Utils::datef($data['changes']['changedColumns']['proposedDeadline']['valueFrom']).'**'.
					' byl zrušen'.
					"\n";
				}
				else
				{
					$c .= '- Změna termínu: **'.
					Utils::datef($data['changes']['changedColumns']['proposedDeadline']['valueFrom']).'**'.
					' --> '.
					'**'.Utils::datef($data['changes']['changedColumns']['proposedDeadline']['valueTo']).'**'.
					"\n";
				}
			}

			if (isset($data['changes']['labelRemove']))
			{
				$c .= '- Odstraněné štítky: ';
				$first = 1;
				foreach ($data['changes']['labelRemove'] as $ll)
				{
					if (!$first)
						$c .= ', ';
					$c .= '**'.$ll['title'].'**';

					$first = 0;
				}

				$c .= "\n";
			}
			if (isset($data['changes']['labelAdd']))
			{
				$c .= '- Přidané štítky: ';
				$first = 1;
				foreach ($data['changes']['labelAdd'] as $ll)
				{
					if (!$first)
						$c .= ', ';
					$c .= '**'.$ll['title'].'**';

					$first = 0;
				}

				$c .= "\n";
			}

			/*
			if (isset($data['changes']['changedColumns']['docState']))
			{
				$oldDocState = $this->app()->cfgItem('helpdesk.tickets.docStates.'.$data['changes']['changedColumns']['docState']['valueFrom']);
				$newDocState = $this->app()->cfgItem('helpdesk.tickets.docStates.'.$data['changes']['changedColumns']['docState']['valueTo']);
				$c .= '- Změna stavu z **'.$oldDocState['stateName'].'**'.' na **'.$newDocState['stateName'].'**'."\n";
			}*/
		}

		$this->textRenderer->renderAsArticle ($c, $this->table);
		$dest .= $this->textRenderer->code;
		//$dest .= Json::encode($data);
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

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->loadNotifications();
		$this->loadOthersNotifications();
		//$this->atts = \E10\Base\loadAttachments ($this->app(), $this->pks, 'wkf.core.comments');
	}

	public function zeroRowCode ()
	{
		$c = '';

		if ($this->ticketRecData ['text'] !== '')
		{
			$c .= "<div class='e10-pane e10-pane-info e10-pane-table pageText'>";
			$this->textRenderer->renderAsArticle ($this->ticketRecData ['text'], $this->table);
			$c .= $this->textRenderer->code;
			$c .= '</div>';
		}

    $addButtons = [];

    $addButtons[] = ['text' => 'Komentáře', 'icon' => 'tables/wkf.core.comments', 'class' => 'h2'];

    $addParams = '__ticket='.$this->queryParam('ticketNdx');
    $addButtons[] = [
      'action' => 'new', 'data-table' => 'helpdesk.core.ticketsComments', 'icon' => 'system/actionAdd',
      'text' => 'Nový komentář', 'type' => 'button', 'actionClass' => 'btn btn-xs',
      'class' => 'pull-right', 'btnClass' => 'btn-success',
      'data-addParams' => $addParams,
			'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
    ];

    $c .= "<div class='e10-pane-core e10-pane-info padd5 e10-bg-t6 pt1 pb1 mb1'>";
    $c .= $this->app()->ui()->composeTextLine($addButtons);
    $c .= '</div>';

		return $c;
	}

	protected function loadNotifications ()
	{
		$q[] = 'SELECT * FROM e10_base_notifications WHERE state = 0  ';
		array_push ($q, 'AND personDest = %i', $this->app()->userNdx());
		array_push ($q, 'AND tableId = %s', 'helpdesk.core.tickets');
		array_push ($q, 'AND recIdMain = %i', $this->ticketNdx);
		array_push ($q, 'AND recId IN %in', $this->pks);
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
			$this->notifyPks[] = $r['recId'];
	}

	protected function loadOthersNotifications ()
	{
		$q = [];

		array_push ($q, 'SELECT ntfs.*, [persons].[fullName] AS [personName]');
		array_push ($q, ' FROM [e10_base_notifications] AS [ntfs]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [ntfs].[personDest] = [persons].[ndx]');
		array_push ($q, ' WHERE 1');
		array_push ($q, 'AND tableId = %s', 'helpdesk.core.tickets');
		array_push ($q, 'AND recIdMain = %i', $this->ticketNdx);
		array_push ($q, 'AND recId IN %in', $this->pks);
		$rows = $this->db()->query ($q);

		foreach ($rows as $r)
		{
			$this->othersNotifications[$r['recId']][] = [
				'personNdx' => $r['personDest'],
				'personName' => $r['personName'],
				'state' => $r['state'],
			];
		}
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
