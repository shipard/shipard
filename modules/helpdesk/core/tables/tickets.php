<?php

namespace helpdesk\core;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;


/**
 * class TableTickets
 */
class TableTickets extends DbTable
{
	CONST ewlNone = 0, ewlHours = 1, ewlDays = 2;

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

		if (!isset($recData ['dateTouch']) || utils::dateIsBlank($recData ['dateTouch']) || !isset($recData['activateCnt']) || !$recData['activateCnt'])
			$recData ['dateTouch'] = new \DateTime();

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->userNdx();

		if ($this->recData['estimatedWorkLen'] === TableTickets::ewlNone)
		{
			$recData['estimatedManHours'] = 0;
			$recData['estimatedManDays'] = 0;
		}
		elseif ($this->recData['estimatedWorkLen'] === TableTickets::ewlHours)
		{
			$recData['estimatedManDays'] = intval($recData['estimatedManHours'] / 8) + 1;
		}
		elseif ($this->recData['estimatedWorkLen'] === TableTickets::ewlDays)
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
			$info [] = ['text' => Utils::nf($recData['estimatedManDays'], 0).' dnů', 'title' => 'Odhadovaná náročnost v hodinách', 'icon' => 'user/keyboard', 'class' => 'label label-warning'];
		}

		if ($recData['proposedPrice'] > 0.0)
			$info [] = ['text' => Utils::nf($recData['proposedPrice'], 0), 'icon' => 'system/iconMoney', 'class' => 'label label-primary'];

		if (!Utils::dateIsBlank($recData['proposedDeadline']))
		{
			$info [] = ['text' => Utils::datef($recData['proposedDeadline']), 'icon' => 'system/iconCheckSquare', 'title' => 'Navrhovaný termín řešení', 'class' => 'label label-info'];
		}
	}

	public function addSystemComment($ticketNdx, $data)
	{
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
