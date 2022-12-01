<?php

namespace helpdesk\core;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableTickets
 */
class TableTickets extends DbTable
{
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

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['subject']];

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

		$hdr ['info'][] = ['class' => 'info', 'value' => $subInfo];

		return $hdr;
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
}


/**
 * Class ViewDetailTicket
 */
class ViewDetailTicket extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('helpdesk.core.libs.dc.HelpdeskTicketCore');

		$this->addContent ([
			'type' => 'viewer', 'table' => 'helpdesk.core.ticketsComments',
			'viewer' => 'default', 'params' => ['ticketNdx' => $this->item['ndx']],
		]);
	}
}
