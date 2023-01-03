<?php

namespace helpdesk\core;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \e10\base\libs\UtilsBase;
use \translation\dicts\e10\base\system\DictSystem;

/**
 * class TableTickets
 */
class TableTickets extends DbTable
{
	CONST ewlNone = 0, ewlHours = 1, ewlDays = 2;
	CONST chiChangedColumns = 'changedColumns', chiLabelRemove = 'labelRemove', chiLabelAdd = 'labelAdd';

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('helpdesk.core.tickets', 'helpdesk_core_tickets', 'Požadavky');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset($recData['ticketId']) || $recData['ticketId'] == '')
		{
			$recData['ticketId'] = Utils::createToken(5, FALSE, TRUE);
		}

		if (!isset($recData ['dateTouch']) || Utils::dateIsBlank($recData ['dateTouch']) || !isset($recData['activateCnt']) || !$recData['activateCnt'])
			$recData ['dateTouch'] = new \DateTime();

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->userNdx();

		if ($recData['estimatedWorkLen'] === TableTickets::ewlNone)
		{
			$recData['estimatedManHours'] = 0;
			$recData['estimatedManDays'] = 0;
		}
		elseif ($recData['estimatedWorkLen'] === TableTickets::ewlHours)
		{
			$recData['estimatedManDays'] = intval($recData['estimatedManHours'] / 8) + 1;
		}
		elseif ($recData['estimatedWorkLen'] === TableTickets::ewlDays)
		{
			$recData['estimatedManHours'] = intval($recData['estimatedManDays'] * 8);
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if ((!isset($recData ['author']) || (!$recData ['author'])) && is_object($this->app()->user))
			$recData ['author'] = $this->app()->userNdx();

		if (!isset($recData['dateCreate']))
			$recData['dateCreate'] = Utils::now();
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

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$ndx = $recData['ndx'] ?? 0;

		$subject = ['text' => $recData ['subject']];
		$ticketPriority = $this->app()->cfgItem('helpdesk.ticketPriorities.'.$recData['priority'], NULL);
		if ($ticketPriority)
		{
			if (isset($ticketPriority['icon']))
				$subject ['icon'] = $ticketPriority['icon'];
		}
		$hdr ['info'][] = ['class' => 'title', 'value' => $subject];

		$subInfo = [];
		if (isset($recData['author']) && $recData['author'])
		{
			$authorRecData = $this->app()->loadItem($recData['author'], 'e10.persons.persons');
			if ($authorRecData)
				$subInfo [] = ['text' => $authorRecData['fullName'], 'icon' => 'system/iconUser', 'class' => 'label label-default'];
		}

		if (isset($recData['dateCreate']))
		{
			$subInfo [] = ['text' => Utils::datef($recData['dateCreate'], '%S%t'), 'icon' => 'system/iconClock', 'class' => 'label label-default'];
		}

		$responseInfo = [];
		$this->ticketStateInfo($recData, $responseInfo);

		$classification = UtilsBase::loadClassification ($this->app(), $this->tableId(), $ndx);
		if (isset ($classification [$ndx]))
		{
			forEach ($classification [$ndx] as $clsfGroup)
				$responseInfo = array_merge ($responseInfo, $clsfGroup);
		}

		$linkedPersons = UtilsBase::linkedPersons ($this->app(), $this, [$ndx]);
		if (isset ($linkedPersons [$ndx]['helpdesk-tickets-assigned']))
		{
			$responseInfo = array_merge ($responseInfo, $linkedPersons [$ndx]['helpdesk-tickets-assigned']);
		}
		if (isset ($linkedPersons [$ndx]['helpdesk-tickets-notify']))
		{
			$subInfo = array_merge ($subInfo, $linkedPersons [$ndx]['helpdesk-tickets-notify']);
		}

		if (count($subInfo))
			$hdr ['info'][] = ['class' => 'info lh16', 'value' => $subInfo];

		if (count($responseInfo))
			$hdr ['info'][] = ['class' => 'info lh16', 'value' => $responseInfo];

		// -- notifications
		if ($recData['docState'] !== 1000 && $recData['ndx'])
		{
			$q = [];

			array_push ($q, 'SELECT ntfs.*, [persons].[fullName] AS [personName]');
			array_push ($q, ' FROM [e10_base_notifications] AS [ntfs]');
			array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [ntfs].[personDest] = [persons].[ndx]');
			array_push ($q, ' WHERE 1');
			array_push ($q, 'AND tableId = %s', 'helpdesk.core.tickets');
			array_push ($q, 'AND recId = %i', $recData['ndx']);
			$rows = $this->db()->query ($q);

			$addTitle = 1;
			$ntfs = [];
			foreach ($rows as $r)
			{
				if ($addTitle)
					$ntfs[] = ['text' => 'Přečetli:', 'class' => 'lh16'];
				if ($r['state'])
					$ntfs[] = ['text' => $r['personName'], 'class' => 'label label-default lh16', 'icon' => 'system/iconCheck'];
				else
					$ntfs[] = ['text' => $r['personName'], 'class' => 'label label-info lh16', 'icon' => 'user/ban'];

				$addTitle = 0;
			}
			if (count($ntfs))
				$hdr ['info'][] = ['class' => 'info lh16', 'value' => $ntfs];
		}

		return $hdr;
	}

	public function ticketStateInfo($recData, &$info)
	{
		$ticketState = $this->app()->cfgItem('helpdesk.ticketStates.'.$recData['ticketState'], NULL);
		if ($ticketState)
		{
			$tsl = ['text' => $ticketState['sn'], 'icon' => $ticketState['icon'], 'class' => 'label label-default'];

			$css = '';
			if ($ticketState['colorbg'])
				$css .= 'background-color: '.$ticketState['colorbg'].';';
			if ($ticketState['colorfg'])
				$css .= 'color: '.$ticketState['colorfg'];
			if ($css !== '')
				$tsl['css'] = $css;
			$info [] = $tsl;
		}

		if ($recData['estimatedWorkLen'] === TableTickets::ewlHours)
		{
			$info [] = ['text' => Utils::nf($recData['estimatedManHours'], 0).' hodin', 'title' => 'Odhadovaná náročnost v hodinách', 'icon' => 'user/keyboard', 'class' => 'label label-warning'];
		}
		elseif ($recData['estimatedWorkLen'] === TableTickets::ewlDays)
		{
			$info [] = ['text' => Utils::nf($recData['estimatedManDays'], 0).' dnů', 'title' => 'Odhadovaná náročnost ve dnech', 'icon' => 'user/keyboard', 'class' => 'label label-warning'];
		}

		if ($recData['proposedPrice'] > 0.0)
			$info [] = ['text' => Utils::nf($recData['proposedPrice'], 0), 'icon' => 'system/iconMoney', 'class' => 'label label-primary'];

		if (!Utils::dateIsBlank($recData['proposedDeadline']))
		{
			$info [] = ['text' => Utils::datef($recData['proposedDeadline']), 'icon' => 'system/iconCheckSquare', 'title' => 'Navrhovaný termín řešení', 'class' => 'label label-info'];
		}
	}

	public function docsLog ($ndx)
	{
		$recData = parent::docsLog($ndx);
		if ($recData['activateCnt'] === 1)
		{
			$this->doNotify($recData, 0, NULL);
			//$this->addSystemComment($ndx, $recData);
		}
		else
			$this->addSystemComment($ndx, $recData);
		return $recData;
	}

	public function doNotify($docRecData, $reason = 0, $commentRecData = NULL)
	{
		$ain = new \helpdesk\core\libs\AddTicketNotification($this->app());
		$ain->setDocument($this, $docRecData, $reason, $commentRecData);
		$ain->run();
	}

	public function addSystemComment($ticketNdx, $recData)
	{
		if ($recData['docState'] === 8000)
			return; // edit is disable

		$q = [];
		array_push($q, 'SELECT * FROM [e10_base_docslog]');
		array_push($q, ' WHERE [tableid] = %s', $this->tableId());
		array_push($q, ' AND [recid] = %i', $ticketNdx);
		array_push($q, ' AND [eventType] = %i', 0);
		array_push($q, ' ORDER BY ndx DESC');
		array_push($q, ' LIMIT 2');

		$lastEvent = NULL;
		$prevEvent =
		$first = 1;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($first)
				$lastEvent = $r->toArray();
			else
				$prevEvent = $r->toArray();
			$first = 0;
		}

		if (!$lastEvent || !$prevEvent)
			return;

		$lastEventData = Json::decode($lastEvent['eventData']);
		$prevEventData = Json::decode($prevEvent['eventData']);

		$eventData = [
			'changes' => [],
			'prevEventNdx' => $prevEvent['ndx'], 'lastEventNdx' => $lastEvent['ndx'],
			'prevEvent' => $prevEventData, 'lastEvent' => $lastEventData,
		];

		$newComment = [
			'ticket' => $ticketNdx, 'systemComment' => 1,
			'author' => $this->app()->userNdx(),
			'dateCreate' => new \DateTime(), 'dateTouch' => new \DateTime(),
			'activateCnt' => 1,
			'docState' => 4000, 'docStateMain' => 2,
		];

		$this->checkChanges($prevEventData, $lastEventData, $eventData['changes']);

		if (!count($eventData['changes']))
			return;
		if (count($eventData['changes']) === 1 &&
				isset($eventData['changes']['changedColumns']['docState']) &&
				count($eventData['changes']['changedColumns']) === 1 &&
				$eventData['changes']['changedColumns']['docState']['valueTo'] === 4000)
			return;

		$newComment ['text'] = Json::lint($eventData);

		$this->db()->query('INSERT INTO [helpdesk_core_ticketsComments] ', $newComment);
		$newCommentNdx = intval ($this->db()->getInsertId ());
		$commentRecData = $this->app()->loadItem($newCommentNdx, 'helpdesk.core.ticketsComments');
		$this->doNotify($recData, 1, $commentRecData);
	}

	protected function checkChanges($prev, $last, &$changes)
	{
		// -- columns
		$ignoredCols = [
			'ndx', 'ticketId', 'dateCreate', 'dateTouch', 'activateCnt', 'docStateMain',
		];
		foreach ($last['recData'] as $colId => $colValue)
		{
			if (!isset($prev['recData'][$colId]))
				continue;
			if (in_array($colId, $ignoredCols))
				continue;
			if ($colValue != $prev['recData'][$colId])
			{
				$changes[self::chiChangedColumns][$colId] = ['valueFrom' => $prev['recData'][$colId], 'valueTo' => $colValue];
			}
		}

		// -- labels
		foreach ($prev['lists']['clsf']['helpdeskTopicsTags'] as $oldLabel)
		{ // removed
			$exist = Utils::searchArray($last['lists']['clsf']['helpdeskTopicsTags'], 'dstRecId', $oldLabel['dstRecId']);
			if ($exist)
				continue;
			$changes[self::chiLabelRemove][] = ['ndx' => $oldLabel['dstRecId'], 'title' => $oldLabel['title']];
		}
		foreach ($last['lists']['clsf']['helpdeskTopicsTags'] as $newLabel)
		{ // new
			$exist = Utils::searchArray($prev['lists']['clsf']['helpdeskTopicsTags'], 'dstRecId', $newLabel['dstRecId']);
			if ($exist)
				continue;
			$changes[self::chiLabelAdd][] = ['ndx' => $newLabel['dstRecId'], 'title' => $newLabel['title']];
		}
	}
}


/**
 * class FormTicket
 */
class FormTicket extends TableForm
{
	public function renderForm ()
	{
		if ($this->app()->hasRole('hstngha'))
			$this->renderForm_Admin();
		else
			$this->renderForm_User();
	}

	public function renderForm_Admin ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
		$tabs ['tabs'][] = ['text' => 'Reakce', 'icon' => 'user/reply'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->addColumnInput ('subject');
			$this->openTabs ($tabs);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('text', NULL, self::coFullSizeY);
				$this->closeTab ();

				$this->openTab ();
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					$this->addList ('doclinksPersons', '', TableForm::loAddToFormLayout);
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('priority');
					$this->addColumnInput ('ticketState');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('proposedPrice');
					$this->addColumnInput ('proposedDeadline');
					$this->addSeparator(self::coH4);
					$this->openRow();
						$this->addColumnInput ('estimatedWorkLen');
						if ($this->recData['estimatedWorkLen'] === TableTickets::ewlHours)
							$this->addColumnInput ('estimatedManHours');
						elseif ($this->recData['estimatedWorkLen'] === TableTickets::ewlDays)
							$this->addColumnInput ('estimatedManDays');
					$this->closeRow();
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('author');
					$this->addColumnInput ('helpdeskSection');
					$this->addColumnInput ('dataSource');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function renderForm_User ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->addColumnInput ('subject');
			$this->openTabs ($tabs);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('text', NULL, self::coFullSizeY);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'estimatedManHours': return '';
			case	'estimatedManDays': return '';
    }

    return parent::columnLabel ($colDef, $options);
  }

	public function createToolbar ()
	{
		if (!$this->readOnly && $this->recData['source'] == 0)
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


/**
 * Class ViewDetailTicket
 */
class ViewDetailTicket extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent ([
			'type' => 'viewer', 'table' => 'helpdesk.core.ticketsComments',
			'viewer' => 'default', 'params' => ['ticketNdx' => $this->item['ndx']],
		]);
	}
}
